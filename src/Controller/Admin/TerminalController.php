<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchHierarchyService;
use App\Services\Branch\BranchPermissionService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/terminals', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class TerminalController extends AdminBaseController
{
    public function __construct(
        AuthService            $auth,
        CheckPermissionService $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService  $branchResolver,
        Connection             $db,
        private readonly UserActivityLogService $activityLog,
        private readonly BranchPermissionService $branchPermissions,
        private readonly BranchHierarchyService $hierarchy,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // GET /dashboard/admin/terminals — List (Twig)
    // =========================================================================

    #[Route('', name: 'admin_terminals', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'authorize_pos_terminal');
        if ($session instanceof Response) return $session;
        if ($session->user->isSuperAdmin && !$this->platformCan->check($session, 'view_terminals')) {
            return $this->denyAccess($request, 'You do not have permission to view terminals.', 403, $session);
        }

        // Scope terminals to branches within the current context's subtree.
        // Platform admins use the branch hierarchy directly (no user_node_roles entries).
        // Regular users use their authority scope from BranchPermissionService.
        // When no branch context exists, fall back to all company terminals.
        if ($session->branch !== null) {
            if ($session->user->isSuperAdmin) {
                // Platform admins: subtree of current context node.
                $branchIds = $this->hierarchy->getSubtreeIds($session->branch->id);
                if (empty($branchIds)) {
                    $branchIds = [$session->branch->id];
                }
            } else {
                // Regular users: authority scope (respects management hierarchy).
                $scope     = $this->branchPermissions->getAuthorityScope($session->user->id, $session->branch->id);
                $branchIds = $scope->accessibleBranchIds;
                $branchIds = !empty($branchIds) ? $branchIds : [$session->branch->id];
            }

            $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
            $placeholders = implode(',', array_fill(0, count($branchIds), '?'));

            $terminals = $this->db->fetchAllAssociative(
                "SELECT pt.id, pt.terminal_identifier, pt.device_name,
                        pt.ip_address, pt.authorized_at, pt.expires_at, pt.revoked_at,
                        b.name AS branch_name,
                        COALESCE(u.name, pa.name, 'Platform Admin') AS authorized_by
                 FROM   pos_terminals pt
                 LEFT JOIN branches b ON b.id = pt.branch_id
                 LEFT JOIN users u
                        ON pt.authorized_by_user_id > 0
                       AND u.id = pt.authorized_by_user_id
                 LEFT JOIN platform_admins pa
                        ON pt.authorized_by_user_id < 0
                       AND pa.id = ABS(pt.authorized_by_user_id)
                 WHERE  pt.branch_id IN ({$placeholders})
                 ORDER  BY pt.authorized_at DESC",
                $branchIds,
            );
        } else {
            // No branch context — show all terminals for the company.
            $terminals = $this->db->fetchAllAssociative(
                "SELECT pt.id, pt.terminal_identifier, pt.device_name,
                        pt.ip_address, pt.authorized_at, pt.expires_at, pt.revoked_at,
                        b.name AS branch_name,
                        COALESCE(u.name, pa.name, 'Platform Admin') AS authorized_by
                 FROM   pos_terminals pt
                 LEFT JOIN branches b ON b.id = pt.branch_id
                 LEFT JOIN users u
                        ON pt.authorized_by_user_id > 0
                       AND u.id = pt.authorized_by_user_id
                 LEFT JOIN platform_admins pa
                        ON pt.authorized_by_user_id < 0
                       AND pa.id = ABS(pt.authorized_by_user_id)
                 WHERE  pt.company_id = :company_id
                 ORDER  BY pt.authorized_at DESC",
                ['company_id' => $session->company->id],
            );
        }

        return $this->render('admin/terminals/index.html.twig', [
            'session'   => $session,
            'terminals' => $terminals,
        ]);
    }

    // =========================================================================
    // POST /dashboard/admin/terminals/{id}/revoke — Revoke terminal (fetch)
    // =========================================================================

    #[Route('/{id}/revoke', name: 'admin_terminals_revoke', methods: ['POST'])]
    public function revoke(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'authorize_pos_terminal');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);
        if ($session->user->isSuperAdmin && !$this->platformCan->check($session, 'revoke_terminals')) {
            return $this->error('You do not have permission to revoke terminals.', 403);
        }

        $terminal = $this->db->fetchAssociative(
            'SELECT * FROM pos_terminals WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$terminal) return $this->error('Terminal not found.', 404);
        if ($terminal['revoked_at'] !== null) return $this->error('Terminal is already revoked.');

        $this->db->executeStatement(
            'UPDATE pos_terminals SET revoked_at = NOW() WHERE id = :id',
            ['id' => $id],
        );

        $this->activityLog->record(
            $session,
            'terminal.revoke',
            [
                'device' => (string) $terminal['device_name'],
                'identifier' => (string) $terminal['terminal_identifier'],
            ],
            permission: 'authorize_pos_terminal',
            subjectType: 'terminal',
            subjectId: $id,
            request: $request,
        );

        return $this->success("Terminal \"{$terminal['device_name']}\" has been revoked.");
    }

    // =========================================================================
    // POST /dashboard/admin/terminals/{id}/reactivate — Reactivate terminal (fetch)
    // =========================================================================

    #[Route('/{id}/reactivate', name: 'admin_terminals_reactivate', methods: ['POST'])]
    public function reactivate(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'authorize_pos_terminal');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);
        if ($session->user->isSuperAdmin && !$this->platformCan->check($session, 'authorize_pos_terminal')) {
            return $this->error('You do not have permission to authorize terminals.', 403);
        }

        $terminal = $this->db->fetchAssociative(
            'SELECT * FROM pos_terminals WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$terminal) return $this->error('Terminal not found.', 404);

        $expiresAt = (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d H:i:s');
        $authorizedById = $session->user->isSuperAdmin
            ? -1 * $session->user->id
            : $session->user->id;

        $this->db->executeStatement(
            'UPDATE pos_terminals
             SET revoked_at  = NULL,
                 branch_id   = :branch_id,
                 expires_at  = :expires_at,
                 authorized_by_user_id = :user_id,
                 authorized_at = NOW()
             WHERE id = :id',
            [
                'branch_id'  => $session->branch?->id,
                'expires_at' => $expiresAt,
                'user_id'    => $authorizedById,
                'id'         => $id,
            ],
        );

        $this->activityLog->record(
            $session,
            'terminal.reactivate',
            [
                'device' => (string) $terminal['device_name'],
                'identifier' => (string) $terminal['terminal_identifier'],
            ],
            permission: 'authorize_pos_terminal',
            subjectType: 'terminal',
            subjectId: $id,
            request: $request,
        );

        return $this->success("Terminal \"{$terminal['device_name']}\" has been reactivated for 30 days.");
    }
}
