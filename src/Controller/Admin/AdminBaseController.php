<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\Exception\AuthException;
use App\Services\Branch\BranchResolverService;
use App\Services\Branch\Exception\BranchAccessDeniedException;
use App\Services\Branch\Exception\BranchInactiveException;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base controller for all admin panel controllers.
 *
 * Handles:
 *   - Validating angavu_token cookie
 *   - Rejecting POS sessions
 *   - Checking required permissions
 *   - Providing helpers for JSON responses
 *
 * Access denial behaviour:
 *   - No token / expired session (401) → redirect to login
 *   - Valid session, missing permission (403) → render in-layout 403 page
 *   - Fetch/AJAX requests (any) → JSON error
 */
abstract class AdminBaseController extends AbstractController
{
    public function __construct(
        protected readonly AuthService            $auth,
        protected readonly CheckPermissionService $can,
        protected readonly PlatformCheckPermissionService $platformCan,
        protected readonly BranchResolverService  $branchResolver,
        protected readonly Connection            $db,
    ) {}

    // =========================================================================
    // GUARD
    // =========================================================================

    /**
     * Require a valid dashboard session with the given permission.
     * Returns AuthResult on success.
     * Returns Response on failure — caller must return it immediately.
     *
     * @param string|null $permission  Pass null to only check for valid dashboard session
     */
    protected function requireAdmin(
        Request $request,
        ?string $permission = null,
    ): AuthResult|Response {
        // Tenant routes are never accessible from the platform admin host (admin.*)
        if (str_starts_with($request->getHost(), 'admin.')) {
            if ($request->headers->get('Accept') && str_contains($request->headers->get('Accept', ''), 'application/json')) {
                return new JsonResponse(['error' => 'Not found.'], 404);
            }
            $domain = substr($request->getHost(), strlen('admin.'));
            return $this->redirectToRoute('platform_dashboard', ['domain' => $domain]);
        }

        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->denyAccess($request, 'You must be logged in.');
        }

        try {
            $session = $this->auth->validateSession($token);
        } catch (AuthException $e) {
            return $this->denyAccess($request, $e->getMessage());
        }

        // Reject POS sessions
        if ($session->deviceType === 'pos') {
            return $this->denyAccess($request, 'POS sessions cannot access the admin panel.');
        }

        // Resolve branch from {branch} URL slug (present on all tenant admin routes).
        $branchSlug = (string) $request->attributes->get('branch', '');
        if ($branchSlug !== '' && !$session->user->isSuperAdmin) {
            // ── Regular tenant user ──────────────────────────────────────────
            try {
                $branchContext = $this->branchResolver->resolveFromRequest(
                    $request,
                    $session->user->id,
                    $session->company->id,
                    $session->company,
                );
                $session->branch            = $branchContext->branch;
                $session->availableBranches = $branchContext->availableBranches;
                $session->context           = $branchContext->context;
            } catch (BranchInactiveException $e) {
                $subdomain  = (string) $request->attributes->get('subdomain', '');
                $baseDomain = (string) $request->attributes->get('domain', '');
                return $this->redirectToRoute('app_branch_picker', [
                    'subdomain' => $subdomain,
                    'domain'    => $baseDomain,
                ]);
            } catch (BranchAccessDeniedException) {
                return $this->denyAccess($request, 'You do not have access to this branch.', 403, $session);
            } catch (\RuntimeException $e) {
                return $this->denyAccess($request, $e->getMessage(), 404);
            }
        } elseif ($branchSlug !== '' && $session->user->isSuperAdmin && $session->company !== null) {
            // ── Platform admin visiting a tenant branch route ────────────────
            // Bypass access validation — load all branches so the sidebar switcher
            // shows every branch, and resolve the requested context as normal.
            try {
                $branchContext = $this->branchResolver->resolveForPlatformAdmin(
                    $request,
                    $session->company->id,
                    $session->company,
                );
                $session->branch            = $branchContext->branch;
                $session->availableBranches = $branchContext->availableBranches;
                $session->context           = $branchContext->context;
            } catch (BranchInactiveException) {
                // Inactive branch — platform admin can still browse; just skip context
            } catch (\RuntimeException) {
                // Branch slug not found — non-fatal for platform admins
            }
        }

