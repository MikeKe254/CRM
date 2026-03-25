<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\Exception\AuthException;
use App\Services\Branch\DTO\BranchNode;
use App\Services\Branch\BranchPermissionService;
use App\Services\Permission\CheckPermissionService;
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
 * Handles POS terminal authorization.
 */
#[Route('/mpesa', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+'])]
class PosLoginController extends AbstractController
{
    private const TERMINAL_DAYS = 30;

    public function __construct(
        private readonly AuthService $auth,
        private readonly CheckPermissionService $can,
        private readonly BranchPermissionService $branchPermissions,
        private readonly PlatformCheckPermissionService $platformCan,
        private readonly UserActivityLogService $activityLog,
        private readonly Connection $db,
        private readonly DomainHelper $domains,
    ) {}

    #[Route('/login', name: 'mpesa_login_page', methods: ['GET'])]
    public function loginPage(Request $request, string $domain): Response
    {
        $terminal = $request->cookies->get('angavu_terminal', '');
        $subdomain = $this->resolveSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        if ($subdomain === null) {
            return $this->render('mpesa/pos_login.html.twig', [
                'has_company_subdomain' => false,
                'host_error_message' => 'Wrong URL. Please use the POS setup link provided for your company.',
            ]);
        }

        if ($terminal !== '') {
            $company = $this->db->fetchAssociative(
                'SELECT id FROM companies WHERE id <> 0 AND subdomain = :subdomain AND deleted_at IS NULL LIMIT 1',
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
                    return $this->redirectToRoute('mpesa_dashboard', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
                }
            }
        }

        return $this->render('mpesa/pos_login.html.twig', [
            'has_company_subdomain' => true,
            'host_error_message' => null,
        ]);
    }

