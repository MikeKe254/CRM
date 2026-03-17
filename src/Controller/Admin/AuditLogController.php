<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Permission\CheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/admin/audit-logs')]
class AuditLogController extends AdminBaseController
{
    public function __construct(
        AuthService            $auth,
        CheckPermissionService $can,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $can);
    }

    // =========================================================================
    // GET /dashboard/admin/audit-logs — List (Twig) — super admin only
    // =========================================================================

    #[Route('', name: 'admin_audit_logs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireSuperAdmin($request);
        if ($session instanceof Response) return $session;

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $filters = [
            'user_id'      => $request->query->get('user_id'),
            'action'       => $request->query->get('action'),
            'target_table' => $request->query->get('target_table'),
            'date_from'    => $request->query->get('date_from'),
            'date_to'      => $request->query->get('date_to'),
        ];

        $where  = ['ul.company_id = :company_id'];
        $params = ['company_id' => $session->company->id];

        if ($filters['user_id']) {
            $where[]             = 'ul.user_id = :user_id';
            $params['user_id']   = $filters['user_id'];
        }
        if ($filters['action']) {
            $where[]           = 'ul.action LIKE :action';
            $params['action']  = '%' . $filters['action'] . '%';
        }
        if ($filters['target_table']) {
            $where[]                  = 'ul.target_table = :target_table';
            $params['target_table']   = $filters['target_table'];
        }
        if ($filters['date_from']) {
            $where[]                = 'ul.created_at >= :date_from';
            $params['date_from']    = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to']) {
            $where[]              = 'ul.created_at <= :date_to';
            $params['date_to']    = $filters['date_to'] . ' 23:59:59';
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_logs ul {$whereSQL}",
            $params,
        );

        $logs = $this->db->fetchAllAssociative(
            "SELECT ul.*, u.name AS user_name, p.name AS permission_name
             FROM   user_logs ul
             LEFT JOIN users u ON u.id = ul.user_id
             LEFT JOIN permissions p ON p.id = ul.permission_id
             {$whereSQL}
             ORDER  BY ul.created_at DESC
             LIMIT  :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset]),
        );

        $users = $this->db->fetchAllAssociative(
            'SELECT id, name FROM users WHERE company_id = :company_id ORDER BY name',
            ['company_id' => $session->company->id],
        );

        return $this->render('admin/audit-logs/index.html.twig', [
            'session'  => $session,
            'logs'     => $logs,
            'users'    => $users,
            'filters'  => $filters,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => (int) ceil($total / $perPage),
        ]);
    }
}
