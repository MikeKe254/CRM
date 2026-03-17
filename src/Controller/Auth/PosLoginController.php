<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Permission\CheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles POS terminal authorization.
 * Completely separate from the main LoginController.
 *
 * Routes:
 *   GET  /mpesa/login                    → serve POS authorization page
 *   POST /mpesa/login/verify-pos-auth    → step 1: verify credentials + permission
 *   POST /mpesa/login/authorize-terminal → step 2: register terminal
 */
#[Route('/mpesa')]
class PosLoginController extends AbstractController
{
    /** Terminal authorization lifetime */
    private const TERMINAL_DAYS = 30;

    public function __construct(
        private readonly AuthService            $auth,
        private readonly CheckPermissionService $can,
        private readonly Connection             $db,
    ) {}

    // =========================================================================
    // GET /mpesa/login — Serve POS authorization page
    // =========================================================================

    #[Route('/login', name: 'mpesa_login_page', methods: ['GET'])]
    public function loginPage(Request $request): Response
    {
        // If terminal cookie exists and is valid → go straight to dashboard
        $terminal = $request->cookies->get('angavu_terminal', '');
        $subdomain = $this->resolveSubdomain($request);

        if ($terminal !== '') {
            // Check terminal is still valid in DB
            $company = $this->db->fetchAssociative(
                'SELECT id FROM companies WHERE subdomain = :subdomain LIMIT 1',
                ['subdomain' => $subdomain],
            );

            if ($company) {
                $valid = $this->db->fetchOne(
                    'SELECT id FROM pos_terminals
                     WHERE  company_id          = :company_id
                       AND  terminal_identifier = :identifier
                       AND  revoked_at IS NULL
                       AND  (expires_at IS NULL OR expires_at > NOW())
                     LIMIT 1',
                    ['company_id' => $company['id'], 'identifier' => $terminal],
                );

                if ($valid) {
                    return $this->redirectToRoute('mpesa_dashboard');
                }
            }
        }

        return $this->render('mpesa/pos_login.html.twig');
    }

    // =========================================================================
    // POST /mpesa/login/verify-pos-auth — Step 1: verify credentials + permission
    // =========================================================================

    #[Route('/login/verify-pos-auth', name: 'mpesa_login_verify_pos_auth', methods: ['POST'])]
    public function verifyPosAuth(Request $request): JsonResponse
    {
        $subdomain = $this->resolveSubdomain($request);
        $email     = trim((string) $request->request->get('email', ''));
        $password  = (string) $request->request->get('password', '');

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
                deviceName:         'POS Auth Verification',
                terminalIdentifier: '',
            );

            // Check permission
            if (!$this->can->check($result, 'authorize_pos_terminal')) {
                $this->auth->logout($result->token);
                return $this->json([
                    'success' => false,
                    'message' => 'This account does not have permission to authorize POS terminals.',
                ], 403);
            }

            // Store temp token in short-lived cookie for step 2
            $response = $this->json([
                'success' => true,
                'message' => 'Credentials verified.',
                'user'    => [
                    'name'  => $result->user->name,
                    'email' => $result->user->email,
                ],
            ]);

            $response->headers->setCookie(
                Cookie::create('angavu_pos_auth_token')
                    ->withValue($result->token)
                    ->withExpires(time() + 900) // 15 minutes
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
    // POST /mpesa/login/authorize-terminal — Step 2: register terminal
    // =========================================================================

    #[Route('/login/authorize-terminal', name: 'mpesa_login_authorize_terminal', methods: ['POST'])]
    public function authorizeTerminal(Request $request): JsonResponse
    {
        $token = $request->cookies->get('angavu_pos_auth_token');

        if (!$token) {
            return $this->json([
                'success' => false,
                'message' => 'Authorization session expired. Please start again.',
            ], 401);
        }

        try {
            $session = $this->auth->validateSession($token);
        } catch (AuthException) {
            return $this->json([
                'success' => false,
                'message' => 'Authorization session expired. Please start again.',
            ], 401);
        }

        if (!$this->can->check($session, 'authorize_pos_terminal')) {
            return $this->json(['success' => false, 'message' => 'Permission denied.'], 403);
        }

        $terminalIdentifier = trim((string) $request->request->get('terminal_identifier', ''));
        $deviceName         = trim((string) $request->request->get('device_name', ''));

        if ($terminalIdentifier === '') {
            return $this->json(['success' => false, 'message' => 'Terminal ID is required.'], 400);
        }

        if ($deviceName === '') {
            return $this->json(['success' => false, 'message' => 'Device name is required.'], 400);
        }

        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::TERMINAL_DAYS . ' days')
            ->format('Y-m-d H:i:s');

        // Upsert terminal
        $existing = $this->db->fetchAssociative(
            'SELECT id FROM pos_terminals
             WHERE company_id = :company_id AND terminal_identifier = :identifier LIMIT 1',
            ['company_id' => $session->company->id, 'identifier' => $terminalIdentifier],
        );

        if ($existing) {
            $this->db->executeStatement(
                'UPDATE pos_terminals
                 SET authorized_by_user_id = :user_id,
                     device_name           = :device_name,
                     ip_address            = :ip,
                     authorized_at         = NOW(),
                     expires_at            = :expires_at,
                     revoked_at            = NULL
                 WHERE id = :id',
                [
                    'user_id'     => $session->user->id,
                    'device_name' => $deviceName,
                    'ip'          => $request->getClientIp(),
                    'expires_at'  => $expiresAt,
                    'id'          => $existing['id'],
                ],
            );
        } else {
            $this->db->insert('pos_terminals', [
                'company_id'            => $session->company->id,
                'terminal_identifier'   => $terminalIdentifier,
                'authorized_by_user_id' => $session->user->id,
                'device_name'           => $deviceName,
                'ip_address'            => $request->getClientIp(),
                'authorized_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'expires_at'            => $expiresAt,
            ]);
        }

        // Revoke the temporary auth session — authorizer gets no dashboard access
        $this->auth->logout($token);

        // Set angavu_terminal cookie — used by SessionController for PIN unlock
        $response = $this->json([
            'success'  => true,
            'message'  => "Terminal \"{$deviceName}\" authorized until " .
                          (new \DateTimeImmutable())->modify('+' . self::TERMINAL_DAYS . ' days')->format('d M Y') . '.',
            'redirect' => $this->generateUrl('mpesa_dashboard'),
        ]);

        // Clear temp auth cookie
        $response->headers->clearCookie('angavu_pos_auth_token', '/');

        // Set permanent terminal cookie — HttpOnly, 30 days
        $response->headers->setCookie(
            Cookie::create('angavu_terminal')
                ->withValue($terminalIdentifier)
                ->withExpires(time() + 60 * 60 * 24 * self::TERMINAL_DAYS)
                ->withPath('/')
                ->withHttpOnly(true)
                ->withSameSite('lax'),
        );

        return $response;
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

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
