<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use App\Services\Revenue\CatalogService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/{branch}/dashboard/admin/catalog',
    host: '{subdomain}.{domain}',
    requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'],
)]
class CatalogController extends AdminBaseController
{
    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService          $branchResolver,
        Connection                     $db,
        private readonly CatalogService           $catalog,
        private readonly UserActivityLogService   $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // GET — List
    // =========================================================================

    #[Route('', name: 'admin_catalog', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_catalog');
        if ($session instanceof Response) return $session;

        $showInactive = (bool) $request->query->get('inactive', false);
        $items        = $this->catalog->list($session->company->id, $session->branch->id, $showInactive);

        return $this->render('admin/catalog/index.html.twig', [
            'session'      => $session,
            'items'        => $items,
            'showInactive' => $showInactive,
            'can'          => [
                'create' => $this->can->check($session, 'create_catalog'),
                'edit'   => $this->can->check($session, 'edit_catalog'),
                'delete' => $this->can->check($session, 'delete_catalog'),
            ],
        ]);
    }

    // =========================================================================
    // POST — Create
    // =========================================================================

    #[Route('/create', name: 'admin_catalog_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'create_catalog');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $name     = trim((string) $request->request->get('name', ''));
        $type     = $request->request->get('type', 'service');
        $category = trim((string) $request->request->get('category', '')) ?: null;
        $priceRaw = trim((string) $request->request->get('price', ''));
        $price    = $priceRaw !== '' && is_numeric($priceRaw) ? (float) $priceRaw : null;

        if ($name === '') {
            return $this->error('Item name is required.');
        }

        if (strlen($name) > 120) {
            return $this->error('Name must be 120 characters or fewer.');
        }

        if (!in_array($type, ['service', 'product'], true)) {
            return $this->error('Type must be service or product.');
        }

        if ($price !== null && $price < 0) {
            return $this->error('Price cannot be negative.');
        }

        // Uniqueness within this branch
        $exists = $this->db->fetchOne(
            'SELECT id FROM catalog_items
              WHERE branch_id = :bid AND name = :name AND deleted_at IS NULL LIMIT 1',
            ['bid' => $session->branch->id, 'name' => $name],
        );

        if ($exists) {
            return $this->error('A catalog item with that name already exists.');
        }

        $id = $this->catalog->create(
            $session->company->id,
            $session->branch->id,
            $name,
            $type,
            $category,
            $price,
        );

        $this->activityLog->record($session, 'catalog.created', [
            'catalog_item_id'   => $id,
            'catalog_item_name' => $name,
            'type'              => $type,
        ]);

        return $this->success('Item created.', [
            'id'       => $id,
            'name'     => $name,
            'type'     => $type,
            'category' => $category,
            'price'    => $price,
        ]);
    }

    // =========================================================================
    // POST — Update
    // =========================================================================

    #[Route('/{id}/update', name: 'admin_catalog_update', methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_catalog');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $item = $this->catalog->findById($id, $session->company->id, $session->branch->id);
        if (!$item) {
            return $this->error('Item not found.', 404);
        }

        $name     = trim((string) $request->request->get('name', ''));
        $type     = $request->request->get('type', 'service');
        $category = trim((string) $request->request->get('category', '')) ?: null;
        $priceRaw = trim((string) $request->request->get('price', ''));
        $price    = $priceRaw !== '' && is_numeric($priceRaw) ? (float) $priceRaw : null;

        if ($name === '') {
            return $this->error('Item name is required.');
        }

        if (strlen($name) > 120) {
            return $this->error('Name must be 120 characters or fewer.');
        }

        if (!in_array($type, ['service', 'product'], true)) {
            return $this->error('Type must be service or product.');
        }

        if ($price !== null && $price < 0) {
            return $this->error('Price cannot be negative.');
        }

        // Uniqueness (exclude self)
        $exists = $this->db->fetchOne(
            'SELECT id FROM catalog_items
              WHERE branch_id = :bid AND name = :name AND id != :id AND deleted_at IS NULL LIMIT 1',
            ['bid' => $session->branch->id, 'name' => $name, 'id' => $id],
        );

        if ($exists) {
            return $this->error('A catalog item with that name already exists.');
        }

        $this->catalog->update(
            $id,
            $session->company->id,
            $session->branch->id,
            $name,
            $type,
            $category,
            $price,
        );

        $this->activityLog->record($session, 'catalog.updated', [
            'catalog_item_id'   => $id,
            'catalog_item_name' => $name,
        ]);

        return $this->success('Item updated.', [
            'id'       => $id,
            'name'     => $name,
            'type'     => $type,
            'category' => $category,
            'price'    => $price,
        ]);
    }

    // =========================================================================
    // POST — Toggle status
    // =========================================================================

    #[Route('/{id}/toggle-status', name: 'admin_catalog_toggle_status', methods: ['POST'])]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_catalog');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $item = $this->catalog->findById($id, $session->company->id, $session->branch->id);
        if (!$item) {
            return $this->error('Item not found.', 404);
        }

        $newStatus = $item['status'] === 'active' ? 'inactive' : 'active';
        $this->catalog->setStatus($id, $session->company->id, $session->branch->id, $newStatus);

        $this->activityLog->record($session, 'catalog.status_changed', [
            'catalog_item_id'   => $id,
            'catalog_item_name' => $item['name'],
            'new_status'        => $newStatus,
        ]);

        return $this->success('Status updated.', ['id' => $id, 'status' => $newStatus]);
    }

    // =========================================================================
    // POST — Delete
    // =========================================================================

    #[Route('/{id}/delete', name: 'admin_catalog_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'delete_catalog');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $item = $this->catalog->findById($id, $session->company->id, $session->branch->id);
        if (!$item) {
            return $this->error('Item not found.', 404);
        }

        $this->catalog->delete($id, $session->company->id, $session->branch->id);

        $this->activityLog->record($session, 'catalog.deleted', [
            'catalog_item_id'   => $id,
            'catalog_item_name' => $item['name'],
        ]);

        return $this->success('Item deleted.');
    }
}
