<?php

declare(strict_types=1);

namespace App\Controller\Mpesa;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Support\DomainHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles POS session locking and unlocking.
 */
#[Route('/mpesa/session', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+'])]
class SessionController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DomainHelper $domains,
    ) {}

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

    #[Route('/unlock', name: 'mpesa_session_unlock', methods: ['POST'])]
    public function unlock(Request $request): JsonResponse
    {
        $subdomain = $this->resolveSubdomain($request);
        $pin = (string) $request->request->get('pin', '');

        if ($subdomain === null) {
            return $this->json([
                'success' => false,
                'message' => 'Wrong URL. Please use the POS link provided for your company.',
            ], 400);
        }

        $terminal = (string) $request->cookies->get('angavu_terminal', '');

        if ($pin === '') {
            return $this->json(['success' => false, 'message' => 'PIN is required.'], 400);
        }

        if ($terminal === '') {
            return $this->json([
                'success' => false,
                'message' => 'This device is not authorized. Please log in on the login page first.',
            ], 403);
        }

        try {
            $result = $this->auth->loginPos(
                subdomain: $subdomain,
                pin: $pin,
                terminalIdentifier: $terminal,
                ipAddress: $request->getClientIp() ?? '',
                userAgent: $request->headers->get('User-Agent') ?? '',
                deviceName: 'POS Terminal',
            );

            $response = $this->json([
                'success' => true,
                'data' => $result->toArray(),
            ]);

            $response->headers->setCookie(
                Cookie::create('angavu_token')
                    ->withValue($result->token)
                    ->withExpires(0)
                    ->withPath('/')
                    ->withHttpOnly(true)
                    ->withSameSite('lax'),
            );

            return $response;
        } catch (AuthException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
    }

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
        // 2026-03-19: Use DomainHelper so configured apex domains never get
        // mistaken for tenant POS hosts.
        return $this->domains->getSubdomain($request);
    }
}
