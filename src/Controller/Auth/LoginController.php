<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
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
#[Route('', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin$)[A-Za-z0-9-]+', 'domain' => '.+'])]
class LoginController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Connection $db,
        private readonly DomainHelper $domains,
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
                    return $this->redirectToRoute('app_dashboard', [
                        'subdomain' => $subdomain,
                        'domain' => $baseDomain,
                    ]);
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

            $response = $this->json([
                'success' => true,
                'redirect' => $subdomain === null
                    ? $this->generateUrl('platform_dashboard', ['domain' => $baseDomain])
                    : $this->generateUrl('app_dashboard', ['subdomain' => $subdomain, 'domain' => $baseDomain]),
                'data' => $result->toArray(),
            ]);

            $cookieTtl = $remember ? 60 * 60 * 24 * 30 : 0;
            $response->headers->setCookie(
                Cookie::create('angavu_token')
                    ->withValue($result->token)
                    ->withExpires($cookieTtl > 0 ? time() + $cookieTtl : 0)
                    ->withPath('/')
                    ->withHttpOnly(true)
                    ->withSameSite('lax'),
            );

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
        $response->headers->clearCookie('angavu_token', '/');

        return $response;
    }

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('angavu_token') ?: null;
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
            'SELECT 1 FROM companies WHERE id <> 0 AND subdomain = :subdomain LIMIT 1',
            ['subdomain' => $subdomain],
        );
    }
}
