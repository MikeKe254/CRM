<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/platform/platform-admins', host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class PlatformAdminController extends PlatformBaseController
{
    public function __construct(
        AuthService $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $platformCan);
    }

    #[Route('', name: 'platform_admins', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requirePlatform($request, 'view_platform_admins');

        if ($session instanceof Response) {
            return $session;
        }

        $admins = $this->db->fetchAllAssociative(
            'SELECT pa.id,
                    pa.name,
                    pa.email,
                    pa.mobile,
                    pa.status,
                    pa.is_platform_owner,
                    pa.is_system_account,
                    pa.created_at,
                    GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR ", ") AS roles,
                    COUNT(DISTINCT prp.platform_permission_id) AS permission_count
             FROM platform_admins pa
             LEFT JOIN platform_admin_roles par ON par.platform_admin_id = pa.id
             LEFT JOIN platform_roles pr ON pr.id = par.platform_role_id
             LEFT JOIN platform_role_permissions prp ON prp.platform_role_id = pr.id
             GROUP BY pa.id, pa.name, pa.email, pa.status, pa.is_platform_owner, pa.is_system_account, pa.created_at
             ORDER BY pa.created_at DESC'
        );

        return $this->render('platform/platform_admins/index.html.twig', [
            'session' => $session,
            'admins'  => $admins,
        ]);
    }

    #[Route('/{id}/edit', name: 'platform_admins_edit', methods: ['POST'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $admin = $this->db->fetchAssociative(
            'SELECT id, name FROM platform_admins WHERE id = :id',
            ['id' => $id],
        );

        if (!$admin) {
            return new JsonResponse(['success' => false, 'message' => 'Admin not found.'], 404);
        }

        $name   = trim((string) $request->request->get('name', ''));
        $mobile = trim((string) $request->request->get('mobile', '')) ?: null;

        if ($name === '') {
            return new JsonResponse(['success' => false, 'message' => 'Name is required.']);
        }

        $this->db->update('platform_admins', ['name' => $name, 'mobile' => $mobile], ['id' => $id]);

        return new JsonResponse(['success' => true, 'message' => 'Updated.']);
    }
}
