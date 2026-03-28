<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Company\DepartmentService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/{branch}/dashboard/admin/departments',
    host: '{subdomain}.{domain}',
    requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'],
)]
class DepartmentController extends AdminBaseController
{
    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService          $branchResolver,
        Connection                     $db,
        private readonly DepartmentService         $departments,
        private readonly UserActivityLogService    $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // GET — List
    // =========================================================================

    #[Route('', name: 'admin_departments', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_departments');
        if ($session instanceof Response) return $session;

        $showInactive = (bool) $request->query->get('inactive', false);
        $departments  = $this->departments->list($session->company->id, $session->branch->id, $showInactive);

        return $this->render('admin/departments/index.html.twig', [
            'session'      => $session,
            'departments'  => $departments,
            'showInactive' => $showInactive,
            'can'          => [
                'create'   => $this->can->check($session, 'create_departments'),
                'edit'     => $this->can->check($session, 'edit_departments'),
                'delete'   => $this->can->check($session, 'delete_departments'),
            ],
        ]);
    }

    // =========================================================================
    // POST — Create
    // =========================================================================

    #[Route('/create', name: 'admin_departments_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'create_departments');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $name        = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', '')) ?: null;

        if ($name === '') {
            return $this->error('Department name is required.');
        }

        if (strlen($name) > 120) {
            return $this->error('Name must be 120 characters or fewer.');
        }

        // Check uniqueness within this branch
        $exists = $this->db->fetchOne(
            'SELECT id FROM departments
              WHERE branch_id = :bid AND name = :name AND deleted_at IS NULL LIMIT 1',
            ['bid' => $session->branch->id, 'name' => $name],
        );

        if ($exists) {
            return $this->error('A department with that name already exists.');
        }

        $id = $this->departments->create($session->company->id, $session->branch->id, $name, $description);

        $this->activityLog->record($session, 'department.created', [
            'department_id'   => $id,
            'department_name' => $name,
        ]);

        return $this->success('Department created.', ['id' => $id, 'name' => $name, 'description' => $description]);
    }

    // =========================================================================
    // POST — Update
    // =========================================================================

    #[Route('/{id}/update', name: 'admin_departments_update', methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_departments');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $department = $this->departments->findById($id, $session->company->id, $session->branch->id);
        if (!$department) {
            return $this->error('Department not found.', 404);
        }

        $name        = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', '')) ?: null;

        if ($name === '') {
            return $this->error('Department name is required.');
        }

        if (strlen($name) > 120) {
            return $this->error('Name must be 120 characters or fewer.');
        }

        // Check uniqueness within this branch (exclude self)
        $exists = $this->db->fetchOne(
            'SELECT id FROM departments
              WHERE branch_id = :bid AND name = :name AND id != :id AND deleted_at IS NULL LIMIT 1',
            ['bid' => $session->branch->id, 'name' => $name, 'id' => $id],
        );

        if ($exists) {
            return $this->error('A department with that name already exists.');
        }

        $this->departments->update($id, $session->company->id, $session->branch->id, $name, $description);

        $this->activityLog->record($session, 'department.updated', [
            'department_id'   => $id,
            'department_name' => $name,
        ]);

        return $this->success('Department updated.', ['id' => $id, 'name' => $name, 'description' => $description]);
    }

    // =========================================================================
    // POST — Toggle status
    // =========================================================================

    #[Route('/{id}/toggle-status', name: 'admin_departments_toggle_status', methods: ['POST'])]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_departments');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $department = $this->departments->findById($id, $session->company->id, $session->branch->id);
        if (!$department) {
            return $this->error('Department not found.', 404);
        }

        $newStatus = $department['status'] === 'active' ? 'inactive' : 'active';
        $this->departments->setStatus($id, $session->company->id, $session->branch->id, $newStatus);

        $this->activityLog->record($session, 'department.status_changed', [
            'department_id'   => $id,
            'department_name' => $department['name'],
            'new_status'      => $newStatus,
        ]);

        return $this->success('Status updated.', ['id' => $id, 'status' => $newStatus]);
    }

    // =========================================================================
    // POST — Delete
    // =========================================================================

    #[Route('/{id}/delete', name: 'admin_departments_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'delete_departments');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $department = $this->departments->findById($id, $session->company->id, $session->branch->id);
        if (!$department) {
            return $this->error('Department not found.', 404);
        }

        if ((bool) $department['is_system']) {
            return $this->error('System departments cannot be deleted. You can deactivate them instead.');
        }

        $this->departments->delete($id, $session->company->id, $session->branch->id);

        $this->activityLog->record($session, 'department.deleted', [
            'department_id'   => $id,
            'department_name' => $department['name'],
        ]);

        return $this->success('Department deleted.');
    }
}
