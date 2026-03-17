<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Main dashboard login — email + password → app_dashboard.
 *
 * Routes:
 *   GET  /login        → serve login page
 *   POST /login/auth   → authenticate → redirect to app_dashboard
 *   POST /logout       → revoke session
 */
#[Route('')]
class LoginController extends AbstractController
{
    public function __construct(private readonly AuthService $auth) {}

    // =========================================================================
    // GET /login
    // =========================================================================

    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function loginPage(Request $request): Response
    {
        $token = $this->resolveToken($request);
        if ($token) {
            try {
                $session = $this->auth->validateSession($token);
                // Only redirect dashboard sessions — not POS sessions
                if ($session->deviceType === 'dashboard') {
                    return $this->redirectToRoute('app_dashboard');
                }
            } catch (AuthException) {
                // Token invalid or expired — show login
            }
        }

        return $this->render('auth/login.html.twig');
    }

    // =========================================================================
    // POST /login/auth
    // =========================================================================

    #[Route('/login/auth', name: 'app_login_auth', methods: ['POST'])]
    public function authenticate(Request $request): JsonResponse
    {
        $subdomain = $this->resolveSubdomain($request);
        $email     = trim((string) $request->request->get('email', ''));
        $password  = (string) $request->request->get('password', '');
        $remember  = (bool)   $request->request->get('remember', false);

        if ($email === '' || $password === '') {
            return $this->json(['success' => false, 'message' => 'Email and password are required.'], 400);
        }

        try {
            $result = $this->auth->loginDashboard(
                subdomain:          $subdomain,
                email:              $email,
                password:           $password,
                ipAddress:          $request->getClientIp() ?? '',
                userAgent:          $request->headers->get('User-Agent') ?? '',
                deviceName:         'Dashboard',
                terminalIdentifier: '',
            );

            $response = $this->json([
                'success'  => true,
                'redirect' => $this->generateUrl('app_dashboard'),
                'data'     => $result->toArray(),
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
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
    }

    // =========================================================================
    // POST /logout
    // =========================================================================

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

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('angavu_token') ?: null;
    }

    private function resolveSubdomain(Request $request): string
    {
        $host  = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) >= 3) {
            return $parts[0];
        }

        return $_ENV['DEFAULT_SUBDOMAIN'] ?? 'koma';
    }
}