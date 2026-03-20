<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/platform/activity-logs', host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class ActivityLogController extends PlatformBaseController
{
    public function __construct(
        AuthService                    $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection    $db,
    ) {
        parent::__construct($auth, $platformCan);
    }

    #[Route('', name: 'platform_activity_logs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // No permission passed to requirePlatform — we do a custom check below
        // so the owner always bypasses and non-owners need at least one of the two permissions.
        $session = $this->requirePlatform($request);
        if ($session instanceof Response) return $session;

        $isOwner         = $this->platformCan->isPlatformOwner($session);
        $canViewSuperadmin = $this->platformCan->check($session, 'view_superadmin_activity_logs');
        $canViewOwner      = $this->platformCan->check($session, 'view_owner_activity_logs');

        if (!$isOwner && !$canViewSuperadmin && !$canViewOwner) {
            return $this->render('platform/errors/403.html.twig', [
                'session' => $session,
                'message' => 'You do not have permission to view activity logs.',
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $filters = [
            'admin_id'   => $request->query->get('admin_id'),
            'company_id' => $request->query->get('company_id'),
            'action'     => $request->query->get('action'),
            'date_from'  => $request->query->get('date_from'),
            'date_to'    => $request->query->get('date_to'),
            'search'     => trim((string) $request->query->get('search', '')),
        ];

        $where  = ["ual.actor_type = 'superadmin'"];
        $params = [];

        // If the viewer cannot see owner logs, restrict to non-owner admins only
        if (!$isOwner && !$canViewOwner) {
            $where[] = '(pa.is_platform_owner = 0 OR pa.is_platform_owner IS NULL)';
        }

        if ($filters['admin_id']) {
            $where[]            = 'ual.user_id = :admin_id';
            $params['admin_id'] = (int) $filters['admin_id'];
        }
        if ($filters['company_id']) {
            $where[]              = 'ual.company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
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

        // The pa JOIN must be INNER for the owner-restriction filter to work correctly,
        // but LEFT for all other cases — use LEFT JOIN and apply the WHERE condition only when needed.
        $joinType = (!$isOwner && !$canViewOwner) ? 'INNER' : 'LEFT';

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*)
               FROM user_activity_logs ual
               {$joinType} JOIN platform_admins pa ON pa.id = ual.user_id AND ual.actor_type = 'superadmin'
             {$whereSQL}",
            $params,
        );

        $logs = $this->db->fetchAllAssociative(
            "SELECT ual.*,
                    COALESCE(pa.name,  u.name)  AS admin_name,
                    COALESCE(pa.email, u.email) AS admin_email,
                    pa.is_platform_owner        AS admin_is_owner,
                    c.name AS company_name,
                    m.name AS module_name
               FROM user_activity_logs ual
               {$joinType} JOIN platform_admins pa ON pa.id = ual.user_id AND ual.actor_type = 'superadmin'
               LEFT JOIN users     u  ON u.id  = ual.user_id AND ual.actor_type = 'tenant'
               LEFT JOIN companies c  ON c.id  = ual.company_id
               LEFT JOIN modules   m  ON m.id  = ual.module_id
             {$whereSQL}
             ORDER BY ual.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $ownerClause = (!$isOwner && !$canViewOwner) ? 'AND (pa.is_platform_owner = 0 OR pa.is_platform_owner IS NULL)' : '';
        $admins = $this->db->fetchAllAssociative(
            "SELECT DISTINCT pa.id, pa.name, pa.is_platform_owner
               FROM platform_admins pa
               JOIN user_activity_logs ual ON ual.user_id = pa.id AND ual.actor_type = 'superadmin'
              WHERE 1 = 1 {$ownerClause}
              ORDER BY pa.name",
        );

        $companies = $this->db->fetchAllAssociative(
            "SELECT DISTINCT c.id, c.name
               FROM companies c
               JOIN user_activity_logs ual ON ual.company_id = c.id AND ual.actor_type = 'superadmin'
              ORDER BY c.name",
        );

        $actionVerbs = $this->db->fetchFirstColumn(
            "SELECT DISTINCT action FROM user_activity_logs
              WHERE actor_type = 'superadmin'
              ORDER BY action",
        );

        return $this->render('platform/activity_logs/index.html.twig', [
            'session'          => $session,
            'logs'             => $logs,
            'admins'           => $admins,
            'companies'        => $companies,
            'actionVerbs'      => $actionVerbs,
            'filters'          => $filters,
            'page'             => $page,
            'per_page'         => $perPage,
            'total'            => $total,
            'pages'            => (int) ceil($total / $perPage),
            'canViewOwner'     => $isOwner || $canViewOwner,
        ]);
    }
}
