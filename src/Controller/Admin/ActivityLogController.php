<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/admin/activity-logs', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin$)[A-Za-z0-9-]+', 'domain' => '.+'])]
class ActivityLogController extends AdminBaseController
{
    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection    $db,
    ) {
        parent::__construct($auth, $can, $platformCan);
    }

    #[Route('', name: 'admin_activity_logs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_user_activity');
        if ($session instanceof Response) return $session;

        // Determine what actor types this viewer is allowed to see.
        // Regular tenant users see only their own team's actions (actor_type = 'tenant').
        // Platform admins can additionally see superadmin actions on this company,
        // subject to their platform-level permissions.
        $isSuperAdmin    = $session->user->isSuperAdmin;
        $isOwner         = $isSuperAdmin && $this->platformCan->isPlatformOwner($session);
        $canViewOwner    = $isSuperAdmin && $this->platformCan->check($session, 'view_owner_activity_logs');
        $canViewSuperadmin = $isSuperAdmin && $this->platformCan->check($session, 'view_superadmin_activity_logs');

        // Build the actor_type condition:
        //   - tenant logs always included
        //   - owner/view_owner_activity_logs → include all superadmin logs
        //   - view_superadmin_activity_logs only → include non-owner superadmin logs
        if ($isOwner || $canViewOwner) {
            $actorCondition = "(ual.actor_type = 'tenant' OR ual.actor_type = 'superadmin')";
        } elseif ($canViewSuperadmin) {
            // Include superadmin logs only for non-owner platform admins
            $actorCondition = "(ual.actor_type = 'tenant' OR (ual.actor_type = 'superadmin' AND (pa.is_platform_owner = 0 OR pa.is_platform_owner IS NULL)))";
        } else {
            $actorCondition = "ual.actor_type = 'tenant'";
        }

        // We need the pa JOIN whenever superadmin logs may be included
        $needsPaJoin = $canViewSuperadmin || $canViewOwner || $isOwner;

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

        $where  = ['ual.company_id = :company_id', $actorCondition];
        $params = ['company_id' => $session->company->id];

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

        $paJoin   = $needsPaJoin ? "LEFT JOIN platform_admins pa ON pa.id = ual.user_id AND ual.actor_type = 'superadmin'" : '';
        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_activity_logs ual {$paJoin} {$whereSQL}",
            $params,
        );

        $nameSelect  = $needsPaJoin ? 'COALESCE(pa.name, u.name)' : 'u.name';
        $ownerSelect = $needsPaJoin ? 'pa.is_platform_owner' : '0';
        $userJoin    = $needsPaJoin
            ? "LEFT JOIN users u ON u.id = ual.user_id AND ual.actor_type = 'tenant'"
            : 'LEFT JOIN users u ON u.id = ual.user_id';

        $logs = $this->db->fetchAllAssociative(
            "SELECT ual.*,
                    {$nameSelect}  AS user_name,
                    {$ownerSelect} AS actor_is_owner,
                    m.name         AS module_name
               FROM user_activity_logs ual
               {$paJoin}
               {$userJoin}
               LEFT JOIN modules m ON m.id = ual.module_id
             {$whereSQL}
             ORDER BY ual.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $users = $this->db->fetchAllAssociative(
            'SELECT id, name FROM users WHERE company_id = :company_id ORDER BY name',
            ['company_id' => $session->company->id],
        );

        $actionVerbs = $this->db->fetchFirstColumn(
            "SELECT DISTINCT ual.action
               FROM user_activity_logs ual
               {$paJoin}
             WHERE ual.company_id = :company_id AND {$actorCondition}
             ORDER BY ual.action",
            ['company_id' => $session->company->id],
        );

        return $this->render('admin/activity-logs/index.html.twig', [
            'session'      => $session,
            'logs'         => $logs,
            'users'        => $users,
            'actionVerbs'  => $actionVerbs,
            'filters'      => $filters,
            'page'         => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'pages'        => (int) ceil($total / $perPage),
            'showActorType' => $needsPaJoin,
        ]);
    }
}