        // ── Multi-branch enforcement ─────────────────────────────────────────
        // When multi-branch is disabled:
        //   1. The only valid branch slug is 'head-office-branch' — redirect everything else.
        //   2. Users whose roles are exclusively multi-branch scopes (hq, region) are blocked.
        //      Only 'any' and 'branch' scoped roles are valid without multi-branch.
        if ($branchSlug !== '' && $session->company !== null) {
            $multiBranchOn = $this->isMultiBranchEnabled($session->company->id);

            if (!$multiBranchOn) {
                // Rule 1: redirect everyone (including platform admins) to head-office-branch.
                // /overall/ is a multi-branch concept and must not be accessible when disabled.
                if ($branchSlug !== BranchResolverService::HEAD_OFFICE_SLUG) {
                    $subdomain  = (string) $request->attributes->get('subdomain', '');
                    $baseDomain = (string) $request->attributes->get('domain', '');
                    $route      = $request->attributes->get('_route');
                    $params     = $request->attributes->get('_route_params', []);
                    $params['subdomain'] = $subdomain;
                    $params['domain']    = $baseDomain;
                    $params['branch']    = BranchResolverService::HEAD_OFFICE_SLUG;

                    return $this->redirectToRoute($route, $params);
                }

                // Rule 2: block tenant users with only multi-branch scoped roles (hq, region).
                // Platform admins are exempt — they have no user_node_roles entries.
                if ($session->user->isSuperAdmin) {
                    return $session; // allow through, already redirected to head-office-branch above
                }

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
                    ['uid' => $session->user->id],
                );

                if (!$hasSingleBranchRole) {
                    return $this->denyAccess(
                        $request,
                        'Your role (Overall Manager / Regional Manager) requires multi-branch to be enabled. Please contact your administrator.',
                        403,
                        $session,
                    );
                }
            }
        }

        // Platform admins must hold ACCESS_COMPANY_CONTEXT to enter any tenant admin page.
        // This is checked here (defense-in-depth) in addition to the login gate.
        // Platform owners always pass — isPlatformOwner() returns true unconditionally.
        if ($session->user->isSuperAdmin && $session->company !== null) {
            if (!$this->platformCan->check($session, 'access_company_context')) {
                return $this->denyAccess($request, 'You do not have permission to access this company dashboard.', 403, $session);
            }
        }

        // Check permission if required.
        // Platform admins (isSuperAdmin) bypass tenant permission checks entirely —
        // their access is governed by their platform-level permissions, not tenant roles.
        if ($permission !== null && !$session->user->isSuperAdmin && !$this->can->check($session, $permission)) {
            return $this->denyAccess($request, 'You do not have permission to access this page.', 403, $session);
        }

        // Validate user type access if needed
        if ($session->user !== null && !$session->user->isSuperAdmin) {
            $typeAccessResponse = $this->validateUserTypeAccess($session, $request);
            if ($typeAccessResponse instanceof Response) {
                return $typeAccessResponse;
            }
        }

        return $session;
    }

    /**
     * Require super admin access only.
     */
    protected function requireSuperAdmin(Request $request): AuthResult|Response
    {
        $session = $this->requireAdmin($request);

        if ($session instanceof Response) {
            return $session;
        }

        if (!$this->platformCan->isPlatformAdminSession($session)) {
            return $this->denyAccess($request, 'Super admin access required.', 403, $session);
        }

        return $session;
    }

    /**
     * Returns true if the multi-branch feature is both platform-released
     * and included in the company's active subscription.
     *
     * When false: only /head-office-branch/ is accessible; branch switcher,
     * overall context, and branch management pages are all hidden/blocked.
     */
    protected function isMultiBranchEnabled(int $companyId): bool
    {
        // Step 1: platform must have released the multi_branch module
        $platformReleased = (bool) $this->db->fetchOne(
            'SELECT platform_released FROM modules WHERE slug = :slug LIMIT 1',
            ['slug' => 'multi_branch'],
        );

        if (!$platformReleased) {
            return false;
        }

        // Step 2: company must have a feature from that module in their active plan
        // OR have a tenant_feature_override enabling it
        $inPlan = (bool) $this->db->fetchOne(
            'SELECT 1
               FROM company_subscriptions cs
               JOIN plan_features pf        ON pf.plan_id      = cs.plan_id
               JOIN module_features mf      ON mf.id           = pf.feature_id
               JOIN module_submodules ms    ON ms.id           = mf.submodule_id
               JOIN modules m              ON m.id            = ms.module_id
              WHERE cs.company_id = :cid
                AND m.slug        = :module
                AND cs.status    IN (\'trial\', \'active\')
                AND (cs.ends_at IS NULL OR cs.ends_at > NOW())
              LIMIT 1',
            ['cid' => $companyId, 'module' => 'multi_branch'],
        );

        if ($inPlan) {
            return true;
        }

        // Also check tenant_feature_overrides (explicit enable)
        $override = (bool) $this->db->fetchOne(
            'SELECT tfo.is_enabled
               FROM tenant_feature_overrides tfo
               JOIN module_features mf   ON mf.id  = tfo.feature_id
               JOIN module_submodules ms ON ms.id  = mf.submodule_id
               JOIN modules m           ON m.id   = ms.module_id
              WHERE tfo.company_id = :cid
                AND m.slug         = :module
                AND tfo.is_enabled = 1
                AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
              LIMIT 1',
            ['cid' => $companyId, 'module' => 'multi_branch'],
        );

        return $override;
    }

    /**
     * Validate user type access to current context
     * Returns null if access is allowed, or a Response denying access
     */
    protected function validateUserTypeAccess(AuthResult $session, Request $request): ?Response
    {
        // Platform admins bypass all user type checks
        if ($session->user->isSuperAdmin) {
            return null;
        }

        // TODO: Implement user type checks
        // - Office users (office/both) should only access office admin pages
        // - Branch users (branch/both) should only access branch admin pages
        // This requires linking user_type to the controller/route being accessed

        return null;
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    /**
     * Success JSON response for fetch actions.
     */
    protected function success(string $message, array $data = []): JsonResponse
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * Error JSON response for fetch actions.
     */
    protected function error(string $message, int $status = 400): JsonResponse
    {
        return $this->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Deny access with context-aware response:
     *
     *   - Fetch/AJAX request       → JSON error (any status)
     *   - 401 Unauthenticated      → redirect to login page
     *   - 403 Forbidden            → render in-layout 403 page (sidebar stays visible)
     */
    protected function denyAccess(Request $request, string $message, int $status = 401, ?object $session = null): Response
    {
        // Fetch/AJAX — always return JSON regardless of status
        if (
            $request->isXmlHttpRequest() ||
            str_contains($request->headers->get('Accept', ''), 'application/json') ||
            (
                $request->headers->get('Content-Type') === 'application/x-www-form-urlencoded'
                && $request->isMethod('POST')
            )
        ) {
            return $this->json(['success' => false, 'message' => $message], $status);
        }

        // Not logged in / session expired → send to login
        if ($status === 401) {
            return $this->redirectToRoute('app_login', [
                'subdomain' => (string) $request->attributes->get('subdomain', ''),
                'domain' => (string) $request->attributes->get('domain', ''),
            ]);
        }

        // Logged in but no permission → in-layout 403 page
        // Pass session so the template can display the logged-in user's name.
        return $this->render('errors/403.html.twig', [
            'message' => $message,
            'session' => $session,
        ], new Response('', Response::HTTP_FORBIDDEN));
    }

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('angavu_token') ?: null;
    }
}
