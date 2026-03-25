<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Branch\BranchHierarchyService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/activity-logs', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class ActivityLogController extends AdminBaseController
{
    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService          $branchResolver,
        Connection                     $db,
        private readonly BranchHierarchyService $hierarchy,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    #[Route('', name: 'admin_activity_logs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_user_activity');
        if ($session instanceof Response) return $session;

        if ($session->user->isSuperAdmin) {
            $canViewOwnerLogs      = $this->platformCan->isPlatformOwner($session) || $this->platformCan->check($session, 'view_owner_activity_logs');
            $canViewSuperadminLogs = $this->platformCan->check($session, 'view_superadmin_activity_logs');

            if (!$canViewOwnerLogs && !$canViewSuperadminLogs) {
                return $this->denyAccess($request, 'You do not have permission to view tenant activity logs.', 403, $session);
            }
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $filters = [
            'user_id'   => $request->query->get('user_id'),
            'action'    => $request->query->get('action'),
            'date_from' => $request->query->get('date_from'),
            'date_to'   => $request->query->get('date_to'),
            'search'    => trim((string) $request->query->get('search', '')),
        ];

        $where  = ['ual.company_id = :company_id'];
        $params = [
            'company_id' => $session->company->id,
            'viewer_user_id' => $session->user->id,
        ];

        if ($session->branch !== null) {
            $where[] = 'ual.branch_id = :branch_id';
            $params['branch_id'] = $session->branch->id;
        }

        $isTenantLeadershipViewer = $this->isTenantLeadershipViewer($session);
        $isSuperAdmin             = $session->user->isSuperAdmin;
        $isPlatformOwner          = $isSuperAdmin && $this->platformCan->isPlatformOwner($session);
        $canViewOwnerLogs         = $isSuperAdmin && $this->platformCan->check($session, 'view_owner_activity_logs');
        $canViewSuperadminLogs    = $isSuperAdmin && $this->platformCan->check($session, 'view_superadmin_activity_logs');

        $tenantLeadershipExistsSql = "EXISTS (
            SELECT 1
            FROM user_node_roles unr2
            JOIN roles r2 ON r2.id = unr2.role_id
            WHERE unr2.user_id = ual.user_id
              AND r2.company_id = :company_id
              AND r2.deleted_at IS NULL
              AND r2.name IN ('Owner', 'Director')
        )";

        $actorClauses = [];
        $actorClauses[] = $isTenantLeadershipViewer
            ? "(ual.actor_type = 'tenant' AND ((NOT {$tenantLeadershipExistsSql}) OR ual.user_id = :viewer_user_id))"
            : "(ual.actor_type = 'tenant' AND NOT {$tenantLeadershipExistsSql})";

        if ($isSuperAdmin) {
            $actorClauses[] = "(ual.actor_type = 'superadmin' AND ual.user_id = :viewer_user_id)";

            if ($canViewSuperadminLogs) {
                $actorClauses[] = "(ual.actor_type = 'superadmin' AND COALESCE(pa.is_platform_owner, 0) = 0)";
            }

            if ($isPlatformOwner || $canViewOwnerLogs) {
                $actorClauses[] = "(ual.actor_type = 'superadmin' AND COALESCE(pa.is_platform_owner, 0) = 1)";
            }
        }

        $where[] = '(' . implode(' OR ', $actorClauses) . ')';

        if ($filters['user_id']) {
            $where[]           = 'ual.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if ($filters['action']) {
            $where[]          = 'ual.action = :action';
            $params['action'] = $filters['action'];
        }
        if ($filters['date_from']) {
            $where[]             = 'ual.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to']) {
            $where[]           = 'ual.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if ($filters['search'] !== '') {
            $where[]          = 'ual.description LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $paJoin   = "LEFT JOIN platform_admins pa ON pa.id = ual.user_id AND ual.actor_type = 'superadmin'";
        $userJoin = "LEFT JOIN users u ON u.id = ual.user_id AND ual.actor_type = 'tenant'";
        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*)
               FROM user_activity_logs ual
               {$paJoin}
             {$whereSQL}",
            $params,
        );

        $logs = $this->db->fetchAllAssociative(
            "SELECT ual.*,
                    COALESCE(pa.name, u.name)          AS user_name,
                    COALESCE(pa.is_platform_owner, 0)  AS actor_is_owner,
                    m.name                             AS module_name
               FROM user_activity_logs ual
               {$paJoin}
               {$userJoin}
               LEFT JOIN modules m ON m.id = ual.module_id
             {$whereSQL}
             ORDER BY ual.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $userVisibilitySql = $isTenantLeadershipViewer
            ? "AND (
                    NOT EXISTS (
                        SELECT 1
                        FROM user_node_roles unr2
                        JOIN roles r2 ON r2.id = unr2.role_id
                        WHERE unr2.user_id = u.id
                          AND r2.company_id = :company_id
                          AND r2.deleted_at IS NULL
                          AND r2.name IN ('Owner', 'Director')
                    )
                    OR u.id = :viewer_user_id
               )"
            : "AND NOT EXISTS (
                    SELECT 1
                    FROM user_node_roles unr2
                    JOIN roles r2 ON r2.id = unr2.role_id
                    WHERE unr2.user_id = u.id
                      AND r2.company_id = :company_id
                      AND r2.deleted_at IS NULL
                      AND r2.name IN ('Owner', 'Director')
               )";

        $users = $session->branch !== null
            ? $this->db->fetchAllAssociative(
                "SELECT DISTINCT u.id, u.name
                   FROM users u
                   JOIN user_activity_logs ual ON ual.user_id = u.id AND ual.actor_type = 'tenant'
                  WHERE u.company_id = :company_id
                    AND u.deleted_at IS NULL
                    AND ual.branch_id = :branch_id
                    {$userVisibilitySql}
                  ORDER BY u.name",
                [
                    'company_id' => $session->company->id,
                    'branch_id' => $session->branch->id,
                    'viewer_user_id' => $session->user->id,
                ],
            )
            : [];

        $actionVerbs = $this->db->fetchFirstColumn(
            "SELECT DISTINCT ual.action
               FROM user_activity_logs ual
               {$paJoin}
             {$whereSQL}
             ORDER BY ual.action",
            $params,
        );

        return $this->render('admin/activity-logs/index.html.twig', [
            'session'        => $session,
            'logs'           => $logs,
            'users'          => $users,
            'actionVerbs'    => $actionVerbs,
            'filters'        => $filters,
            'page'           => $page,
            'per_page'       => $perPage,
            'total'          => $total,
            'pages'          => (int) ceil($total / $perPage),
            'showActorType'  => true,
            'scopeMode'      => 'branch',
            'hasSubBranches' => false,
        ]);
    }

    private function isTenantLeadershipViewer(object $session): bool
    {
        if ($session->user->isSuperAdmin) {
            return false;
        }

        $roleIds = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT role_id FROM user_node_roles WHERE user_id = :user_id',
            ['user_id' => $session->user->id],
        ));

        if ($roleIds === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $roleNames = array_map(
            'strtolower',
            $this->db->fetchFirstColumn(
                "SELECT name
                   FROM roles
                  WHERE id IN ({$placeholders})
                    AND company_id = ?
                    AND deleted_at IS NULL",
                array_merge($roleIds, [$session->company->id]),
            ),
        );

        return (bool) array_intersect($roleNames, ['owner', 'director']);
    }
}
