<?php

declare(strict_types=1);

namespace App\Controller\Terminal;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Terminal\TerminalBranchAccessService;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles POS session locking and unlocking.
 */
#[Route('/{branch}/terminal/session', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class SessionController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DomainHelper $domains,
        private readonly Connection $db,
        private readonly TerminalBranchAccessService $terminalBranchAccess,
    ) {}

    #[Route('/lock', name: 'terminal_session_lock', methods: ['POST'])]
    public function lock(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if ($token) {
            $this->auth->logout($token);
        }

        $response = $this->json(['success' => true, 'message' => 'Session locked.']);
        $response->headers->clearCookie('patronr_pos_token', '/');
        $response->headers->clearCookie('patronr_pos_branch', '/');

        return $response;
    }

    #[Route('/unlock', name: 'terminal_session_unlock', methods: ['POST'])]
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

        $terminal = (string) $request->cookies->get('patronr_terminal', '');

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
            $companyId = (int) $this->db->fetchOne(
                'SELECT id FROM companies WHERE id <> 0 AND subdomain = :subdomain AND deleted_at IS NULL LIMIT 1',
                ['subdomain' => $subdomain],
            );
            $branchNode = $this->terminalBranchAccess->resolveBranchNode(
                $companyId,
                (string) $request->attributes->get('branch', ''),
            );
            if ($branchNode === null) {
                return $this->json(['success' => false, 'message' => 'Invalid branch terminal URL.'], 404);
            }

            $result = $this->auth->loginPos(
                subdomain: $subdomain,
                pin: $pin,
                terminalIdentifier: $terminal,
                ipAddress: $request->getClientIp() ?? '',
                userAgent: $request->headers->get('User-Agent') ?? '',
                deviceName: 'POS Terminal',
            );
            $result->branch = $branchNode;

            if (
                !$this->terminalBranchAccess->terminalMatchesBranch($result->company->id, $terminal, $branchNode->id)
                || !$this->terminalBranchAccess->userAssignedToBranch($result->user->id, $branchNode->id)
            ) {
                return $this->json([
                    'success' => false,
                    'message' => 'This POS login is only available for users assigned to this branch.',
                ], 403);
            }

            $response = $this->json([
                'success' => true,
                'data' => $result->toArray(),
            ]);

            $response->headers->setCookie(
                Cookie::create('patronr_pos_token')
                    ->withValue($result->token)
                    ->withExpires(0)
                    ->withPath('/')
                    ->withHttpOnly(true)
                    ->withSameSite('lax'),
            );

            // Branch slug cookie for POS — readable by JS (not httpOnly).
            // Look up the branch assigned to this terminal via the patronr_terminal device cookie.
            $posTerminalId = (string) $request->cookies->get('patronr_terminal', '');
            if ($posTerminalId !== '') {
                $branchSlug = $this->db->fetchOne(
                    'SELECT b.slug
                       FROM pos_terminals pt
                       JOIN branches b ON b.id = pt.branch_id
                      WHERE pt.terminal_identifier = :identifier
                        AND pt.revoked_at IS NULL
                        AND (pt.expires_at IS NULL OR pt.expires_at > NOW())
                      LIMIT 1',
                    ['identifier' => $posTerminalId],
                );

                if ($branchSlug) {
                    $response->headers->setCookie(
                        Cookie::create('patronr_pos_branch')
                            ->withValue((string) $branchSlug)
                            ->withExpires(0)
                            ->withPath('/')
                            ->withHttpOnly(false)
                            ->withSameSite('lax'),
                    );
                }
            }

            return $response;
        } catch (AuthException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
    }

    #[Route('/validate', name: 'terminal_session_validate', methods: ['GET'])]
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

        return $request->cookies->get('patronr_pos_token') ?: null;
    }

    private function resolveSubdomain(Request $request): ?string
    {
        // 2026-03-19: Use DomainHelper so configured apex domains never get
        // mistaken for tenant POS hosts.
        return $this->domains->getSubdomain($request);
    }
}