    #[Route('/login/verify-pos-auth', name: 'mpesa_login_verify_pos_auth', methods: ['POST'])]
    public function verifyPosAuth(Request $request): JsonResponse
    {
        $subdomain = $this->resolveSubdomain($request);

        if ($subdomain === null) {
            return $this->json([
                'success' => false,
                'message' => 'Wrong URL. Please use the POS setup link provided for your company.',
            ], 400);
        }

        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');

        if ($email === '' || $password === '') {
            return $this->json(['success' => false, 'message' => 'Email and password are required.'], 400);
        }

        try {
            $result = $this->auth->loginDashboard(
                subdomain: $subdomain,
                email: $email,
                password: $password,
                ipAddress: $request->getClientIp() ?? '',
                userAgent: $request->headers->get('User-Agent') ?? '',
                deviceName: 'POS Auth Verification',
                terminalIdentifier: '',
            );

            if (!$this->canAuthorizePosTerminal($result)) {
                $this->auth->logout($result->token);

                return $this->json([
                    'success' => false,
                    'message' => 'This account does not have permission to authorize POS terminals.',
                ], 403);
            }

            $response = $this->json([
                'success' => true,
                'message' => 'Credentials verified.',
                'user' => [
                    'name' => $result->user->name,
                    'email' => $result->user->email,
                ],
            ]);

            $response->headers->setCookie(
                Cookie::create('angavu_pos_auth_token')
                    ->withValue($result->token)
                    ->withExpires(time() + 900)
                    ->withPath('/')
                    ->withHttpOnly(true)
                    ->withSameSite('lax'),
            );

            return $response;
        } catch (AuthException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
    }

    #[Route('/login/authorize-terminal', name: 'mpesa_login_authorize_terminal', methods: ['POST'])]
    public function authorizeTerminal(Request $request, string $domain): JsonResponse
    {
        $token = $request->cookies->get('angavu_pos_auth_token');
        $baseDomain = $this->domains->getBaseDomain($request);

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

        if (!$this->canAuthorizePosTerminal($session)) {
            return $this->json(['success' => false, 'message' => 'Permission denied.'], 403);
        }

        $terminalIdentifier = trim((string) $request->request->get('terminal_identifier', ''));
        $deviceName = trim((string) $request->request->get('device_name', ''));

        if ($terminalIdentifier === '') {
            return $this->json(['success' => false, 'message' => 'Terminal ID is required.'], 400);
        }

        if ($deviceName === '') {
            return $this->json(['success' => false, 'message' => 'Device name is required.'], 400);
        }

        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::TERMINAL_DAYS . ' days')
            ->format('Y-m-d H:i:s');
        $branchId = $this->resolveTerminalBranchId($session);
        if ($branchId !== null) {
            $session->branch = $this->loadBranchNode($branchId);
        }
        $authorizedById = $session->user->isSuperAdmin
            ? -1 * $session->user->id
            : $session->user->id;

        $existing = $this->db->fetchAssociative(
            'SELECT id FROM pos_terminals
             WHERE company_id = :company_id AND terminal_identifier = :identifier LIMIT 1',
            ['company_id' => $session->company->id, 'identifier' => $terminalIdentifier],
        );

        $terminalRowId = null;

        if ($existing) {
            $this->db->executeStatement(
                'UPDATE pos_terminals
                 SET authorized_by_user_id = :user_id,
                     branch_id              = :branch_id,
                     device_name           = :device_name,
                     ip_address            = :ip,
                     authorized_at         = NOW(),
                     expires_at            = :expires_at,
                     revoked_at            = NULL
                 WHERE id = :id',
                [
                    'user_id' => $authorizedById,
                    'branch_id' => $branchId,
                    'device_name' => $deviceName,
                    'ip' => $request->getClientIp(),
                    'expires_at' => $expiresAt,
                    'id' => $existing['id'],
                ],
            );
            $terminalRowId = (int) $existing['id'];
        } else {
            $this->db->insert('pos_terminals', [
                'company_id' => $session->company->id,
                'branch_id' => $branchId,
                'terminal_identifier' => $terminalIdentifier,
                'authorized_by_user_id' => $authorizedById,
                'device_name' => $deviceName,
                'ip_address' => $request->getClientIp(),
                'authorized_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt,
            ]);
            $terminalRowId = (int) $this->db->lastInsertId();
        }

        $this->auth->logout($token);

        $this->activityLog->record(
            $session,
            'terminal.authorize',
            [
                'device' => $deviceName,
                'identifier' => $terminalIdentifier,
            ],
            permission: 'authorize_pos_terminal',
            subjectType: 'terminal',
            subjectId: $terminalRowId,
            request: $request,
        );

        $response = $this->json([
            'success' => true,
            'message' => "Terminal \"{$deviceName}\" authorized until "
                . (new \DateTimeImmutable())->modify('+' . self::TERMINAL_DAYS . ' days')->format('d M Y') . '.',
            'redirect' => $this->generateUrl('mpesa_dashboard', [
                'subdomain' => $session->company->subdomain,
                'domain' => $baseDomain,
            ]),
        ]);

        $response->headers->clearCookie('angavu_pos_auth_token', '/');
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

    private function resolveSubdomain(Request $request): ?string
    {
        // 2026-03-19: Route all subdomain parsing through DomainHelper so apex
        // hosts like everify.co.ke never enter the tenant POS flow by mistake.
        return $this->domains->getSubdomain($request);
    }

    private function canAuthorizePosTerminal(AuthResult $session): bool
    {
        if ($session->user->isSuperAdmin) {
            return $this->platformCan->check($session, 'authorize_pos_terminal');
        }

        $anchorNodeId = $session->branch?->id ?? $this->resolvePermissionAnchorNodeId($session);
        if ($anchorNodeId === null) {
            return false;
        }

        return $this->branchPermissions->check(
            $session->user->id,
            $anchorNodeId,
            'authorize_pos_terminal',
        );
    }

    private function resolvePermissionAnchorNodeId(AuthResult $session): ?int
    {
        $nodeId = $this->db->fetchOne(
            'SELECT unr.node_id
               FROM user_node_roles unr
               JOIN branches b ON b.id = unr.node_id
              WHERE unr.user_id = :user_id
                AND b.company_id = :company_id
                AND b.deleted_at IS NULL
              ORDER BY unr.is_primary DESC, unr.node_id ASC
              LIMIT 1',
            [
                'user_id' => $session->user->id,
                'company_id' => $session->company->id,
            ],
        );

        return $nodeId !== false ? (int) $nodeId : null;
    }

    private function resolveTerminalBranchId(AuthResult $session): ?int
    {
        if (!$this->isMultiBranchEnabled($session->company->id)) {
            $singleBranchId = $this->db->fetchOne(
                'SELECT id
                   FROM branches
                  WHERE company_id = :company_id
                    AND slug = :slug
                    AND deleted_at IS NULL
                  LIMIT 1',
                [
                    'company_id' => $session->company->id,
                    'slug' => 'head-office-branch',
                ],
            );

            if ($singleBranchId !== false) {
                return (int) $singleBranchId;
            }
        }

        return $session->branch?->id ?? $this->resolvePermissionAnchorNodeId($session);
    }

    private function isMultiBranchEnabled(int $companyId): bool
    {
        $platformReleased = (bool) $this->db->fetchOne(
            'SELECT platform_released FROM modules WHERE slug = :slug LIMIT 1',
            ['slug' => 'multi_branch'],
        );

        if (!$platformReleased) {
            return false;
        }

        $inPlan = (bool) $this->db->fetchOne(
            'SELECT 1
               FROM company_subscriptions cs
               JOIN plan_features pf        ON pf.plan_id      = cs.plan_id
               JOIN module_features mf      ON mf.id           = pf.feature_id
               JOIN module_submodules ms    ON ms.id           = mf.submodule_id
               JOIN modules m               ON m.id            = ms.module_id
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

        return (bool) $this->db->fetchOne(
            'SELECT tfo.is_enabled
               FROM tenant_feature_overrides tfo
               JOIN module_features mf   ON mf.id  = tfo.feature_id
               JOIN module_submodules ms ON ms.id  = mf.submodule_id
               JOIN modules m            ON m.id   = ms.module_id
              WHERE tfo.company_id = :cid
                AND m.slug         = :module
                AND tfo.is_enabled = 1
                AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
              LIMIT 1',
            ['cid' => $companyId, 'module' => 'multi_branch'],
        );
    }

    private function loadBranchNode(int $branchId): ?BranchNode
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, company_id, parent_id, name, slug, type, path, depth, is_hq, status
               FROM branches
              WHERE id = :id
                AND deleted_at IS NULL
              LIMIT 1',
            ['id' => $branchId],
        );

        return $row ? BranchNode::fromRow($row) : null;
    }
}
