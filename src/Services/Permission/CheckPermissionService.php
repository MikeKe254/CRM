<?php

declare(strict_types=1);

namespace App\Services\Permission;

use App\Services\Auth\DTO\AuthResult;
use Doctrine\DBAL\Connection;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║             Angavu Check Permission Service                      ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Answers one question: "Can this user do X?"                     ║
 * ║                                                                  ║
 * ║  Features:                                                       ║
 * ║   • Super admins always pass — no DB query needed                ║
 * ║   • Returns bool for simple yes/no checks                        ║
 * ║   • Returns constraints map for limit-aware checks               ║
 * ║   • Per-request in-memory cache — safe to call repeatedly        ║
 * ║   • Works with action_key (VIEW_TRANSACTIONS) or                 ║
 * ║     permission name (view_transactions)                          ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Inject into any controller:                                     ║
 * ║                                                                  ║
 * ║    public function __construct(                                  ║
 * ║        private readonly CheckPermissionService $can              ║
 * ║    ) {}                                                          ║
 * ║                                                                  ║
 * ║  Usage:                                                          ║
 * ║    if (!$this->can->check($session, 'view_transactions')) { ...  ║
 * ║    $limit = $this->can->constraint($session,                     ║
 * ║                 'view_transactions', 'max_hours_history', 24);   ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
final class CheckPermissionService
{
    /**
     * In-memory cache per request.
     * Key: "{userId}:{companyId}:{permissionName}"
     * Value: ['granted' => bool, 'role_permission_id' => int|null, 'constraints' => array]
     */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Simple yes/no permission check.
     *
     * @param AuthResult $session        The validated session from AuthService
     * @param string     $permission     Permission name OR action_key
     *                                   e.g. 'view_transactions' or 'VIEW_TRANSACTIONS'
     */
    public function check(AuthResult $session, string $permission): bool
    {
        // Super admins always pass
        if ($session->user->isSuperAdmin) {
            return true;
        }

        $resolved = $this->resolve($session, $permission);

        return $resolved['granted'];
    }

    /**
     * Check multiple permissions at once.
     * Returns true only if ALL permissions are granted.
     */
    public function checkAll(AuthResult $session, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->check($session, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check multiple permissions at once.
     * Returns true if ANY permission is granted.
     */
    public function checkAny(AuthResult $session, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->check($session, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a specific constraint value for a permission.
     * Returns the default if the constraint is not set.
     *
     * @param AuthResult  $session
     * @param string      $permission    Permission name or action_key
     * @param string      $constraintKey e.g. 'max_hours_history'
     * @param mixed       $default       Returned when constraint is not set
     *
     * @return mixed The constraint value (always a string from DB) or $default
     *
     * Example:
     *   $hours = $this->can->constraint($session, 'view_transactions', 'max_hours_history', 24);
     *   // Returns '48' (string) or 24 (int default)
     */
    public function constraint(
        AuthResult $session,
        string     $permission,
        string     $constraintKey,
        mixed      $default = null,
    ): mixed {
        if ($session->user->isSuperAdmin) {
            return $default;
        }

        $resolved = $this->resolve($session, $permission);

        if (!$resolved['granted']) {
            return $default;
        }

        return $resolved['constraints'][$constraintKey] ?? $default;
    }

    /**
     * Get the full constraints map for a permission.
     * Returns empty array if permission not granted or no constraints set.
     *
     * Example return: ['max_hours_history' => '48', 'allowed_shortcodes' => '174379,123456']
     */
    public function constraints(AuthResult $session, string $permission): array
    {
        if ($session->user->isSuperAdmin) {
            return [];
        }

        $resolved = $this->resolve($session, $permission);

        return $resolved['granted'] ? $resolved['constraints'] : [];
    }

    /**
     * Get a full permission report for a user.
     * Returns every permission the user has, grouped by category, with constraints.
     * Useful for sending to the frontend on login.
     */
    public function getUserPermissionReport(AuthResult $session): array
    {
        if ($session->user->isSuperAdmin) {
            return ['super_admin' => true, 'permissions' => []];
        }

        $rows = $this->fetchAllUserPermissions(
            $session->user->id,
            $session->company->id,
        );

        $report = [];

        foreach ($rows as $row) {
            $constraints = $this->fetchConstraints((int) $row['role_permission_id']);

            $report[$row['category']][] = [
                'name'               => $row['name'],
                'action_key'         => $row['action_key'],
                'role_permission_id' => (int) $row['role_permission_id'],
                'constraints'        => $constraints,
            ];
        }

        return $report;
    }

    /**
     * Clear the in-memory cache.
     * Useful in tests or when permissions have just been modified.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    // =========================================================================
    // PRIVATE — RESOLUTION
    // =========================================================================

    /**
     * Core resolver. Checks the DB once per unique (user, company, permission)
     * combination per request, then caches the result.
     */
    private function resolve(AuthResult $session, string $permission): array
    {
        $userId    = $session->user->id;
        $companyId = $session->company->id;

        // Normalize to lowercase name (handles action_key input like 'VIEW_TRANSACTIONS')
        $permissionName = $this->normalizePermission($permission);

        $cacheKey = "{$userId}:{$companyId}:{$permissionName}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Query: does this user have this permission via any of their roles?
        $row = $this->db->fetchAssociative(
            'SELECT rp.id AS role_permission_id
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE ur.user_id  = :user_id
               AND r.company_id = :company_id
               AND (p.name = :name OR p.action_key = :action_key)
             LIMIT 1',
            [
                'user_id'    => $userId,
                'company_id' => $companyId,
                'name'       => $permissionName,
                'action_key' => strtoupper($permissionName),
            ],
        );

        if (!$row) {
            return $this->cache[$cacheKey] = [
                'granted'           => false,
                'role_permission_id' => null,
                'constraints'       => [],
            ];
        }

        $rolePermissionId = (int) $row['role_permission_id'];
        $constraints      = $this->fetchConstraints($rolePermissionId);

        return $this->cache[$cacheKey] = [
            'granted'            => true,
            'role_permission_id' => $rolePermissionId,
            'constraints'        => $constraints,
        ];
    }

    /**
     * Fetch all constraints for a role_permission as a flat key => value map.
     */
    private function fetchConstraints(int $rolePermissionId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT c.constraint_key, rpc.constraint_value
             FROM role_permission_constraints rpc
             JOIN constraints c ON c.id = rpc.constraint_id
             WHERE rpc.role_permission_id = :id',
            ['id' => $rolePermissionId],
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['constraint_key']] = $row['constraint_value'];
        }

        return $map;
    }

    /**
     * Fetch all permissions for a user across all their roles in a company.
     */
    private function fetchAllUserPermissions(int $userId, int $companyId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT DISTINCT
                p.name,
                p.category,
                p.action_key,
                rp.id AS role_permission_id
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE ur.user_id   = :user_id
               AND r.company_id = :company_id
             ORDER BY p.category, p.name',
            ['user_id' => $userId, 'company_id' => $companyId],
        );
    }

    /**
     * Normalize permission input.
     * Accepts both 'view_transactions' and 'VIEW_TRANSACTIONS'.
     */
    private function normalizePermission(string $permission): string
    {
        return strtolower($permission);
    }
}