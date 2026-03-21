<?php

declare(strict_types=1);

namespace App\Services\Permission;

use App\Services\Auth\DTO\AuthResult;
use App\Services\Permission\DTO\PermissionResult;
use Doctrine\DBAL\Connection;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║              Angavu Permission Management Service               ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Handles:                                                        ║
 * ║   • Assigning permissions to roles                               ║
 * ║   • Revoking permissions from roles                              ║
 * ║   • Listing permissions (all / by role / by category)            ║
 * ║   • Managing constraints (set, update, remove)                   ║
 * ║                                                                  ║
 * ║  Guardrails:                                                     ║
 * ║   • Only super admins or users with assign_permissions can act   ║
 * ║   • System roles (is_system_role=1) cannot be modified           ║
 * ║   • Permissions must exist before assignment                     ║
 * ║   • Duplicate assignments are silently skipped                   ║
 * ║   • Every change is logged to user_logs                          ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Inject into any controller:                                     ║
 * ║                                                                  ║
 * ║    public function __construct(                                  ║
 * ║        private readonly PermissionService $permissions           ║
 * ║    ) {}                                                          ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
final class PermissionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PlatformCheckPermissionService $platformCan,
    ) {}

    // =========================================================================
    // LIST
    // =========================================================================

    /**
     * List every permission in the system, optionally filtered by category.
     * No authorization required — this is a read-only global list.
     *
     * @param string|null $category       Filter by category slug (e.g. 'dashboard', 'stk')
     * @param bool        $includeDeleted When true, soft-deleted permissions are included too
     */
    public function listAll(?string $category = null, bool $includeDeleted = false): array
    {
        $deletedClause = $includeDeleted ? '' : 'AND deleted_at IS NULL';

        if ($category !== null) {
            return $this->db->fetchAllAssociative(
                "SELECT * FROM permissions WHERE category = :category {$deletedClause} ORDER BY category, name",
                ['category' => $category],
            );
        }

        return $this->db->fetchAllAssociative(
            "SELECT * FROM permissions WHERE 1=1 {$deletedClause} ORDER BY category, name",
        );
    }

    /**
     * List all permissions assigned to a specific role within a tenant,
     * including any constraints attached to each role_permission.
     *
     * @param int $roleId
     * @param int $companyId
     */
    public function listByRole(int $roleId, int $companyId): array
    {
        // Verify the role belongs to this company
        $role = $this->getRoleOrNull($roleId, $companyId);
        if (!$role) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT
                rp.id          AS role_permission_id,
                p.id           AS permission_id,
                p.name,
                p.category,
                p.description,
                p.action_key
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id
             ORDER BY p.category, p.name',
            ['role_id' => $roleId],
        );

        // Attach constraints to each permission
        foreach ($rows as &$row) {
            $row['constraints'] = $this->getConstraints((int) $row['role_permission_id']);
        }

        return $rows;
    }

    /**
     * List all permissions grouped by category for a role.
     * Useful for building permission management UIs.
     */
    public function listByRoleGrouped(int $roleId, int $companyId): array
    {
        $flat    = $this->listByRole($roleId, $companyId);
        $grouped = [];

        foreach ($flat as $permission) {
            $grouped[$permission['category']][] = $permission;
        }

        return $grouped;
    }

    /**
     * List all available permission categories.
     */
    public function listCategories(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT DISTINCT category FROM permissions WHERE deleted_at IS NULL ORDER BY category',
        );

        return array_column($rows, 'category');
    }

    // =========================================================================
    // ASSIGN
    // =========================================================================

    /**
     * Assign a permission to a role.
     *
     * @param AuthResult $actor        The user performing this action
     * @param int        $roleId       The role to assign to
     * @param int        $permissionId
     * @param int        $companyId    Tenant scope
     */
    public function assignPermission(
        AuthResult $actor,
        int        $roleId,
        int        $permissionId,
        int        $companyId,
    ): PermissionResult {
        // ── Authorization ────────────────────────────────────────────────────
        $authCheck = $this->assertCanManage($actor, $companyId);
        if (!$authCheck->success) {
            return $authCheck;
        }

        // ── Role exists + belongs to tenant ──────────────────────────────────
        $role = $this->getRoleOrNull($roleId, $companyId);
        if (!$role) {
            return PermissionResult::fail('Role not found in this company.', 404);
        }

        // ── System role guard ─────────────────────────────────────────────────
        if ($this->isSystemRole($role) && !$this->platformCan->isPlatformAdminSession($actor)) {
            return PermissionResult::fail(
                "System role '{$role['name']}' cannot be modified by tenant users.",
                403,
            );
        }

        // ── Permission exists ─────────────────────────────────────────────────
        $permission = $this->getPermissionOrNull($permissionId);
        if (!$permission) {
            return PermissionResult::fail('Permission not found.', 404);
        }

        // ── Ownership check — cannot assign a permission you don't have ───────
        // Super admins bypass this; regular users can only delegate what they own.
        if (
            !$this->platformCan->isPlatformAdminSession($actor)
            && !$this->actorHasPermission($actor, $permissionId, $companyId)
        ) {
            return PermissionResult::fail(
                "You cannot assign the '{$permission['name']}' permission because you don't have it yourself.",
                403,
            );
        }

        // ── Duplicate check ───────────────────────────────────────────────────
        $existing = $this->db->fetchOne(
            'SELECT id FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
            ['role_id' => $roleId, 'permission_id' => $permissionId],
        );

        if ($existing) {
            return PermissionResult::ok(
                "Permission '{$permission['name']}' is already assigned to role '{$role['name']}'.",
                ['role_permission_id' => (int) $existing],
            );
        }

        // ── Insert ────────────────────────────────────────────────────────────
        $this->db->insert('role_permissions', [
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
        ]);

        $rolePermissionId = (int) $this->db->lastInsertId();

        // ── Audit log ─────────────────────────────────────────────────────────
        $this->log(
            actor:        $actor,
            companyId:    $companyId,
            permissionId: $permissionId,
            action:       'ASSIGN_PERMISSION',
            targetTable:  'role_permissions',
            targetId:     $rolePermissionId,
            description:  "Assigned permission '{$permission['name']}' to role '{$role['name']}'.",
        );

        return PermissionResult::ok(
            "Permission '{$permission['name']}' assigned to role '{$role['name']}'.",
            ['role_permission_id' => $rolePermissionId],
        );
    }

    /**
     * Assign multiple permissions to a role in one call.
     * Returns a summary of each assignment result.
     */
    public function assignPermissions(
        AuthResult $actor,
        int        $roleId,
        array      $permissionIds,
        int        $companyId,
    ): array {
        $results = [];
        foreach ($permissionIds as $permissionId) {
            $results[$permissionId] = $this->assignPermission(
                $actor,
                $roleId,
                (int) $permissionId,
                $companyId,
            )->toArray();
        }

        return $results;
    }

    // =========================================================================
    // REVOKE
    // =========================================================================

    /**
     * Revoke a permission from a role.
     * Also removes all constraints attached to that role_permission.
     */
    public function revokePermission(
        AuthResult $actor,
        int        $roleId,
        int        $permissionId,
        int        $companyId,
    ): PermissionResult {
        // ── Authorization ─────────────────────────────────────────────────────
        $authCheck = $this->assertCanManage($actor, $companyId);
        if (!$authCheck->success) {
            return $authCheck;
        }

        // ── Role guard ────────────────────────────────────────────────────────
        $role = $this->getRoleOrNull($roleId, $companyId);
        if (!$role) {
            return PermissionResult::fail('Role not found in this company.', 404);
        }

        if ($this->isSystemRole($role) && !$this->platformCan->isPlatformAdminSession($actor)) {
            return PermissionResult::fail(
                "System role '{$role['name']}' cannot be modified by tenant users.",
                403,
            );
        }

        // ── Find role_permission ──────────────────────────────────────────────
        $rolePermission = $this->db->fetchAssociative(
            'SELECT rp.id, p.name AS permission_name
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id AND rp.permission_id = :permission_id',
            ['role_id' => $roleId, 'permission_id' => $permissionId],
        );

        if (!$rolePermission) {
            return PermissionResult::fail(
                'This permission is not assigned to the role.',
                404,
            );
        }

        // ── Ownership check — cannot revoke a permission you don't have ───────
        if (
            !$this->platformCan->isPlatformAdminSession($actor)
            && !$this->actorHasPermission($actor, $permissionId, $companyId)
        ) {
            return PermissionResult::fail(
                "You cannot revoke the '{$rolePermission['permission_name']}' permission because you don't have it yourself.",
                403,
            );
        }

        // ── Own-role unassignment guard ───────────────────────────────────────
        // You cannot remove a permission from a role you are a member of —
        // you would lose it and cannot reassign it to yourself (self-role-assignment is blocked).
        if (!$this->platformCan->isPlatformAdminSession($actor)) {
            $actorInRole = (bool) $this->db->fetchOne(
                'SELECT 1 FROM user_roles WHERE user_id = :user_id AND role_id = :role_id LIMIT 1',
                ['user_id' => $actor->user->id, 'role_id' => $roleId],
            );
            if ($actorInRole) {
                return PermissionResult::fail(
                    'You cannot remove a permission from a role you are a member of.',
                    403,
                );
            }
        }

        $rolePermissionId = (int) $rolePermission['id'];

        // ── Delete constraints first (FK safety) ──────────────────────────────
        $this->db->executeStatement(
            'DELETE FROM role_permission_constraints WHERE role_permission_id = :id',
            ['id' => $rolePermissionId],
        );

        // ── Delete role_permission ─────────────────────────────────────────────
        $this->db->executeStatement(
            'DELETE FROM role_permissions WHERE id = :id',
            ['id' => $rolePermissionId],
        );

        // ── Audit log ──────────────────────────────────────────────────────────
        $this->log(
            actor:        $actor,
            companyId:    $companyId,
            permissionId: $permissionId,
            action:       'REVOKE_PERMISSION',
            targetTable:  'role_permissions',
            targetId:     $rolePermissionId,
            description:  "Revoked permission '{$rolePermission['permission_name']}' from role '{$role['name']}'.",
        );

        return PermissionResult::ok(
            "Permission '{$rolePermission['permission_name']}' revoked from role '{$role['name']}'.",
        );
    }

    /**
     * Revoke all permissions from a role in one call.
     */
    public function revokeAllPermissions(
        AuthResult $actor,
        int        $roleId,
        int        $companyId,
    ): PermissionResult {
        $authCheck = $this->assertCanManage($actor, $companyId);
        if (!$authCheck->success) {
            return $authCheck;
        }

        $role = $this->getRoleOrNull($roleId, $companyId);
        if (!$role) {
            return PermissionResult::fail('Role not found in this company.', 404);
        }

        if ($this->isSystemRole($role) && !$this->platformCan->isPlatformAdminSession($actor)) {
            return PermissionResult::fail(
                "System role '{$role['name']}' cannot be modified by tenant users.",
                403,
            );
        }

        // Own-role unassignment guard: cannot wipe permissions from a role you are in
        if (!$this->platformCan->isPlatformAdminSession($actor)) {
            $actorInRole = (bool) $this->db->fetchOne(
                'SELECT 1 FROM user_roles WHERE user_id = :user_id AND role_id = :role_id LIMIT 1',
                ['user_id' => $actor->user->id, 'role_id' => $roleId],
            );
            if ($actorInRole) {
                return PermissionResult::fail(
                    'You cannot remove permissions from a role you are a member of.',
                    403,
                );
            }
        }

        // Delete all constraints for this role's permissions first
        $this->db->executeStatement(
            'DELETE rpc FROM role_permission_constraints rpc
             JOIN role_permissions rp ON rp.id = rpc.role_permission_id
             WHERE rp.role_id = :role_id',
            ['role_id' => $roleId],
        );

        $count = (int) $this->db->executeStatement(
            'DELETE FROM role_permissions WHERE role_id = :role_id',
            ['role_id' => $roleId],
        );

        $this->log(
            actor:        $actor,
            companyId:    $companyId,
            permissionId: null,
            action:       'REVOKE_ALL_PERMISSIONS',
            targetTable:  'role_permissions',
            targetId:     $roleId,
            description:  "Revoked all {$count} permissions from role '{$role['name']}'.",
        );

        return PermissionResult::ok(
            "All {$count} permissions revoked from role '{$role['name']}'.",
            ['revoked_count' => $count],
        );
    }

    // =========================================================================
    // CONSTRAINTS
    // =========================================================================

    /**
     * Set (upsert) a constraint on a role_permission.
     *
     * Example:
     *   setConstraint($actor, 94, 'max_hours_history', '48', 1)
     *
     * @param int    $rolePermissionId  The role_permissions.id
     * @param string $key               e.g. 'max_hours_history'
     * @param string $value             e.g. '48'
     */
    public function setConstraint(
        AuthResult $actor,
        int        $rolePermissionId,
        string     $key,
        string     $value,
        int        $companyId,
    ): PermissionResult {
        $authCheck = $this->assertCanManage($actor, $companyId);
        if (!$authCheck->success) {
            return $authCheck;
        }

        // Verify role_permission belongs to this company
        $rolePermission = $this->db->fetchAssociative(
            'SELECT rp.id, r.is_system_role, r.name AS role_name, p.name AS permission_name
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.id = :id AND r.company_id = :company_id',
            ['id' => $rolePermissionId, 'company_id' => $companyId],
        );

        if (!$rolePermission) {
            return PermissionResult::fail('Role permission not found in this company.', 404);
        }

        if (
            (bool) $rolePermission['is_system_role']
            && !$this->platformCan->isPlatformAdminSession($actor)
        ) {
            return PermissionResult::fail(
                "Cannot set constraints on system role '{$rolePermission['role_name']}'.",
                403,
            );
        }

        // Upsert constraint
        $existing = $this->db->fetchOne(
            'SELECT id FROM role_permission_constraints
             WHERE role_permission_id = :rp_id AND constraint_id = :constraint_id',
            ['rp_id' => $rolePermissionId, 'constraint_id' => $key],
        );

        if ($existing) {
            $this->db->executeStatement(
                'UPDATE role_permission_constraints
                 SET constraint_value = :value
                 WHERE id = :id',
                ['value' => $value, 'id' => $existing],
            );
            $action      = 'UPDATE_CONSTRAINT';
            $description = "Updated constraint '{$key}' = '{$value}' on permission '{$rolePermission['permission_name']}' for role '{$rolePermission['role_name']}'.";
        } else {
            $this->db->insert('role_permission_constraints', [
                'role_permission_id' => $rolePermissionId,
                'constraint_id'      => $key,
                'constraint_value'   => $value,
            ]);
            $action      = 'SET_CONSTRAINT';
            $description = "Set constraint '{$key}' = '{$value}' on permission '{$rolePermission['permission_name']}' for role '{$rolePermission['role_name']}'.";
        }

        $this->log(
            actor:        $actor,
            companyId:    $companyId,
            permissionId: null,
            action:       $action,
            targetTable:  'role_permission_constraints',
            targetId:     $rolePermissionId,
            description:  $description,
        );

        return PermissionResult::ok($description, [
            'role_permission_id' => $rolePermissionId,
            'key'                => $key,
            'value'              => $value,
        ]);
    }

    /**
     * Remove a specific constraint from a role_permission.
     */
    public function removeConstraint(
        AuthResult $actor,
        int        $rolePermissionId,
        string     $key,
        int        $companyId,
    ): PermissionResult {
        $authCheck = $this->assertCanManage($actor, $companyId);
        if (!$authCheck->success) {
            return $authCheck;
        }

        $deleted = (int) $this->db->executeStatement(
            'DELETE FROM role_permission_constraints
             WHERE role_permission_id = :rp_id AND constraint_id = :constraint_id',
            ['rp_id' => $rolePermissionId, 'constraint_id' => $key],
        );

        if ($deleted === 0) {
            return PermissionResult::fail("Constraint '{$key}' not found.", 404);
        }

        $this->log(
            actor:        $actor,
            companyId:    $companyId,
            permissionId: null,
            action:       'REMOVE_CONSTRAINT',
            targetTable:  'role_permission_constraints',
            targetId:     $rolePermissionId,
            description:  "Removed constraint '{$key}' from role_permission #{$rolePermissionId}.",
        );

        return PermissionResult::ok("Constraint '{$key}' removed.");
    }

    /**
     * Remove all constraints from a role_permission.
     */
    public function removeAllConstraints(
        AuthResult $actor,
        int        $rolePermissionId,
        int        $companyId,
    ): PermissionResult {
        $authCheck = $this->assertCanManage($actor, $companyId);
        if (!$authCheck->success) {
            return $authCheck;
        }

        $count = (int) $this->db->executeStatement(
            'DELETE FROM role_permission_constraints WHERE role_permission_id = :id',
            ['id' => $rolePermissionId],
        );

        $this->log(
            actor:        $actor,
            companyId:    $companyId,
            permissionId: null,
            action:       'REMOVE_ALL_CONSTRAINTS',
            targetTable:  'role_permission_constraints',
            targetId:     $rolePermissionId,
            description:  "Removed all {$count} constraints from role_permission #{$rolePermissionId}.",
        );

        return PermissionResult::ok("All {$count} constraints removed.", ['removed_count' => $count]);
    }

    /**
     * Get all constraints for a role_permission.
     */
    public function getConstraints(int $rolePermissionId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT rpc.constraint_id, c.constraint_key, rpc.constraint_value
             FROM role_permission_constraints rpc
             JOIN constraints c ON c.id = rpc.constraint_id
             WHERE rpc.role_permission_id = :id',
            ['id' => $rolePermissionId],
        );
    }

    /**
     * Get constraints as a flat key => value map.
     * Much easier to work with in application logic.
     *
     * Example return: ['max_hours_history' => '48', 'allowed_shortcodes' => '174379,123456']
     */
    public function getConstraintsMap(int $rolePermissionId): array
    {
        $rows = $this->getConstraints($rolePermissionId);
        $map  = [];

        foreach ($rows as $row) {
            $map[$row['constraint_key']] = $row['constraint_value'];
        }

        return $map;
    }

    // =========================================================================
    // PRIVATE — AUTHORIZATION
    // =========================================================================

    /**
     * Assert the actor can manage permissions.
     * Allowed if: super admin OR has the ASSIGN_PERMISSIONS action_key.
     *
     * Matches on action_key (stable machine identifier) rather than name
     * (human label that may differ between environments or be title-cased).
     */
    private function assertCanManage(AuthResult $actor, int $companyId): PermissionResult
    {
        if ($this->platformCan->isPlatformAdminSession($actor)) {
            return $this->platformCan->check($actor, 'assign_permissions')
                ? PermissionResult::ok()
                : PermissionResult::fail(
                    'You do not have permission to manage role permissions.',
                    403,
                );
        }

        $hasPermission = $this->db->fetchOne(
            'SELECT rp.id
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id     = :user_id
               AND p.action_key   = :action_key
               AND rp.role_id IN (
                   SELECT id FROM roles WHERE company_id = :company_id
               )',
            [
                'user_id'    => $actor->user->id,
                'action_key' => 'ASSIGN_PERMISSIONS',
                'company_id' => $companyId,
            ],
        );

        if (!$hasPermission) {
            return PermissionResult::fail(
                'You do not have permission to manage role permissions.',
                403,
            );
        }

        return PermissionResult::ok();
    }

    // =========================================================================
    // PRIVATE — DB HELPERS
    // =========================================================================

    private function getRoleOrNull(int $roleId, int $companyId): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT * FROM roles WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $roleId, 'company_id' => $companyId],
        );
    }

    private function getPermissionOrNull(int $permissionId): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT * FROM permissions WHERE id = :id AND deleted_at IS NULL',
            ['id' => $permissionId],
        );
    }

    /**
     * Check whether the actor personally holds a specific permission
     * via any of their own roles within the company.
     *
     * Used to enforce: you can only assign/revoke permissions you yourself have.
     */
    private function actorHasPermission(AuthResult $actor, int $permissionId, int $companyId): bool
    {
        $result = $this->db->fetchOne(
            'SELECT rp.id
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             WHERE ur.user_id      = :user_id
               AND rp.permission_id = :permission_id
               AND rp.role_id IN (
                   SELECT id FROM roles WHERE company_id = :company_id
               )
             LIMIT 1',
            [
                'user_id'       => $actor->user->id,
                'permission_id' => $permissionId,
                'company_id'    => $companyId,
            ],
        );

        return (bool) $result;
    }

    private function isSystemRole(array $role): bool
    {
        return (bool) ($role['is_system_role'] ?? false);
    }

    // =========================================================================
    // PRIVATE — AUDIT LOG
    // =========================================================================

    private function log(
        AuthResult $actor,
        int        $companyId,
        ?int       $permissionId,
        string     $action,
        string     $targetTable,
        int        $targetId,
        string     $description,
    ): void {
        try {
            $this->db->insert('user_logs', [
                'company_id'    => $companyId,
                'user_id'       => $actor->user->id,
                'permission_id' => $permissionId,
                'action'        => $action,
                'target_table'  => $targetTable,
                'target_id'     => $targetId,
                'description'   => $description,
                'ip_address'    => null,
                'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Logging must never break the main flow
        }
    }
}
