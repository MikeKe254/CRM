<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/permissions', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class PermissionController extends AdminBaseController
{
    public function __construct(
        AuthService                        $auth,
        CheckPermissionService             $can,
        PlatformCheckPermissionService     $platformCan,
        BranchResolverService              $branchResolver,
        private readonly PermissionService $permissions,
        private readonly UserActivityLogService $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver);
    }

    // =========================================================================
    // GET /dashboard/admin/permissions — List all permissions grouped (Twig)
    // =========================================================================

    #[Route('', name: 'admin_permissions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_permissions');
        if ($session instanceof Response) return $session;

        $showDeleted = $session->user->isSuperAdmin
            && ($this->platformCan->isPlatformOwner($session) || $this->platformCan->check($session, 'view_deleted_entries'));

        $grouped = [];
        foreach ($this->permissions->listAll(includeDeleted: $showDeleted) as $p) {
            $grouped[$p['category']][] = $p;
        }

        $categories = $this->permissions->listCategories();

        $this->activityLog->record($session, 'permission.view', request: $request);

        return $this->render('admin/permissions/index.html.twig', [
            'session'     => $session,
            'grouped'     => $grouped,
            'categories'  => $categories,
            'showDeleted' => $showDeleted,
            'can'         => [
                'assign' => $this->can->check($session, 'assign_permissions'),
            ],
        ]);
    }
}
