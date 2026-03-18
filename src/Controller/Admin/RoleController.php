<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/admin/roles')]
class RoleController extends AdminBaseController
{
    public function __construct(
        AuthService                      $auth,
        CheckPermissionService           $can,
        private readonly Connection      $db,
        private readonly PermissionService $permissions,
    ) {
        parent::__construct($auth, $can);
    }

    // =========================================================================
    // GET /dashboard/admin/roles — List (Twig)
    // =========================================================================

    #[Route('', name: 'admin_roles', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_roles');
        if ($session instanceof Response) return $session;

        $roles = $this->db->fetchAllAssociative(
            'SELECT r.id, r.name, r.description, r.is_system_role,
                    COUNT(rp.id) AS permission_count
             FROM   roles r
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             WHERE  r.company_id = :company_id
             GROUP  BY r.id
             ORDER  BY r.is_system_role DESC, r.name',
            ['company_id' => $session->company->id],
        );

        return $this->render('admin/roles/index.html.twig', [
            'session' => $session,
            'roles'   => $roles,
            'can'     => [
                'create' => $this->can->check($session, 'create_roles'),
                'edit'   => $this->can->check($session, 'edit_roles'),
                'delete' => $this->can->check($session, 'delete_roles'),
                'assign' => $this->can->check($session, 'assign_permissions'),
            ],
        ]);
    }

    // =========================================================================
    // GET /dashboard/admin/roles/{id} — Role detail with permissions (Twig)
    // =========================================================================

    #[Route('/{id}', name: 'admin_roles_show', methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_roles');
        if ($session instanceof Response) return $session;

        $role = $this->db->fetchAssociative(
            'SELECT * FROM roles WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$role) {
            throw $this->createNotFoundException('Role not found.');
        }

        $assigned   = $this->permissions->listByRoleGrouped($id, $session->company->id);
        $allGrouped = [];

        foreach ($this->permissions->listAll() as $p) {
            $allGrouped[$p['category']][] = $p;
        }

        // Build flat array of assigned permission IDs
        $assignedIds = [];
        foreach ($assigned as $perms) {
            foreach ($perms as $p) {
                $assignedIds[] = (int) $p['permission_id'];
            }
        }

        // Build role_permission_id map: permission_id => role_permission_id
        $rolePermissionMap = [];
        foreach ($assigned as $perms) {
            foreach ($perms as $p) {
                $rolePermissionMap[(int) $p['permission_id']] = (int) $p['role_permission_id'];
            }
        }

        // Fetch all constraints with their permission links
        // Only for permissions assigned to this role
        $constraintData = [];
        if (!empty($assignedIds)) {
            $placeholders = implode(',', array_fill(0, count($assignedIds), '?'));
            $rows = $this->db->fetchAllAssociative(
                "SELECT
                    pc.permission_id,
                    pc.is_required,
                    pc.default_value,
                    c.id            AS constraint_id,
                    c.name          AS constraint_name,
                    c.constraint_key,
                    c.constraint_type,
                    c.description   AS constraint_description,
                    rpc.id          AS role_constraint_id,
                    rpc.constraint_value
                 FROM permission_constraints pc
                 JOIN constraints c ON c.id = pc.constraint_id
                 LEFT JOIN role_permission_constraints rpc
                    ON rpc.constraint_id = pc.constraint_id
                    AND rpc.role_permission_id = (
                        SELECT rp.id FROM role_permissions rp
                        WHERE rp.role_id = ?
                        AND rp.permission_id = pc.permission_id
                        LIMIT 1
                    )
                 WHERE pc.permission_id IN ($placeholders)
                 ORDER BY pc.permission_id, c.name",
                array_merge([$id], $assignedIds),
            );

            // Group by permission_id
            foreach ($rows as $row) {
                $constraintData[(int) $row['permission_id']][] = $row;
            }
        }

        // Fetch permission names for constraint tab display
        $permissionNames = [];
        foreach ($this->permissions->listAll() as $p) {
            $permissionNames[(int) $p['id']] = $p['name'];
        }

        // All available constraints for the add form
        $allConstraints = $this->db->fetchAllAssociative(
            'SELECT * FROM constraints ORDER BY name'
        );

        // Active MPesa shortcodes for this company — used by the allowed_shortcodes constraint UI
        $mpesaShortcodes = $this->db->fetchAllAssociative(
            'SELECT shortcode, account_name, type
             FROM   mpesa_configs
             WHERE  company_id = :company_id
               AND  is_active  = 1
             ORDER  BY account_name',
            ['company_id' => $session->company->id],
        );

        return $this->render('admin/roles/show.html.twig', [
            'session'           => $session,
            'role'              => $role,
            'assigned'          => $assigned,
            'all'               => $allGrouped,
            'assignedIds'       => $assignedIds,
            'rolePermissionMap' => $rolePermissionMap,
            'constraintData'    => $constraintData,
            'permissionNames'   => $permissionNames,
            'allConstraints'    => $allConstraints,
            'mpesaShortcodes'   => $mpesaShortcodes,
            'can'               => [
                'edit'   => $this->can->check($session, 'edit_roles'),
                'assign' => $this->can->check($session, 'assign_permissions'),
            ],
        ]);
    }

    // =========================================================================
    // POST /dashboard/admin/roles/create — Create (fetch)
    // =========================================================================

    #[Route('/create', name: 'admin_roles_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'create_roles');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $name        = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));

        if ($name === '') {
            return $this->error('Role name is required.');
        }

        $exists = $this->db->fetchOne(
            'SELECT id FROM roles WHERE name = :name AND company_id = :company_id',
            ['name' => $name, 'company_id' => $session->company->id],
        );

        if ($exists) {
            return $this->error('A role with this name already exists.');
        }

        $this->db->insert('roles', [
            'company_id'     => $session->company->id,
            'name'           => $name,
            'description'    => $description ?: null,
            'is_system_role' => 0,
        ]);

        $roleId = (int) $this->db->lastInsertId();

        return $this->success('Role created successfully.', ['id' => $roleId]);
    }

    // =========================================================================
    // POST /dashboard/admin/roles/{id}/update — Update (fetch)
    // =========================================================================

    #[Route('/{id}/update', name: 'admin_roles_update', methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_roles');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $role = $this->db->fetchAssociative(
            'SELECT * FROM roles WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$role) return $this->error('Role not found.', 404);
        if ($role['is_system_role']) return $this->error('System roles cannot be edited.');

        $name        = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));

        if ($name === '') {
            return $this->error('Role name is required.');
        }

        $conflict = $this->db->fetchOne(
            'SELECT id FROM roles WHERE name = :name AND company_id = :company_id AND id != :id',
            ['name' => $name, 'company_id' => $session->company->id, 'id' => $id],
        );

        if ($conflict) {
            return $this->error('Another role with this name already exists.');
        }

        $this->db->update('roles', [
            'name'        => $name,
            'description' => $description ?: null,
        ], ['id' => $id]);

        return $this->success('Role updated successfully.');
    }

    // =========================================================================
    // POST /dashboard/admin/roles/{id}/delete — Delete (fetch)
    // =========================================================================

    #[Route('/{id}/delete', name: 'admin_roles_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'delete_roles');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $role = $this->db->fetchAssociative(
            'SELECT * FROM roles WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$role) return $this->error('Role not found.', 404);
        if ($role['is_system_role']) return $this->error('System roles cannot be deleted.');

        // Remove all permission assignments first
        $this->permissions->revokeAllPermissions($session, $id, $session->company->id);

        // Remove role from all users
        $this->db->executeStatement('DELETE FROM user_roles WHERE role_id = :id', ['id' => $id]);

        // Delete role
        $this->db->executeStatement(
            'DELETE FROM roles WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        return $this->success('Role deleted successfully.');
    }

    // =========================================================================
    // POST /dashboard/admin/roles/{id}/assign-permission — Assign permission (fetch)
    // =========================================================================

    #[Route('/{id}/assign-permission', name: 'admin_roles_assign_permission', methods: ['POST'])]
    public function assignPermission(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'assign_permissions');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $permissionId = (int) $request->request->get('permission_id', 0);
        if ($permissionId === 0) return $this->error('Permission ID is required.');

        $result = $this->permissions->assignPermission($session, $id, $permissionId, $session->company->id);

        return $result->success
            ? $this->success($result->reason, $result->data)
            : $this->error($result->reason);
    }

    // =========================================================================
    // POST /dashboard/admin/roles/{id}/revoke-permission — Revoke permission (fetch)
    // =========================================================================

    #[Route('/{id}/revoke-permission', name: 'admin_roles_revoke_permission', methods: ['POST'])]
    public function revokePermission(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'assign_permissions');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $permissionId = (int) $request->request->get('permission_id', 0);
        if ($permissionId === 0) return $this->error('Permission ID is required.');

        $result = $this->permissions->revokePermission($session, $id, $permissionId, $session->company->id);

        return $result->success
            ? $this->success($result->reason)
            : $this->error($result->reason);
    }

    // =========================================================================
    // POST /dashboard/admin/roles/{id}/set-constraint — Set constraint (fetch)
    // =========================================================================

    #[Route('/{id}/set-constraint', name: 'admin_roles_set_constraint', methods: ['POST'])]
    public function setConstraint(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'assign_permissions');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $rolePermissionId = (int) $request->request->get('role_permission_id', 0);
        $key              = trim((string) $request->request->get('key', ''));
        $value            = trim((string) $request->request->get('value', ''));

        if ($rolePermissionId === 0) return $this->error('Role permission ID is required.');
        if ($key === '') return $this->error('Constraint key is required.');

        $result = $this->permissions->setConstraint($session, $rolePermissionId, $key, $value, $session->company->id);

        return $result->success
            ? $this->success($result->reason, $result->data)
            : $this->error($result->reason);
    }

    // =========================================================================
    // POST /dashboard/admin/roles/{id}/remove-constraint — Remove constraint (fetch)
    // =========================================================================

    #[Route('/{id}/remove-constraint', name: 'admin_roles_remove_constraint', methods: ['POST'])]
    public function removeConstraint(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'assign_permissions');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $rolePermissionId = (int) $request->request->get('role_permission_id', 0);
        $key              = trim((string) $request->request->get('key', ''));

        if ($rolePermissionId === 0) return $this->error('Role permission ID is required.');
        if ($key === '') return $this->error('Constraint key is required.');

        $result = $this->permissions->removeConstraint($session, $rolePermissionId, $key, $session->company->id);

        return $result->success
            ? $this->success($result->reason)
            : $this->error($result->reason);
    }
}