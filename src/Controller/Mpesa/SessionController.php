<?php

declare(strict_types=1);

namespace App\Controller\Mpesa;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles POS session locking and unlocking.
 * Replaces: Legacy/mpesa/ajax/lock_user.php
 *           Legacy/mpesa/ajax/unlock_user.php
 *
 * Routes:
 *   POST /mpesa/session/lock      → lock the current POS session (logout token)
 *   POST /mpesa/session/unlock    → PIN login on an authorized terminal
 *   GET  /mpesa/session/validate  → validate current token
 */
#[Route('/mpesa/session')]
class SessionController extends AbstractController
{
    public function __construct(private readonly AuthService $auth) {}

    // =========================================================================
    // POST /mpesa/session/lock
    // Ends the current POS staff session. Terminal stays authorized.
    // =========================================================================

    #[Route('/lock', name: 'mpesa_session_lock', methods: ['POST'])]
    public function lock(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if ($token) {
            $this->auth->logout($token);
        }

        $response = $this->json(['success' => true, 'message' => 'Session locked.']);
        $response->headers->clearCookie('angavu_token', '/');

        return $response;
    }

    // =========================================================================
    // POST /mpesa/session/unlock
    // PIN login on an authorized terminal — replaces hardcoded PIN unlock.
    // Body: { pin, terminal_identifier }
    // =========================================================================

    #[Route('/unlock', name: 'mpesa_session_unlock', methods: ['POST'])]
    public function unlock(Request $request): JsonResponse
    {
        $subdomain = $this->resolveSubdomain($request);
        $pin       = (string) $request->request->get('pin', '');

        // Terminal identifier is read server-side from HttpOnly cookie
        // set during dashboard login — never trusted from JS
        $terminal  = (string) $request->cookies->get('angavu_terminal', '');

        if ($pin === '') {
            return $this->json(['success' => false, 'message' => 'PIN is required.'], 400);
        }

        if ($terminal === '') {
            return $this->json(['success' => false, 'message' => 'This device is not authorized. Please log in on the login page first.'], 403);
        }

        try {
            $result = $this->auth->loginPos(
                subdomain:           $subdomain,
                pin:                 $pin,
                terminalIdentifier:  $terminal,
                ipAddress:           $request->getClientIp() ?? '',
                userAgent:           $request->headers->get('User-Agent') ?? '',
                deviceName:          'POS Terminal',
            );

            $response = $this->json([
                'success' => true,
                'data'    => $result->toArray(),
            ]);

            // Set POS session token in cookie
            $response->headers->setCookie(
                Cookie::create('angavu_token')
                    ->withValue($result->token)
                    ->withExpires(0) // session cookie — expires when browser closes
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
    // GET /mpesa/session/validate
    // Validate current token — useful for frontend auth checks on page load.
    // =========================================================================

    #[Route('/validate', name: 'mpesa_session_validate', methods: ['GET'])]
    public function validate(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->json(['success' => false, 'message' => 'No token provided.'], 401);
        }

        try {
            $result = $this->auth->validateSession($token);
            return $this->json(['success' => true, 'data' => $result->toArray()]);
        } catch (AuthException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
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