<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/platform/access', host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class AccessController extends PlatformBaseController
{
    public function __construct(
        AuthService $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $platformCan);
    }

    // =========================================================================
    // INDEX — tabbed page: Users / Roles / Permissions
    // =========================================================================

    #[Route('', name: 'platform_access', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // null = only validate it's a platform session, we gate each tab individually
        $session = $this->requirePlatform($request, null);
        if ($session instanceof Response) return $session;

        $isOwner            = $this->platformCan->isPlatformOwner($session);
        $callerId           = $session->user->id;

        $canViewUsers       = $this->platformCan->check($session, 'view_users');
        $canViewRoles       = $this->platformCan->check($session, 'view_roles');
        $canViewPermissions = $this->platformCan->check($session, 'view_permissions');

        if (!$canViewUsers && !$canViewRoles && !$canViewPermissions) {
            return $this->render('platform/errors/403.html.twig', [
                'session' => $session,
                'message' => 'You do not have permission to view users, roles, or permissions.',
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // Users with their assigned roles.
        // Non-owners: owner accounts are hidden entirely — they should not know owners exist.
        // $showDeleted: platform owners and admins with view_deleted_entries see soft-deleted accounts too.
        $showDeleted = $this->platformCan->isPlatformOwner($session)
            || $this->platformCan->check($session, 'view_deleted_entries');

        $users = $canViewUsers ? $this->db->fetchAllAssociative(
            'SELECT pa.id, pa.name, pa.email, pa.status,
                    pa.is_platform_owner, pa.is_system_account, pa.created_at,
                    GROUP_CONCAT(DISTINCT pr.name  ORDER BY pr.name  SEPARATOR \', \') AS role_names,
                    GROUP_CONCAT(DISTINCT pr.id    ORDER BY pr.id)                    AS role_ids
               FROM platform_admins pa
               LEFT JOIN platform_admin_roles par ON par.platform_admin_id = pa.id
               LEFT JOIN platform_roles pr        ON pr.id = par.platform_role_id
              ' . ($isOwner
                    ? ($showDeleted ? '' : 'WHERE pa.deleted_at IS NULL')
                    : ('WHERE pa.is_platform_owner = 0' . ($showDeleted ? '' : ' AND pa.deleted_at IS NULL'))
                ) . '
              GROUP BY pa.id
              ORDER BY pa.name ASC',
        ) : [];

        // Roles with counts + comma-separated permission IDs for JS
        $roles = ($canViewRoles || $canViewUsers) ? $this->db->fetchAllAssociative(
            'SELECT r.id, r.name, r.description, r.is_system_role, r.created_at,
                    COUNT(DISTINCT par.platform_admin_id)            AS admin_count,
                    COUNT(DISTINCT prp.platform_permission_id)       AS permission_count,
                    GROUP_CONCAT(DISTINCT prp.platform_permission_id
                                 ORDER BY prp.platform_permission_id) AS permission_ids
               FROM platform_roles r
               LEFT JOIN platform_admin_roles par ON par.platform_role_id = r.id
               LEFT JOIN platform_role_permissions prp ON prp.platform_role_id = r.id
              GROUP BY r.id
              ORDER BY r.is_system_role DESC, r.name ASC',
        ) : [];

        // Permissions with role usage count, ordered by category.
        // Non-owners: only show permissions they personally hold — they cannot see
        // (or therefore assign) permissions they do not have.
        $ownedPermissionIds = [];
        if (!$isOwner) {
            $ownedRows = $this->db->fetchAllAssociative(
                'SELECT DISTINCT prp.platform_permission_id
                   FROM platform_admin_roles par
                   JOIN platform_role_permissions prp ON prp.platform_role_id = par.platform_role_id
                  WHERE par.platform_admin_id = :id',
                ['id' => $callerId],
            );
            $ownedPermissionIds = array_map('intval', array_column($ownedRows, 'platform_permission_id'));
        }

        $permissions = ($canViewPermissions || $canViewRoles) ? $this->db->fetchAllAssociative(
            'SELECT p.id, p.name, p.action_key, p.category, p.description, p.created_at,
                    COUNT(DISTINCT prp.platform_role_id) AS role_count
               FROM platform_permissions p
               LEFT JOIN platform_role_permissions prp ON prp.platform_permission_id = p.id
              GROUP BY p.id
              ORDER BY p.category ASC, p.name ASC',
        ) : [];

        // Group permissions by category — non-owners only see permissions they hold
        $permissionsByCategory = [];
        foreach ($permissions as $p) {
            if (!$isOwner && !in_array((int) $p['id'], $ownedPermissionIds, true)) {
                continue;
            }
            $permissionsByCategory[$p['category']][] = $p;
        }

        // Peer user IDs: users who share at least one role with the caller.
        // Non-owners cannot act on their peers (edit, suspend, delete, assign roles).
        $peerUserIds = [];
        if (!$isOwner) {
            $peerRows = $this->db->fetchAllAssociative(
                'SELECT DISTINCT par2.platform_admin_id
                   FROM platform_admin_roles par1
                   JOIN platform_admin_roles par2 ON par2.platform_role_id = par1.platform_role_id
                  WHERE par1.platform_admin_id = :id
                    AND par2.platform_admin_id != :id',
                ['id' => $callerId],
            );
            $peerUserIds = array_map('intval', array_column($peerRows, 'platform_admin_id'));
        }

        return $this->render('platform/access/index.html.twig', [
            'session'            => $session,
            'users'              => $users,
            'roles'              => $roles,
            'permissionsByCategory' => $permissionsByCategory,
            'defaultTab'         => $canViewUsers ? 'users' : ($canViewRoles ? 'roles' : 'permissions'),
            'peerUserIds'        => $peerUserIds,
            'showDeleted'        => $showDeleted,

            // Capability flags passed to template to show/hide buttons
            'canViewUsers'         => $canViewUsers,
            'canViewRoles'         => $canViewRoles,
            'canViewPermissions'   => $canViewPermissions,
            'canCreateUsers'       => $this->platformCan->check($session, 'create_users'),
            'canEditUsers'         => $this->platformCan->check($session, 'edit_users'),
            'canDeleteUsers'       => $this->platformCan->check($session, 'delete_users'),
            'canAssignRoles'       => $this->platformCan->check($session, 'assign_roles'),
            'canCreateRoles'       => $this->platformCan->check($session, 'create_roles'),
            'canEditRoles'         => $this->platformCan->check($session, 'edit_roles'),
            'canDeleteRoles'       => $this->platformCan->check($session, 'delete_roles'),
            'canAssignPermissions' => $this->platformCan->check($session, 'assign_permissions'),
            'canCreatePermissions' => $this->platformCan->check($session, 'create_permissions'),
            'canDeletePermissions' => $this->platformCan->check($session, 'delete_permissions'),
            'isOwner'              => $isOwner,
        ]);
    }

    // =========================================================================
    // USERS
    // =========================================================================

    #[Route('/users/create', name: 'platform_access_users_create', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'create_users');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name     = trim((string) $request->request->get('name', ''));
        $email    = strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');
        $status   = in_array($request->request->get('status'), ['active', 'inactive', 'suspended'], true)
                        ? $request->request->get('status')
                        : 'active';

        if ($name === '' || $email === '' || $password === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name, email and password are required.']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid email address.']);
        }

        if (strlen($password) < 8) {
            return new JsonResponse(['ok' => false, 'error' => 'Password must be at least 8 characters.']);
        }

        try {
            $this->db->insert('platform_admins', [
                'name'               => $name,
                'email'              => $email,
                'password'           => password_hash($password, PASSWORD_DEFAULT),
                'status'             => $status,
                'is_platform_owner'  => 0,
                'is_system_account'  => 0,
            ]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e, 'email')]);
        }
    }

    #[Route('/users/{id}/edit', name: 'platform_access_users_edit', methods: ['POST'])]
    public function editUser(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'edit_users');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name   = trim((string) $request->request->get('name', ''));
        $email  = strtolower(trim((string) $request->request->get('email', '')));
        $status = in_array($request->request->get('status'), ['active', 'inactive', 'suspended'], true)
                      ? $request->request->get('status')
                      : 'active';

        if ($name === '' || $email === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name and email are required.']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid email address.']);
        }

        // Fetch target account
        $target = $this->db->fetchAssociative(
            'SELECT is_system_account, is_platform_owner, status FROM platform_admins WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );
        if (!$target) {
            return new JsonResponse(['ok' => false, 'error' => 'User not found.']);
        }
        if ((bool) $target['is_system_account']) {
            return new JsonResponse(['ok' => false, 'error' => 'System accounts cannot be edited.']);
        }
        if ((bool) $target['is_platform_owner'] && !$this->platformCan->isPlatformOwner($session)) {
            return new JsonResponse(['ok' => false, 'error' => 'Only a platform owner can edit another owner account.']);
        }

        // Peer-role protection: cannot act on someone in the same role
        if (!$this->platformCan->isPlatformOwner($session) && $this->shareRole($session->user->id, $id)) {
            return new JsonResponse(['ok' => false, 'error' => 'You cannot edit a user who shares a role with you.'], 403);
        }

        // Block self-status changes
        if ($id === $session->user->id && $status !== 'active') {
            return new JsonResponse(['ok' => false, 'error' => 'You cannot suspend or deactivate your own account.']);
        }

        // Status-specific permission checks (only when status is actually changing)
        if ($status !== ($target['status'] ?? 'active')) {
            if ($status === 'suspended' && !$this->platformCan->check($session, 'suspend_users')) {
                return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to suspend accounts.'], 403);
            }
            if ($status === 'inactive' && !$this->platformCan->check($session, 'deactivate_users')) {
                return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to deactivate accounts.'], 403);
            }
        }

        try {
            $this->db->update('platform_admins', [
                'name'   => $name,
                'email'  => $email,
                'status' => $status,
            ], ['id' => $id]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e, 'email')]);
        }
    }

    #[Route('/users/{id}/delete', name: 'platform_access_users_delete', methods: ['POST'])]
    public function deleteUser(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'delete_users');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        if ($id === (int) $session->user->id) {
            return new JsonResponse(['ok' => false, 'error' => 'You cannot delete your own account.']);
        }

        // Peer-role protection
        if (!$this->platformCan->isPlatformOwner($session) && $this->shareRole($session->user->id, $id)) {
            return new JsonResponse(['ok' => false, 'error' => 'You cannot delete a user who shares a role with you.'], 403);
        }

        $target = $this->db->fetchAssociative(
            'SELECT is_system_account, is_platform_owner FROM platform_admins WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );

        if (!$target) {
            return new JsonResponse(['ok' => false, 'error' => 'User not found.']);
        }

        if ((bool) $target['is_system_account']) {
            return new JsonResponse(['ok' => false, 'error' => 'System accounts cannot be deleted.']);
        }

        if ((bool) $target['is_platform_owner']) {
            return new JsonResponse(['ok' => false, 'error' => 'Platform owner accounts cannot be deleted.']);
        }

        try {
            $this->db->executeStatement(
                'UPDATE platform_admins SET deleted_at = NOW() WHERE id = :id',
                ['id' => $id],
            );
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    #[Route('/users/{id}/roles/save', name: 'platform_access_users_roles_save', methods: ['POST'])]
    public function saveUserRoles(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'assign_roles');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        // Block self-role-assignment
        if ($id === $session->user->id) {
            return new JsonResponse(['ok' => false, 'error' => 'You cannot assign roles to your own account.'], 403);
        }

        // Peer-role protection
        if (!$this->platformCan->isPlatformOwner($session) && $this->shareRole($session->user->id, $id)) {
            return new JsonResponse(['ok' => false, 'error' => 'You cannot assign roles to a user who shares a role with you.'], 403);
        }

        // Prevent non-owners from modifying roles on an owner account
        $targetOwner = $this->db->fetchOne(
            'SELECT is_platform_owner FROM platform_admins WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );
        if ((bool) $targetOwner && !$this->platformCan->isPlatformOwner($session)) {
            return new JsonResponse(['ok' => false, 'error' => 'Only a platform owner can modify roles on another owner account.'], 403);
        }

        $roleIds = array_map('intval', (array) $request->request->all('roles'));

        // Role containment guard: you cannot assign a role whose permission set
        // exceeds your own — prevents privilege escalation through role assignment.
        // (A role with zero permissions is always assignable.)
        if (!$this->platformCan->isPlatformOwner($session) && count($roleIds) > 0) {
            $roleIdsClean = array_filter($roleIds, fn(int $r) => $r > 0);
            if (count($roleIdsClean) > 0) {
                $escalation = (int) $this->db->fetchOne(
                    'SELECT COUNT(*)
                       FROM platform_role_permissions prp
                      WHERE prp.platform_role_id IN (:role_ids)
                        AND prp.platform_permission_id NOT IN (
                            SELECT prp2.platform_permission_id
                              FROM platform_admin_roles par
                              JOIN platform_role_permissions prp2
                                ON prp2.platform_role_id = par.platform_role_id
                             WHERE par.platform_admin_id = :caller_id
                        )',
                    ['role_ids' => $roleIdsClean, 'caller_id' => $session->user->id],
                    ['role_ids' => ArrayParameterType::INTEGER],
                );

                if ($escalation > 0) {
                    return new JsonResponse([
                        'ok'    => false,
                        'error' => 'You cannot assign a role that contains permissions you do not hold.',
                    ], 403);
                }
            }
        }

        try {
            $this->db->beginTransaction();
            $this->db->delete('platform_admin_roles', ['platform_admin_id' => $id]);

            foreach ($roleIds as $roleId) {
                if ($roleId <= 0) continue;
                $this->db->insert('platform_admin_roles', [
                    'platform_admin_id' => $id,
                    'platform_role_id'  => $roleId,
                ]);
            }

            $this->db->commit();
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    // =========================================================================
    // ROLES
    // =========================================================================

    #[Route('/roles/create', name: 'platform_access_roles_create', methods: ['POST'])]
    public function createRole(Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'create_roles');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name = trim((string) $request->request->get('name', ''));
        $desc = trim((string) $request->request->get('description', '')) ?: null;

        if ($name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Role name is required.']);
        }

        try {
            $this->db->insert('platform_roles', [
                'name'           => $name,
                'description'    => $desc,
                'is_system_role' => 0,
            ]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e, 'name')]);
        }
    }

    #[Route('/roles/{id}/edit', name: 'platform_access_roles_edit', methods: ['POST'])]
    public function editRole(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'edit_roles');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $isSystem = $this->db->fetchOne('SELECT is_system_role FROM platform_roles WHERE id = :id', ['id' => $id]);
        if ((bool) $isSystem) {
            return new JsonResponse(['ok' => false, 'error' => 'System roles cannot be edited.']);
        }

        $name = trim((string) $request->request->get('name', ''));
        $desc = trim((string) $request->request->get('description', '')) ?: null;

        if ($name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Role name is required.']);
        }

        try {
            $this->db->update('platform_roles', ['name' => $name, 'description' => $desc], ['id' => $id]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e, 'name')]);
        }
    }

    #[Route('/roles/{id}/delete', name: 'platform_access_roles_delete', methods: ['POST'])]
    public function deleteRole(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'delete_roles');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $isSystem = $this->db->fetchOne('SELECT is_system_role FROM platform_roles WHERE id = :id', ['id' => $id]);
        if ((bool) $isSystem) {
            return new JsonResponse(['ok' => false, 'error' => 'System roles cannot be deleted.']);
        }

        try {
            $this->db->delete('platform_roles', ['id' => $id]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    #[Route('/roles/{id}/permissions/save', name: 'platform_access_roles_permissions_save', methods: ['POST'])]
    public function saveRolePermissions(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'assign_permissions');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $permissionIds = array_filter(array_map('intval', (array) $request->request->all('permissions')));

        // Privilege escalation guard: you cannot assign a permission you do not hold yourself.
        // Platform owners bypass this (isPlatformOwner → check() always returns true).
        if (!$this->platformCan->isPlatformOwner($session) && count($permissionIds) > 0) {
            $unowned = (int) $this->db->fetchOne(
                'SELECT COUNT(*)
                   FROM platform_permissions pp
                  WHERE pp.id IN (:ids)
                    AND pp.id NOT IN (
                        SELECT prp.platform_permission_id
                          FROM platform_admin_roles par
                          JOIN platform_role_permissions prp ON prp.platform_role_id = par.platform_role_id
                         WHERE par.platform_admin_id = :admin_id
                    )',
                ['ids' => array_values($permissionIds), 'admin_id' => $session->user->id],
                ['ids' => ArrayParameterType::INTEGER],
            );

            if ($unowned > 0) {
                return new JsonResponse([
                    'ok'    => false,
                    'error' => 'You cannot assign permissions you do not hold yourself.',
                ], 403);
            }
        }

        // Own-role unassignment guard: if the caller is a member of this role, they cannot
        // remove any permission from it — because they would lose it and cannot reassign it
        // to themselves (self-role-assignment is blocked).
        if (!$this->platformCan->isPlatformOwner($session)) {
            $callerInRole = (bool) $this->db->fetchOne(
                'SELECT 1 FROM platform_admin_roles
                  WHERE platform_admin_id = :caller_id AND platform_role_id = :role_id',
                ['caller_id' => $session->user->id, 'role_id' => $id],
            );

            if ($callerInRole) {
                $currentRows = $this->db->fetchAllAssociative(
                    'SELECT platform_permission_id FROM platform_role_permissions WHERE platform_role_id = :role_id',
                    ['role_id' => $id],
                );
                $currentIds = array_map('intval', array_column($currentRows, 'platform_permission_id'));
                $beingRemoved = array_diff($currentIds, array_values($permissionIds));

                if (count($beingRemoved) > 0) {
                    return new JsonResponse([
                        'ok'    => false,
                        'error' => 'You cannot remove permissions from a role you are a member of.',
                    ], 403);
                }
            }
        }

        try {
            $this->db->beginTransaction();
            $this->db->delete('platform_role_permissions', ['platform_role_id' => $id]);

            foreach ($permissionIds as $permId) {
                if ($permId <= 0) continue;
                $this->db->insert('platform_role_permissions', [
                    'platform_role_id'      => $id,
                    'platform_permission_id' => $permId,
                ]);
            }

            $this->db->commit();
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    // =========================================================================
    // PERMISSIONS
    // =========================================================================

    #[Route('/permissions/create', name: 'platform_access_permissions_create', methods: ['POST'])]
    public function createPermission(Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'create_permissions');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name      = trim((string) $request->request->get('name', ''));
        $actionKey = strtoupper(trim((string) $request->request->get('action_key', '')));
        $category  = trim((string) $request->request->get('category', ''));
        $desc      = trim((string) $request->request->get('description', '')) ?: null;

        if ($name === '' || $actionKey === '' || $category === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name, action key and category are required.']);
        }

        // Normalize action_key: uppercase, spaces → underscores
        $actionKey = str_replace([' ', '-'], '_', $actionKey);

        try {
            $this->db->insert('platform_permissions', [
                'name'        => $name,
                'action_key'  => $actionKey,
                'category'    => $category,
                'description' => $desc,
            ]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e, 'action_key')]);
        }
    }

    #[Route('/permissions/{id}/delete', name: 'platform_access_permissions_delete', methods: ['POST'])]
    public function deletePermission(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatform($request, 'delete_permissions');
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        try {
            $this->db->delete('platform_permissions', ['id' => $id]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Returns true if $callerId and $targetId share at least one platform role.
     * Used to enforce peer-role protection: admins in the same role cannot act on each other.
     */
    private function shareRole(int $callerId, int $targetId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1
               FROM platform_admin_roles par1
               JOIN platform_admin_roles par2 ON par2.platform_role_id = par1.platform_role_id
              WHERE par1.platform_admin_id = :caller_id
                AND par2.platform_admin_id = :target_id
              LIMIT 1',
            ['caller_id' => $callerId, 'target_id' => $targetId],
        );
    }

    private function dbError(\Throwable $e, string $uniqueColumn = ''): string
    {
        if ($uniqueColumn !== '' && str_contains($e->getMessage(), '1062')) {
            return ucfirst($uniqueColumn) . ' already exists. Choose a different one.';
        }

        return 'A database error occurred. Please try again.';
    }
}
