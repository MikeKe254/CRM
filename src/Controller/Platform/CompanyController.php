<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/platform/companies', host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class CompanyController extends PlatformBaseController
{
    public function __construct(
        AuthService $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $platformCan);
    }

    #[Route('', name: 'platform_companies', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requirePlatform($request, 'view_companies');

        if ($session instanceof Response) {
            return $session;
        }

        $showDeleted = $this->platformCan->isPlatformOwner($session)
            || $this->platformCan->check($session, 'view_deleted_entries');

        $deletedFilter = $showDeleted ? '' : 'AND c.deleted_at IS NULL';

        $companies = $this->db->fetchAllAssociative(
            "SELECT c.id,
                    c.name,
                    c.subdomain,
                    c.email,
                    c.phone,
                    c.plan,
                    c.status,
                    c.created_at,
                    c.deleted_at,
                    COUNT(DISTINCT u.id) AS user_count,
                    COUNT(DISTINCT pt.id) AS terminal_count,
                    COUNT(DISTINCT mp.id) AS payment_count
             FROM companies c
             LEFT JOIN users u ON u.company_id = c.id
             LEFT JOIN pos_terminals pt
               ON pt.company_id = c.id
              AND pt.revoked_at IS NULL
              AND (pt.expires_at IS NULL OR pt.expires_at >= NOW())
             LEFT JOIN mpesa_payments mp ON mp.company_id = c.id
             WHERE c.id <> 0
               {$deletedFilter}
             GROUP BY c.id, c.name, c.subdomain, c.email, c.phone, c.plan, c.status, c.created_at, c.deleted_at
             ORDER BY c.name ASC"
        );

        return $this->render('platform/companies/index.html.twig', [
            'session'     => $session,
            'companies'   => $companies,
            'showDeleted' => $showDeleted,
        ]);
    }
}
