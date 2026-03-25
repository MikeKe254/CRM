<?php

declare(strict_types=1);

namespace App\Services\Permission;

use App\Services\Auth\DTO\AuthResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class PlatformCheckPermissionService
{
    private array $cache = [];

    private const APP_PERMISSION_MAP = [
        // ── Platform admin user management ──────────────────────────────────
        'view_users'         => ['VIEW_PLATFORM_ADMINS'],
        'create_users'       => ['CREATE_PLATFORM_ADMINS'],
        'edit_users'         => ['EDIT_PLATFORM_ADMINS'],
        'suspend_users'      => ['SUSPEND_PLATFORM_ADMINS'],
        'deactivate_users'   => ['DEACTIVATE_PLATFORM_ADMINS'],
        'delete_users'       => ['DELETE_PLATFORM_ADMINS'],
        'assign_roles'       => ['ASSIGN_PLATFORM_ROLES'],

        // ── Platform role management (CRUD + permission assignment) ──────────
        'view_roles'         => ['ASSIGN_PLATFORM_ROLES'],
        'view_roles_hierarchy' => ['ASSIGN_PLATFORM_ROLES'],
        'create_roles'       => ['CREATE_PLATFORM_ROLES'],
        'edit_roles'         => ['EDIT_PLATFORM_ROLES'],
        'edit_roles_hierarchy' => ['EDIT_PLATFORM_ROLES'],
        'delete_roles'       => ['DELETE_PLATFORM_ROLES'],
        'assign_permissions' => ['ASSIGN_PLATFORM_ROLES'],

        // ── Platform permission catalogue ────────────────────────────────────
        'view_permissions'   => ['VIEW_PLATFORM_PERMISSIONS'],
        // create_permissions / delete_permissions: no mapping → resolves to
        // CREATE_PERMISSIONS / DELETE_PERMISSIONS which don't exist → only owners

        // ── Tenant dashboard access ──────────────────────────────────────────
        'access_company_context' => ['ACCESS_COMPANY_CONTEXT'],
        'view_company_org_chart' => ['VIEW_COMPANY_ORG_CHART'],
        'manage_company_org_chart' => ['MANAGE_COMPANY_ORG_CHART'],

        // ── Misc ─────────────────────────────────────────────────────────────
        'authorize_pos_terminal' => ['AUTHORIZE_TERMINALS'],
        'view_audit_logs'        => ['VIEW_AUDIT_LOGS'],
    ];

    public function __construct(private readonly Connection $db) {}

    public function isPlatformAdminSession(AuthResult $session): bool
    {
        return $session->user->isSuperAdmin;
    }

    public function isPlatformOwner(AuthResult $session): bool
    {
        return $this->isPlatformAdminSession($session)
            && $session->user->isPlatformOwner;
    }

    public function check(AuthResult $session, string $permission): bool
    {
        if (!$this->isPlatformAdminSession($session)) {
            return false;
        }

        if ($this->isPlatformOwner($session)) {
            return true;
        }

        $adminId = $session->user->id;
        $keys = $this->resolveActionKeys($permission);
        $cacheKey = $adminId . ':' . implode('|', $keys);

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $result = (bool) $this->db->fetchOne(
            'SELECT 1
             FROM platform_admin_roles par
             JOIN platform_role_permissions prp ON prp.platform_role_id = par.platform_role_id
             JOIN platform_permissions pp ON pp.id = prp.platform_permission_id
             WHERE par.platform_admin_id = :admin_id
               AND pp.action_key IN (:keys)
             LIMIT 1',
            [
                'admin_id' => $adminId,
                'keys' => $keys,
            ],
            [
                'keys' => ArrayParameterType::STRING,
            ],
        );

        $this->cache[$cacheKey] = $result;

        return $result;
    }

    private function resolveActionKeys(string $permission): array
    {
        if (isset(self::APP_PERMISSION_MAP[$permission])) {
            return self::APP_PERMISSION_MAP[$permission];
        }

        return [strtoupper($permission)];
    }
}
