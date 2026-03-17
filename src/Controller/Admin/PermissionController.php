<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PermissionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/admin/permissions')]
class PermissionController extends AdminBaseController
{
    public function __construct(
        AuthService                        $auth,
        CheckPermissionService             $can,
        private readonly PermissionService $permissions,
    ) {
        parent::__construct($auth, $can);
    }

    // =========================================================================
    // GET /dashboard/admin/permissions — List all permissions grouped (Twig)
    // =========================================================================

    #[Route('', name: 'admin_permissions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_permissions');
        if ($session instanceof Response) return $session;

        $grouped = [];
        foreach ($this->permissions->listAll() as $p) {
            $grouped[$p['category']][] = $p;
        }

        $categories = $this->permissions->listCategories();

        return $this->render('admin/permissions/index.html.twig', [
            'session'    => $session,
            'grouped'    => $grouped,
            'categories' => $categories,
            'can'        => [
                'assign' => $this->can->check($session, 'assign_permissions'),
            ],
        ]);
    }
}
