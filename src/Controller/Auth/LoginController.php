<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Branch\BranchResolverService;
use App\Services\Branch\Exception\NoBranchAssignmentException;
use App\Services\Permission\PlatformCheckPermissionService;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Main dashboard login: email + password -> app_dashboard.
 *
 * Routes:
 *   GET  /login      -> serve login page
 *   POST /login/auth -> authenticate -> redirect to app_dashboard
 *   POST /logout     -> revoke session
 */
#[Route('', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+'])]
class LoginController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Connection $db,
        private readonly DomainHelper $domains,
        private readonly BranchResolverService $branchResolver,
        private readonly PlatformCheckPermissionService $platformCan,
    ) {}

    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function loginPage(Request $request, string $domain): Response
    {
        $token = $this->resolveToken($request);
        $subdomain = $this->resolveSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        // 2026-03-19: Generic tenant host routes can also match apex domains
        // like everify.co.ke. Redirect using the resolved base domain instead
        // of the raw route fragment so apex traffic never falls through to co.ke.
        if ($subdomain === null || !$this->tenantExists($subdomain)) {
            return $this->redirectToRoute('home', ['domain' => $baseDomain]);
        }

        if ($token) {
            try {
                $session = $this->auth->validateSession($token);

                if ($session->deviceType === 'dashboard') {
                    // Platform admins bypass user_node_roles — but they still need
                    // ACCESS_COMPANY_CONTEXT to enter a tenant dashboard.
                    if ($session->user->isSuperAdmin) {
                        if ($this->platformCan->check($session, 'access_company_context')) {
                            return $this->redirectToRoute('app_dashboard', [
                                'subdomain' => $subdomain,
                                'domain'    => $baseDomain,
                                'branch'    => BranchResolverService::OVERALL_SLUG,
                            ]);
                        }
                        // Has no tenant access — fall through to login page
                    }

                    // Already logged in — redirect to appropriate branch
                    try {
                        $result = $this->branchResolver->resolvePostLogin($session->user->id, $session->company->id);
                        if ($result->requiresPicker) {
                            return $this->redirectToRoute('app_branch_picker', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
                        }
                        return $this->redirectToRoute('app_dashboard', [
                            'subdomain' => $subdomain,
                            'domain'    => $baseDomain,
                            'branch'    => $result->directBranch->slug,
                        ]);
                    } catch (NoBranchAssignmentException) {
                        // No branches yet — fall through to login page so user can see an error
                    }
                }
            } catch (AuthException) {
                // Invalid or expired token: continue to the login page.
            }
        }

        return $this->render('auth/login.html.twig');
    }

    #[Route('/login/auth', name: 'app_login_auth', methods: ['POST'])]
    public function authenticate(Request $request, string $domain): JsonResponse
    {
        $subdomain = $this->resolveSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);
        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');
        $remember = (bool) $request->request->get('remember', false);

        if ($email === '' || $password === '') {
            return $this->json(['success' => false, 'message' => 'Email and password are required.'], 400);
        }

        try {
            if ($subdomain === null) {
                $result = $this->auth->loginSuperAdmin(
                    email: $email,
                    password: $password,
                    ipAddress: $request->getClientIp() ?? '',
                    userAgent: $request->headers->get('User-Agent') ?? '',
                    deviceName: 'Dashboard',
                );
            } else {
                $result = $this->auth->loginDashboard(
                    subdomain: $subdomain,
                    email: $email,
                    password: $password,
                    ipAddress: $request->getClientIp() ?? '',
                    userAgent: $request->headers->get('User-Agent') ?? '',
                    deviceName: 'Dashboard',
                    terminalIdentifier: '',
                );
            }

            // ── Multi-branch check (computed once, used twice below) ────────
            $multiBranchEnabled = false;
            if ($subdomain !== null && !$result->user->isSuperAdmin) {
                $platformReleased = (bool) $this->db->fetchOne(
                    'SELECT platform_released FROM modules WHERE slug = :slug LIMIT 1',
                    ['slug' => 'multi_branch'],
                );

                $multiBranchEnabled = $platformReleased && (bool) $this->db->fetchOne(
                    "SELECT 1
                       FROM company_subscriptions cs
                       JOIN plan_features pf     ON pf.plan_id    = cs.plan_id
                       JOIN module_features mf   ON mf.id         = pf.feature_id
                       JOIN module_submodules ms ON ms.id         = mf.submodule_id
                       JOIN modules m            ON m.id          = ms.module_id
                      WHERE cs.company_id = :cid
                        AND m.slug        = 'multi_branch'
                        AND cs.status    IN ('trial', 'active')
                        AND (cs.ends_at IS NULL OR cs.ends_at > NOW())
                      LIMIT 1",
                    ['cid' => $result->company->id],
                );

                // Block login for hq/region-only roles when multi-branch is off
                if (!$multiBranchEnabled) {
                    $hasSingleBranchRole = (bool) $this->db->fetchOne(
                        "SELECT 1
                           FROM user_node_roles unr
                           JOIN roles r ON r.id = unr.role_id
                          WHERE unr.user_id = :uid
                            AND (
                                r.scope IN ('any', 'branch')
                                OR r.name IN ('Owner', 'Director')
                            )
                            AND r.deleted_at IS NULL
                          LIMIT 1",
                        ['uid' => $result->user->id],
                    );

                    if (!$hasSingleBranchRole) {
                        return $this->json([
                            'success' => false,
                            'message' => 'Your role (Overall Manager / Regional Manager) is only available when multi-branch is enabled. Please contact your administrator.',
                        ], 403);
                    }
                }
            }

            // Resolve post-login redirect URL
            // $branchSlug is captured here so we can set the patronr_branch cookie.
            // Null means the destination doesn't have a fixed branch yet (picker, platform).
            $branchSlug = null;

            if ($subdomain === null) {
                $redirectUrl = $this->generateUrl('platform_dashboard', ['domain' => $baseDomain]);
            } else {
                if ($result->user->isSuperAdmin) {
                    // Platform admins bypass resolvePostLogin — but they still need
                    // ACCESS_COMPANY_CONTEXT to enter any tenant dashboard.
                    if (!$this->platformCan->check($result, 'access_company_context')) {
                        return $this->json([
                            'success' => false,
                            'message' => 'You do not have permission to access this company dashboard. Contact the platform owner.',
                        ], 403);
                    }

                    // Even platform admins land on head-office-branch when the company
                    // has multi-branch disabled — /overall/ is a multi-branch concept.
                    $superMultiBranch = $result->company !== null && $this->isTenantMultiBranchEnabled($result->company->id);
                    $branchSlug = $superMultiBranch
                        ? BranchResolverService::OVERALL_SLUG
                        : BranchResolverService::HEAD_OFFICE_SLUG;
                    $redirectUrl = $this->generateUrl('app_dashboard', [
                        'subdomain' => $subdomain,
                        'domain'    => $baseDomain,
                        'branch'    => $branchSlug,
                    ]);
                } else {
                    // When multi-branch is disabled, always land on head-office-branch —
                    // skip resolvePostLogin entirely (it would send Overall Managers to /overall/).
                    if (!$multiBranchEnabled) {
                        $branchSlug  = BranchResolverService::HEAD_OFFICE_SLUG;
                        $redirectUrl = $this->generateUrl('app_dashboard', [
                            'subdomain' => $subdomain,
                            'domain'    => $baseDomain,
                            'branch'    => $branchSlug,
                        ]);
                    } else {
                        try {
                            $pickerResult = $this->branchResolver->resolvePostLogin($result->user->id, $result->company->id);
                            if ($pickerResult->requiresPicker) {
                                // Branch picker will set the cookie after the user selects a branch.
                                $redirectUrl = $this->generateUrl('app_branch_picker', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
                            } else {
                                $branchSlug  = $pickerResult->directBranch->slug;
                                $redirectUrl = $this->generateUrl('app_dashboard', [
                                    'subdomain' => $subdomain,
                                    'domain'    => $baseDomain,
                                    'branch'    => $branchSlug,
                                ]);
                            }
                        } catch (NoBranchAssignmentException) {
                            $redirectUrl = $this->generateUrl('app_branch_picker', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
                        }
                    }
                }
            }

            $response = $this->json([
                'success' => true,
                'redirect' => $redirectUrl,
                'data' => $result->toArray(),
            ]);

            $cookieTtl = $remember ? 60 * 60 * 24 * 30 : 0;
            $response->headers->setCookie(
                Cookie::create('patronr_token')
                    ->withValue($result->token)
                    ->withExpires($cookieTtl > 0 ? time() + $cookieTtl : 0)
                    ->withPath('/')
                    ->withHttpOnly(true)
                    ->withSameSite('lax'),
            );

            // Branch slug cookie — readable by JS (not httpOnly).
            // Null when user needs the branch picker; the picker will set it after selection.
            if ($branchSlug !== null) {
                $response->headers->setCookie(
                    Cookie::create('patronr_branch')
                        ->withValue($branchSlug)
                        ->withExpires($cookieTtl > 0 ? time() + $cookieTtl : 0)
                        ->withPath('/')
                        ->withHttpOnly(false)
                        ->withSameSite('lax'),
                );
            }

            return $response;
        } catch (AuthException $e) {
            if ($subdomain === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'Wrong login details. Please make sure you use the URL and credentials assigned to your company.',
                ], 401);
            }

            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if ($token) {
            $this->auth->logout($token);
        }

        $response = $this->json(['success' => true, 'message' => 'Logged out.']);
        $response->headers->clearCookie('patronr_token', '/');
        $response->headers->clearCookie('patronr_branch', '/');

        return $response;
    }

    private function isTenantMultiBranchEnabled(int $companyId): bool
    {
        $platformReleased = (bool) $this->db->fetchOne(
            'SELECT platform_released FROM modules WHERE slug = :slug LIMIT 1',
            ['slug' => 'multi_branch'],
        );

        if (!$platformReleased) {
            return false;
        }

        return (bool) $this->db->fetchOne(
            "SELECT 1
               FROM company_subscriptions cs
               JOIN plan_features pf     ON pf.plan_id    = cs.plan_id
               JOIN module_features mf   ON mf.id         = pf.feature_id
               JOIN module_submodules ms ON ms.id         = mf.submodule_id
               JOIN modules m            ON m.id          = ms.module_id
              WHERE cs.company_id = :cid
                AND m.slug        = 'multi_branch'
                AND cs.status    IN ('trial', 'active')
                AND (cs.ends_at IS NULL OR cs.ends_at > NOW())
              LIMIT 1",
            ['cid' => $companyId],
        );
    }

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('patronr_token') ?: null;
    }

    private function resolveSubdomain(Request $request): ?string
    {
        // 2026-03-19: Always resolve subdomains through DomainHelper so
        // configured apex domains like everify.co.ke are never treated as
        // tenant hosts.
        $subdomain = $this->domains->getSubdomain($request);

        return $subdomain === 'admin' ? null : $subdomain;
    }

    private function tenantExists(string $subdomain): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM companies WHERE id <> 0 AND subdomain = :subdomain AND deleted_at IS NULL LIMIT 1',
            ['subdomain' => $subdomain],
        );
    }
}
