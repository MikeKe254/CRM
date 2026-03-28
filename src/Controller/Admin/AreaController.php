<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Company\AreaService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/{branch}/dashboard/admin/areas',
    host: '{subdomain}.{domain}',
    requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'],
)]
class AreaController extends AdminBaseController
{
    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService          $branchResolver,
        Connection                     $db,
        private readonly AreaService              $areas,
        private readonly UserActivityLogService   $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // GET — List
    // =========================================================================

    #[Route('', name: 'admin_areas', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_areas');
        if ($session instanceof Response) return $session;

        $showInactive = (bool) $request->query->get('inactive', false);
        $areas        = $this->areas->list($session->company->id, $session->branch->id, $showInactive);

        return $this->render('admin/areas/index.html.twig', [
            'session'      => $session,
            'areas'        => $areas,
            'showInactive' => $showInactive,
            'can'          => [
                'create'   => $this->can->check($session, 'create_areas'),
                'edit'     => $this->can->check($session, 'edit_areas'),
                'delete'   => $this->can->check($session, 'delete_areas'),
            ],
        ]);
    }

    // =========================================================================
    // POST — Create
    // =========================================================================

    #[Route('/create', name: 'admin_areas_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'create_areas');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $name            = trim((string) $request->request->get('name', ''));
        $description     = trim((string) $request->request->get('description', '')) ?: null;
        $isTransactional = (bool) $request->request->get('is_transactional', false);

        if ($name === '') {
            return $this->error('Area name is required.');
        }

        if (strlen($name) > 120) {
            return $this->error('Name must be 120 characters or fewer.');
        }

        // Check uniqueness within this branch
        $exists = $this->db->fetchOne(
            'SELECT id FROM areas
              WHERE branch_id = :bid AND name = :name AND deleted_at IS NULL LIMIT 1',
            ['bid' => $session->branch->id, 'name' => $name],
        );

        if ($exists) {
            return $this->error('An area with that name already exists.');
        }

        $id = $this->areas->create($session->company->id, $session->branch->id, $name, $description, $isTransactional);

        $this->activityLog->record($session, 'area.created', [
            'area_id'   => $id,
            'area_name' => $name,
        ]);

        return $this->success('Area created.', ['id' => $id, 'name' => $name, 'description' => $description]);
    }

    // =========================================================================
    // POST — Update
    // =========================================================================

    #[Route('/{id}/update', name: 'admin_areas_update', methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_areas');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $area = $this->areas->findById($id, $session->company->id, $session->branch->id);
        if (!$area) {
            return $this->error('Area not found.', 404);
        }

        $name            = trim((string) $request->request->get('name', ''));
        $description     = trim((string) $request->request->get('description', '')) ?: null;
        $isTransactional = (bool) $request->request->get('is_transactional', false);

        if ($name === '') {
            return $this->error('Area name is required.');
        }

        if (strlen($name) > 120) {
            return $this->error('Name must be 120 characters or fewer.');
        }

        // Check uniqueness within this branch (exclude self)
        $exists = $this->db->fetchOne(
            'SELECT id FROM areas
              WHERE branch_id = :bid AND name = :name AND id != :id AND deleted_at IS NULL LIMIT 1',
            ['bid' => $session->branch->id, 'name' => $name, 'id' => $id],
        );

        if ($exists) {
            return $this->error('An area with that name already exists.');
        }

        $this->areas->update($id, $session->company->id, $session->branch->id, $name, $description, $isTransactional);

        $this->activityLog->record($session, 'area.updated', [
            'area_id'   => $id,
            'area_name' => $name,
        ]);

        return $this->success('Area updated.', ['id' => $id, 'name' => $name, 'description' => $description]);
    }

    // =========================================================================
    // POST — Toggle status
    // =========================================================================

    #[Route('/{id}/toggle-status', name: 'admin_areas_toggle_status', methods: ['POST'])]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_areas');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $area = $this->areas->findById($id, $session->company->id, $session->branch->id);
        if (!$area) {
            return $this->error('Area not found.', 404);
        }

        $newStatus = $area['status'] === 'active' ? 'inactive' : 'active';
        $this->areas->setStatus($id, $session->company->id, $session->branch->id, $newStatus);

        $this->activityLog->record($session, 'area.status_changed', [
            'area_id'    => $id,
            'area_name'  => $area['name'],
            'new_status' => $newStatus,
        ]);

        return $this->success('Status updated.', ['id' => $id, 'status' => $newStatus]);
    }

    // =========================================================================
    // POST — Delete
    // =========================================================================

    #[Route('/{id}/delete', name: 'admin_areas_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'delete_areas');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $area = $this->areas->findById($id, $session->company->id, $session->branch->id);
        if (!$area) {
            return $this->error('Area not found.', 404);
        }

        if ((bool) $area['is_system']) {
            return $this->error('System areas cannot be deleted. You can deactivate them instead.');
        }

        $this->areas->delete($id, $session->company->id, $session->branch->id);

        $this->activityLog->record($session, 'area.deleted', [
            'area_id'   => $id,
            'area_name' => $area['name'],
        ]);

        return $this->success('Area deleted.');
    }
}
