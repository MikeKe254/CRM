<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/platform/dashboard', host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class DashboardController extends PlatformBaseController
{
    public function __construct(
        AuthService $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $platformCan);
    }

    #[Route('', name: 'platform_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requirePlatform($request);

        if ($session instanceof Response) {
            return $session;
        }

        $stats = [
            'companies' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM companies WHERE id <> 0 AND deleted_at IS NULL'),
            'active_companies' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM companies WHERE id <> 0 AND status = 'active' AND deleted_at IS NULL"),
            'platform_admins' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM platform_admins WHERE status = 'active' AND deleted_at IS NULL"),
            'tenant_users' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE company_id IS NOT NULL AND company_id <> 0 AND deleted_at IS NULL'),
            'active_terminals' => (int) $this->db->fetchOne(
                'SELECT COUNT(*)
                 FROM pos_terminals
                 WHERE revoked_at IS NULL
                   AND (expires_at IS NULL OR expires_at >= NOW())'
            ),
            'platform_logs' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM user_activity_logs WHERE actor_type = 'superadmin'"),
        ];

        $recentCompanies = $this->db->fetchAllAssociative(
            'SELECT c.id,
                    c.name,
                    c.subdomain,
                    c.plan,
                    c.status,
                    c.created_at,
                    COUNT(DISTINCT u.id) AS user_count,
                    COUNT(DISTINCT pt.id) AS terminal_count
             FROM companies c
             LEFT JOIN users u ON u.company_id = c.id
             LEFT JOIN pos_terminals pt
               ON pt.company_id = c.id
              AND pt.revoked_at IS NULL
              AND (pt.expires_at IS NULL OR pt.expires_at >= NOW())
             WHERE c.id <> 0
             GROUP BY c.id, c.name, c.subdomain, c.plan, c.status, c.created_at
             ORDER BY c.created_at DESC
             LIMIT 6'
        );

        $recentPlatformAdmins = $this->db->fetchAllAssociative(
            'SELECT pa.id,
                    pa.name,
                    pa.email,
                    pa.status,
                    pa.is_platform_owner,
                    GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR ", ") AS roles
             FROM platform_admins pa
             LEFT JOIN platform_admin_roles par ON par.platform_admin_id = pa.id
             LEFT JOIN platform_roles pr ON pr.id = par.platform_role_id
             GROUP BY pa.id, pa.name, pa.email, pa.status, pa.is_platform_owner
             ORDER BY pa.created_at DESC
             LIMIT 6'
        );

        return $this->render('platform/dashboard/index.html.twig', [
            'session' => $session,
            'stats' => $stats,
            'recent_companies' => $recentCompanies,
            'recent_platform_admins' => $recentPlatformAdmins,
        ]);
    }
}
