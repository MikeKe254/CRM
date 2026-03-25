<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchPermissionService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/roles', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class RoleController extends AdminBaseController
{
    private const PROTECTED_SYSTEM_ROLE_NAMES = [
        'Assistant Manager',
        'Department Manager',
        'Supervisor',
        'Support Functions',
    ];

    public function __construct(
        AuthService                      $auth,
        CheckPermissionService           $can,
        PlatformCheckPermissionService   $platformCan,
        BranchResolverService            $branchResolver,
        Connection                       $db,
        private readonly BranchPermissionService $branchPermissions,
        private readonly PermissionService $permissions,
        private readonly UserActivityLogService $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // GET /dashboard/admin/roles — List (Twig)
    // =========================================================================

    #[Route('', name: 'admin_roles', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_roles');
        if ($session instanceof Response) return $session;

        $showDeleted = $session->user->isSuperAdmin
            && ($this->platformCan->isPlatformOwner($session) || $this->platformCan->check($session, 'view_deleted_entries'));

        $deletedFilter = $showDeleted ? '' : 'AND r.deleted_at IS NULL';
        $allowedScopes = $this->getAllowedRoleScopes($session);
        $canCreateRoles = $this->can->check($session, 'create_roles') && !empty($allowedScopes);
        $canEditRoles = $this->can->check($session, 'edit_roles');
        $canDeleteRoles = $this->can->check($session, 'delete_roles');
        $multiBranchEnabled = $this->isMultiBranchEnabled($session->company->id);
        $canViewLeadershipRoles = $this->canViewLeadershipRoles($session);

        // Branch context:  show only that node's own role copies (independent per branch)
        // HQ / no-context: show company-wide templates (branch_id IS NULL)
        $isHq = $session->branch !== null && $session->branch->isHq;

        if ($isHq || $session->branch === null) {
            $branchFilter = 'AND r.branch_id IS NULL';
            $branchParam  = [];
        } else {
            $branchFilter = $multiBranchEnabled
                ? 'AND r.branch_id = :branch_id'
                : "AND (
                        r.branch_id = :branch_id
                        OR (
                            r.branch_id IS NULL
                            AND r.is_head_role = 1
                            AND (
                                (rh.scope = 'branch' OR (rh.scope IS NULL AND r.scope = 'branch'))
                                OR (
                                    :can_view_leadership_roles = 1
                                    AND r.name IN ('Owner', 'Director')
                                )
                            )
                        )
                    )";
            $branchParam  = [
                'branch_id' => $session->branch->id,
                'can_view_leadership_roles' => $canViewLeadershipRoles ? 1 : 0,
            ];
        }

        $roles = $this->db->fetchAllAssociative(
            "SELECT r.id, r.branch_id, r.name, r.description, r.is_system_role, r.is_head_role,
                    COALESCE(rh.scope, r.scope) AS scope, r.deleted_at,
                    COUNT(rp.id) AS permission_count,
                    rh.parent_role_id, rh.level,
                    parent_role.name AS parent_role_name
             FROM   roles r
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN role_hierarchy rh ON rh.role_id = r.id
             LEFT JOIN roles parent_role ON parent_role.id = rh.parent_role_id
             WHERE  r.company_id = :company_id
               {$branchFilter}
               {$deletedFilter}
             GROUP  BY r.id
             ORDER  BY COALESCE(rh.level, -1) DESC, r.is_head_role DESC, r.scope ASC, r.name",
            array_merge(['company_id' => $session->company->id], $branchParam),
        );

        if (!$multiBranchEnabled) {
            $roles = array_values(array_filter(
                $roles,
                fn (array $role) => !$this->isHiddenWhenMultiBranchDisabled((string) ($role['name'] ?? ''))
            ));
        }

        foreach ($roles as &$role) {
            $role['can_edit'] = $canEditRoles && $this->canManageRoleLifecycle($session, $role);
            $role['can_delete'] = $canDeleteRoles && $this->canManageRoleLifecycle($session, $role);
        }
        unset($role);

        $this->activityLog->record($session, 'role.view', request: $request);

        return $this->render('admin/roles/index.html.twig', [
            'session'     => $session,
            'roles'       => $roles,
            'showDeleted' => $showDeleted,
            'allowedScopes' => $allowedScopes,
            'can'         => [
                'create' => $canCreateRoles,
                'edit'   => $canEditRoles,
                'delete' => $canDeleteRoles,
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

        // Base role fetch — company ownership check first.
        $role = $this->db->fetchAssociative(
            'SELECT * FROM roles WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$role) {
            throw $this->createNotFoundException('Role not found.');
        }

        if (
            !$this->isMultiBranchEnabled($session->company->id)
            && $this->isHiddenWhenMultiBranchDisabled((string) ($role['name'] ?? ''))
        ) {
            throw $this->createNotFoundException('Role not found.');
        }

        // Branch context scoping — non-superadmin actors cannot reach role pages
        // that do not belong to their current node context:
        //   HQ context        → may only view company-wide templates (branch_id IS NULL)
        //   Branch/region ctx → may only view that node's own role copies
        //                       (head roles are also readable since they are company-wide)
        if (!$session->user->isSuperAdmin && $session->branch !== null) {
            $isHq = $session->branch->isHq;
            if ($isHq) {
                // HQ actors see templates only
                if ($role['branch_id'] !== null && !(bool) $role['is_head_role']) {
                    throw $this->createNotFoundException('Role not found.');
                }
            } else {
                $multiBranchEnabled = $this->isMultiBranchEnabled($session->company->id);
                // Branch/region actors: must be this node's copy OR a visible head role
                $belongsHere = (int) ($role['branch_id'] ?? 0) === $session->branch->id;
                $isHeadRole  = (bool) $role['is_head_role'];
                $visibleSingleBranchHeadRole = !$multiBranchEnabled
                    && $isHeadRole
                    && ($role['branch_id'] === null)
                    && (
                        (($role['scope'] ?? 'branch') === 'branch')
                        || ($this->canViewLeadershipRoles($session) && in_array((string) $role['name'], ['Owner', 'Director'], true))
                    );
                if (!$belongsHere && !$visibleSingleBranchHeadRole) {
                    throw $this->createNotFoundException('Role not found.');
                }
            }
        }

        $assigned   = $this->permissions->listByRoleGrouped($id, $session->company->id);
        $allGrouped = [];

        foreach ($this->permissions->listVisibleToActor($session, $session->company->id) as $p) {
            $allGrouped[$p['category']][] = $p;
        }

        // Only expose assigned permissions that are in the actor's visible catalog.
        $visiblePermissionIds = [];
        foreach ($allGrouped as $perms) {
            foreach ($perms as $p) {
                $visiblePermissionIds[(int) $p['id']] = true;
            }
        }

        // Build flat array of assigned permission IDs
        $assignedIds = [];
        foreach ($assigned as $perms) {
            foreach ($perms as $p) {
                $permissionId = (int) $p['permission_id'];
                if (isset($visiblePermissionIds[$permissionId])) {
                    $assignedIds[] = $permissionId;
                }
            }
        }

        // Build role_permission_id map: permission_id => role_permission_id
        $rolePermissionMap = [];
        foreach ($assigned as $perms) {
            foreach ($perms as $p) {
                $permissionId = (int) $p['permission_id'];
                if (isset($visiblePermissionIds[$permissionId])) {
                    $rolePermissionMap[$permissionId] = (int) $p['role_permission_id'];
                }
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
        foreach ($this->permissions->listVisibleToActor($session, $session->company->id) as $p) {
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

        $this->activityLog->record($session, 'role.show',
            ['name' => $role['name']],
            subjectType: 'role', subjectId: $id, request: $request,
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
                'assign' => $this->permissions->canManageRolePermissions($session, $id, $session->company->id),
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
        if ($this->isReservedSystemRoleName($name)) {
            return $this->error('This role name is reserved for a system-defined role and cannot be created manually.');
        }

        $exists = $this->db->fetchOne(
            'SELECT id FROM roles WHERE name = :name AND company_id = :company_id AND deleted_at IS NULL',
            ['name' => $name, 'company_id' => $session->company->id],
        );

        if ($exists) {
            return $this->error('A role with this name already exists.');
        }

        // Custom roles created in a branch context belong to that branch only.
        // Custom roles created at HQ are company-wide templates.
        $isHq     = $session->branch !== null && $session->branch->isHq;
        $branchId = (!$isHq && $session->branch !== null) ? $session->branch->id : null;

        // Branch-level roles are always branch-scoped; ignore client value.
        if ($branchId !== null) {
            $scope = 'branch';
        } else {
            $scope = $request->request->get('scope', 'any');
            if (!in_array($scope, ['any', 'hq', 'region', 'branch'], true)) {
                $scope = 'any';
            }
            if (!in_array($scope, $this->getAllowedRoleScopes($session), true)) {
                return $this->error('You cannot create a role with that scope from your current hierarchy level.', 403);
            }
        }

        $this->db->insert('roles', [
            'company_id'     => $session->company->id,
            'branch_id'      => $branchId,
            'name'           => $name,
            'description'    => $description ?: null,
            'is_system_role' => 0,
            'is_head_role'   => 0,
            'scope'          => $scope,
        ]);

        $roleId = (int) $this->db->lastInsertId();

        $this->activityLog->record($session, 'role.create',
            ['name' => $name],
            permission: 'create_roles', subjectType: 'role', subjectId: $roleId, request: $request,
        );

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
            'SELECT * FROM roles WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$role) return $this->error('Role not found.', 404);
        if ($role['is_head_role']) return $this->error('Head roles cannot be edited.');
        if ($this->isProtectedSystemRole($role)) {
            return $this->error('This system role cannot be edited.');
        }
        if (!$this->canManageRoleLifecycle($session, $role)) {
            return $this->error('You cannot edit a role at that scope from your current hierarchy level.', 403);
        }

        // Branch scope: ensure the role belongs to the actor's current context
        if (!$session->user->isSuperAdmin && $session->branch !== null) {
            $isHqCtx = $session->branch->isHq || ($session->context ?? 'operational') === 'overall';
            if ($isHqCtx) {
                if ($role['branch_id'] !== null) return $this->error('Role not found.', 404);
            } else {
                if ((int) ($role['branch_id'] ?? 0) !== $session->branch->id) {
                    return $this->error('Role not found.', 404);
                }
            }
        }

        $name        = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));

        if ($name === '') {
            return $this->error('Role name is required.');
        }
        if ($this->isReservedSystemRoleName($name) && strcasecmp((string) $role['name'], $name) !== 0) {
            return $this->error('This role name is reserved for a system-defined role and cannot be used here.');
        }

        $conflict = $this->db->fetchOne(
            'SELECT id FROM roles WHERE name = :name AND company_id = :company_id AND id != :id AND deleted_at IS NULL',
            ['name' => $name, 'company_id' => $session->company->id, 'id' => $id],
        );

        if ($conflict) {
            return $this->error('Another role with this name already exists.');
        }

        // Branch-scoped roles must remain branch-scoped; only HQ templates allow scope changes.
        if ($role['branch_id'] !== null) {
            $scope = 'branch';
        } else {
            $scope = $request->request->get('scope', $role['scope'] ?? 'any');
            if (!in_array($scope, ['any', 'hq', 'region', 'branch'], true)) {
                $scope = 'any';
            }
            if (!in_array($scope, $this->getAllowedRoleScopes($session), true)) {
                return $this->error('You cannot set that scope from your current hierarchy level.', 403);
            }
        }

        $this->db->update('roles', [
            'name'        => $name,
            'description' => $description ?: null,
            'scope'       => $scope,
        ], ['id' => $id]);

        $this->activityLog->record($session, 'role.update',
            ['name' => $name],
            permission: 'edit_roles', subjectType: 'role', subjectId: $id, request: $request,
        );

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
            'SELECT * FROM roles WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$role) return $this->error('Role not found.', 404);
        if ($role['is_head_role']) return $this->error('Head roles cannot be deleted.');
        if ($this->isProtectedSystemRole($role)) return $this->error('This system role cannot be deleted.');
        if (!$this->canManageRoleLifecycle($session, $role)) {
            return $this->error('You cannot delete a role at that scope from your current hierarchy level.', 403);
        }

        // Branch scope: ensure the role belongs to the actor's current context
        if (!$session->user->isSuperAdmin && $session->branch !== null) {
            $isHqCtx = $session->branch->isHq || ($session->context ?? 'operational') === 'overall';
            if ($isHqCtx) {
                if ($role['branch_id'] !== null) return $this->error('Role not found.', 404);
            } else {
                if ((int) ($role['branch_id'] ?? 0) !== $session->branch->id) {
                    return $this->error('Role not found.', 404);
                }
            }
        }

        // Remove all permission assignments first
        $this->permissions->revokeAllPermissions($session, $id, $session->company->id);

        // Remove role from all users (both legacy and branch-aware tables)
        $this->db->executeStatement('DELETE FROM user_roles WHERE role_id = :id', ['id' => $id]);
        $this->db->executeStatement('DELETE FROM user_node_roles WHERE role_id = :id', ['id' => $id]);

        // Soft-delete role
        $this->db->executeStatement(
            'UPDATE roles SET deleted_at = NOW() WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        $this->activityLog->record($session, 'role.delete',
            ['name' => $role['name']],
            permission: 'delete_roles', subjectType: 'role', subjectId: $id, request: $request,
        );

        return $this->success('Role deleted successfully.');
    }

    private function isReservedSystemRoleName(string $name): bool
    {
        return in_array(mb_strtolower($name), array_map('mb_strtolower', self::PROTECTED_SYSTEM_ROLE_NAMES), true);
    }

    private function isProtectedSystemRole(array $role): bool
    {
        $name = trim((string) ($role['name'] ?? ''));
        return $name !== '' && $this->isReservedSystemRoleName($name);
    }

    private function getAllowedRoleScopes(mixed $session): array
    {
        if ($session->user->isSuperAdmin) {
            return ['any', 'hq', 'region', 'branch'];
        }

        if ($session->branch === null) {
            return [];
        }

        if (!$session->branch->isHq && ($session->context ?? 'operational') !== 'overall') {
            return ['branch'];
        }

        $roleIds = $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id);
        if (empty($roleIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $roleNames = array_map(
            'strtolower',
            $this->db->fetchFirstColumn(
                "SELECT name FROM roles WHERE id IN ({$placeholders}) AND company_id = ? AND deleted_at IS NULL",
                array_merge($roleIds, [$session->company->id]),
            ),
        );

        if (array_intersect($roleNames, ['owner', 'director'])) {
            return ['any', 'hq', 'region', 'branch'];
        }

        if (in_array('overall manager', $roleNames, true)) {
            return ['region', 'branch'];
        }

        if (in_array('regional manager', $roleNames, true)) {
            return ['region', 'branch'];
        }

        return ['branch'];
    }

    private function canManageRoleLifecycle(mixed $session, array $role): bool
    {
        if ($session->user->isSuperAdmin) {
            return true;
        }

        if ($session->branch === null) {
            return false;
        }

        if ($role['branch_id'] !== null) {
            return (int) $role['branch_id'] === $session->branch->id;
        }

        return in_array((string) ($role['scope'] ?? 'branch'), $this->getAllowedRoleScopes($session), true);
    }

    private function canViewLeadershipRoles(mixed $session): bool
    {
        if ($session->user->isSuperAdmin) {
            return true;
        }

        if ($session->branch === null) {
            return false;
        }

        $roleIds = $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id);
        if (empty($roleIds)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $roleNames = array_map(
            'strtolower',
            $this->db->fetchFirstColumn(
                "SELECT name
                   FROM roles
                  WHERE id IN ({$placeholders})
                    AND company_id = ?
                    AND deleted_at IS NULL",
                array_merge($roleIds, [$session->company->id]),
            ),
        );

        return (bool) array_intersect($roleNames, ['owner', 'director']);
    }

    private function isHiddenWhenMultiBranchDisabled(string $roleName): bool
    {
        return in_array(mb_strtolower(trim($roleName)), ['overall manager', 'regional manager'], true);
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

        $roleName = (string) $this->db->fetchOne(
            'SELECT name FROM roles WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $session->company->id],
        );
        $permName = (string) $this->db->fetchOne(
            'SELECT name FROM permissions WHERE id = :id AND deleted_at IS NULL',
            ['id' => $permissionId],
        );

        $result = $this->permissions->assignPermission($session, $id, $permissionId, $session->company->id);

        if ($result->success) {
            $this->activityLog->record($session, 'user.permission.grant',
                ['role' => $roleName ?: "role #$id", 'permission' => $permName ?: "permission #$permissionId"],
                permission: 'assign_permissions', subjectType: 'role', subjectId: $id, request: $request,
            );
            return $this->success($result->reason, $result->data);
        }

        return $this->error($result->reason);
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

        $roleName = (string) $this->db->fetchOne(
            'SELECT name FROM roles WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $session->company->id],
        );
        $permName = (string) $this->db->fetchOne(
            'SELECT name FROM permissions WHERE id = :id AND deleted_at IS NULL',
            ['id' => $permissionId],
        );

        $result = $this->permissions->revokePermission($session, $id, $permissionId, $session->company->id);

        if ($result->success) {
            $this->activityLog->record($session, 'user.permission.revoke',
                ['role' => $roleName ?: "role #$id", 'permission' => $permName ?: "permission #$permissionId"],
                permission: 'assign_permissions', subjectType: 'role', subjectId: $id, request: $request,
            );
            return $this->success($result->reason);
        }

        return $this->error($result->reason);
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

        $meta = $this->db->fetchAssociative(
            'SELECT r.name AS role_name, p.name AS permission_name, rp.role_id
               FROM role_permissions rp
               JOIN roles r       ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.id = :rp_id AND r.company_id = :company_id',
            ['rp_id' => $rolePermissionId, 'company_id' => $session->company->id],
        );

        $constraintName = (string) $this->db->fetchOne(
            'SELECT name FROM constraints WHERE id = :key OR constraint_key = :key LIMIT 1',
            ['key' => $key],
        );

        $result = $this->permissions->setConstraint($session, $rolePermissionId, $key, $value, $session->company->id);

        if ($result->success) {
            $this->activityLog->record($session, 'role.constraint.set',
                [
                    'role'       => $meta ? $meta['role_name']      : "role_permission #$rolePermissionId",
                    'permission' => $meta ? $meta['permission_name'] : '',
                    'constraint' => $constraintName ?: $key,
                    'value'      => $value !== '' ? $value : '(cleared)',
                ],
                permission: 'assign_permissions',
                subjectType: 'role',
                subjectId: $meta ? (int) $meta['role_id'] : $id,
                request: $request,
            );
            return $this->success($result->reason, $result->data);
        }

        return $this->error($result->reason);
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

        $meta = $this->db->fetchAssociative(
            'SELECT r.name AS role_name, p.name AS permission_name, rp.role_id
               FROM role_permissions rp
               JOIN roles r       ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.id = :rp_id AND r.company_id = :company_id',
            ['rp_id' => $rolePermissionId, 'company_id' => $session->company->id],
        );

        $constraintName = (string) $this->db->fetchOne(
            'SELECT name FROM constraints WHERE id = :key OR constraint_key = :key LIMIT 1',
            ['key' => $key],
        );

        $result = $this->permissions->removeConstraint($session, $rolePermissionId, $key, $session->company->id);

        if ($result->success) {
            $this->activityLog->record($session, 'role.constraint.remove',
                [
                    'role'       => $meta ? $meta['role_name']      : "role_permission #$rolePermissionId",
                    'permission' => $meta ? $meta['permission_name'] : '',
                    'constraint' => $constraintName ?: $key,
                ],
                permission: 'assign_permissions',
                subjectType: 'role',
                subjectId: $meta ? (int) $meta['role_id'] : $id,
                request: $request,
            );
            return $this->success($result->reason);
        }

        return $this->error($result->reason);
    }
}
