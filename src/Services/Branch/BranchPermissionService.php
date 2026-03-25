<?php

declare(strict_types=1);

namespace App\Services\Branch;

use App\Services\Branch\DTO\AuthorityScope;
use App\Services\Branch\DTO\BranchNode;
use App\Services\Branch\DTO\EffectivePermissions;
use App\Services\Branch\Exception\BranchAccessDeniedException;
use Doctrine\DBAL\Connection;

/**
 * Single source of truth for "can this user do this at this branch?"
 *
 * Permission resolution walks UP the ancestor chain and unions all
 * permissions from every role assigned at any ancestor node.
 * Result is cached per userId+branchId for the request lifetime.
 */
final class BranchPermissionService
{
    /** @var array<string, EffectivePermissions> */
    private array $permissionCache = [];
    /** @var array<string, AuthorityScope> */
    private array $scopeCache      = [];
    /** @var array<string, int[]> */
    private array $accessibleBranchIdsCache = [];

    public function __construct(
        private readonly Connection              $db,
        private readonly BranchHierarchyService  $hierarchy,
    ) {}

    // =========================================================================
    // CORE CHECKS
    // =========================================================================

    /**
     * Check if a user has a specific permission at a given branch.
     * Traverses ancestor chain and unions all role permissions.
     * Never throws — returns false on any error.
     */
    public function check(int $userId, int $branchId, string $permissionKey): bool
    {
        try {
            return $this->getEffectivePermissions($userId, $branchId)->has($permissionKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the full effective permission set for a user at a branch.
     * Cached per userId+branchId for the request lifetime.
     */
    public function getEffectivePermissions(int $userId, int $branchId): EffectivePermissions
    {
        $cacheKey = "{$userId}:{$branchId}";

        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $ancestorIds = $this->hierarchy->getAncestorIds($branchId);

        if (empty($ancestorIds)) {
            return $this->permissionCache[$cacheKey] = new EffectivePermissions(
                userId:      $userId,
                branchId:    $branchId,
                permissions: [],
                roleIds:     [],
                resolvedAt:  new \DateTimeImmutable(),
            );
        }

        // Fetch all role IDs the user holds at any ancestor node
        $placeholders = implode(',', array_fill(0, count($ancestorIds), '?'));

        $roleIds = $this->db->fetchFirstColumn(
            "SELECT DISTINCT role_id FROM user_node_roles
              WHERE user_id = ? AND node_id IN ({$placeholders})",
            array_merge([$userId], $ancestorIds),
        );

        $roleIds = array_map('intval', $roleIds);

        $permissions = [];

        if (!empty($roleIds)) {
            $rolePlaceholders = implode(',', array_fill(0, count($roleIds), '?'));
            $permissions = $this->db->fetchFirstColumn(
                "SELECT DISTINCT p.action_key
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE rp.role_id IN ({$rolePlaceholders})
                    AND p.deleted_at IS NULL",
                $roleIds,
            );
            $permissions = array_map('strtolower', $permissions);
        }

        return $this->permissionCache[$cacheKey] = new EffectivePermissions(
            userId:      $userId,
            branchId:    $branchId,
            permissions: $permissions,
            roleIds:     $roleIds,
            resolvedAt:  new \DateTimeImmutable(),
        );
    }

    /**
     * Returns all branch IDs this user can access and manage.
     * Cached per userId+branchId for the request lifetime.
     */
    public function getAuthorityScope(int $userId, int $branchId): AuthorityScope
    {
        $cacheKey = "{$userId}:{$branchId}";

        if (isset($this->scopeCache[$cacheKey])) {
            return $this->scopeCache[$cacheKey];
        }

        // Find all nodes where this user has ANY role assignment
        $assignedNodeIds = $this->db->fetchFirstColumn(
            'SELECT DISTINCT node_id FROM user_node_roles WHERE user_id = :user_id',
            ['user_id' => $userId],
        );

        $accessibleIds  = [];
        $manageableIds  = [];

        foreach ($assignedNodeIds as $nodeId) {
            // Each assigned node gives access to its entire subtree
            $subtree = $this->hierarchy->getSubtreeIds((int) $nodeId);
            $accessibleIds = array_unique(array_merge($accessibleIds, $subtree));

            // "Manageable" means user can assign roles / manage users there
            if ($this->check($userId, (int) $nodeId, 'manage_branch_users') ||
                $this->check($userId, (int) $nodeId, 'assign_users_to_branches')) {
                $manageableIds = array_unique(array_merge($manageableIds, $subtree));
            }
        }

        return $this->scopeCache[$cacheKey] = new AuthorityScope(
            userId:               $userId,
            fromNodeId:           $branchId,
            accessibleBranchIds:  array_values($accessibleIds),
            manageableBranchIds:  array_values($manageableIds),
        );
    }

    /**
     * Returns all BranchNode objects this user can operate in.
     *
     * @return BranchNode[]
     */
    public function getAccessibleBranches(int $userId, int $companyId): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM branches
              WHERE company_id = :cid
                AND status     = 'active'
                AND deleted_at IS NULL
              ORDER BY depth ASC, is_hq DESC, name ASC",
            ['cid' => $companyId],
        );

        if (empty($rows)) {
            return [];
        }

        $accessibleIds = $this->getAccessibleBranchIds($userId, $companyId);
        if (empty($accessibleIds)) {
            return [];
        }

        $accessibleRows = array_values(array_filter(
            $rows,
            fn (array $row) => in_array((int) $row['id'], $accessibleIds, true)
        ));

        if (empty($accessibleRows)) {
            return [];
        }

        $primaryNodeId = (int) ($this->db->fetchOne(
            'SELECT node_id FROM user_node_roles WHERE user_id = :u ORDER BY is_primary DESC LIMIT 1',
            ['u' => $userId],
        ) ?: 0);

        usort($accessibleRows, static function (array $a, array $b) use ($primaryNodeId): int {
            $aPrimary = (int) $a['id'] === $primaryNodeId ? 1 : 0;
            $bPrimary = (int) $b['id'] === $primaryNodeId ? 1 : 0;

            if ($aPrimary !== $bPrimary) {
                return $bPrimary <=> $aPrimary;
            }

            if ((int) $a['depth'] !== (int) $b['depth']) {
                return (int) $a['depth'] <=> (int) $b['depth'];
            }

            if ((int) $a['is_hq'] !== (int) $b['is_hq']) {
                return (int) $b['is_hq'] <=> (int) $a['is_hq'];
            }

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return array_map(BranchNode::fromRow(...), $accessibleRows);
    }

    /**
     * Returns any node ID the user is assigned to — used as a permission-check anchor
     * when we need to check a company-level permission without a specific branch context.
     */
    private function getAnyNodeId(int $userId): int
    {
        return (int) ($this->db->fetchOne(
            'SELECT node_id FROM user_node_roles WHERE user_id = :u ORDER BY is_primary DESC LIMIT 1',
            ['u' => $userId],
        ) ?: 0);
    }

    // =========================================================================
    // ROLE ASSIGNMENT
    // =========================================================================

    /**
     * Returns role IDs assigned to a user at EXACTLY this node (no ancestor walk).
     *
     * @return int[]
     */
    public function getUserRolesAt(int $userId, int $nodeId): array
    {
        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT role_id FROM user_node_roles WHERE user_id = :user_id AND node_id = :node_id',
            ['user_id' => $userId, 'node_id' => $nodeId],
        ));
    }

    /**
     * Returns role IDs from this node + all ancestors.
     *
     * @return int[]
     */
    public function getUserEffectiveRoles(int $userId, int $branchId): array
    {
        return $this->getEffectivePermissions($userId, $branchId)->roleIds;
    }

    /**
     * Assign a role to a user at a node. Validates:
     *  1. Actor has ASSIGN_ROLES permission at the target node or ancestor
     *  2. Containment: actor's effective permissions are a superset of the role's permissions
     */
    public function assignRole(
        int $actorUserId,
        int $actorBranchId,
        int $targetUserId,
        int $nodeId,
        int $roleId,
    ): void {
        if (!$this->check($actorUserId, $actorBranchId, 'assign_roles')) {
            throw new BranchAccessDeniedException('assign_roles');
        }

        // Containment check — actor must hold every permission the role grants
        $rolePermissions = $this->db->fetchFirstColumn(
            "SELECT p.action_key
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :role_id AND p.deleted_at IS NULL",
            ['role_id' => $roleId],
        );

        $actorPermissions = $this->getEffectivePermissions($actorUserId, $actorBranchId)->all();

        foreach ($rolePermissions as $perm) {
            if (!in_array(strtolower($perm), $actorPermissions, true)) {
                throw new \RuntimeException(
                    'You cannot assign a role that contains permissions you do not hold.'
                );
            }
        }

        // Check if this is the target user's first assignment — set as primary
        $hasExisting = (bool) $this->db->fetchOne(
            'SELECT 1 FROM user_node_roles WHERE user_id = :user_id LIMIT 1',
            ['user_id' => $targetUserId],
        );

        $this->db->executeStatement(
            'INSERT IGNORE INTO user_node_roles (user_id, node_id, role_id, is_primary) VALUES (:u, :n, :r, :p)',
            ['u' => $targetUserId, 'n' => $nodeId, 'r' => $roleId, 'p' => $hasExisting ? 0 : 1],
        );

        $this->bustCache($targetUserId);
    }

    /**
     * Revoke a role from a user at a node.
     * Throws if this would leave the user with no assignments anywhere (orphan).
     */
    public function revokeRole(
        int $actorUserId,
        int $actorBranchId,
        int $targetUserId,
        int $nodeId,
        int $roleId,
    ): void {
        if (!$this->check($actorUserId, $actorBranchId, 'assign_roles')) {
            throw new BranchAccessDeniedException('assign_roles');
        }

        // Would this leave the user with zero assignments?
        $totalAssignments = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM user_node_roles WHERE user_id = :user_id',
            ['user_id' => $targetUserId],
        );

        if ($totalAssignments <= 1) {
            throw new \RuntimeException(
                'Cannot revoke the last role assignment — the user would have no branch access.'
            );
        }

        $this->db->executeStatement(
            'DELETE FROM user_node_roles WHERE user_id = :u AND node_id = :n AND role_id = :r',
            ['u' => $targetUserId, 'n' => $nodeId, 'r' => $roleId],
        );

        $this->bustCache($targetUserId);
    }

    /**
     * Returns true if the user has at least one role at this branch or any ancestor.
     *
     * Always includes $branchId itself in the check regardless of path content —
     * guards against stale/wrong materialised paths that exclude the node's own ID.
     */
    public function validateAccess(int $userId, int $branchId): bool
    {
        $companyId = (int) ($this->db->fetchOne(
            'SELECT company_id FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $branchId],
        ) ?: 0);

        if ($companyId === 0) {
            return false;
        }

        return in_array($branchId, $this->getAccessibleBranchIds($userId, $companyId), true);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function bustCache(int $userId): void
    {
        foreach (array_keys($this->permissionCache) as $key) {
            if (str_starts_with($key, "{$userId}:")) {
                unset($this->permissionCache[$key]);
            }
        }
        foreach (array_keys($this->scopeCache) as $key) {
            if (str_starts_with($key, "{$userId}:")) {
                unset($this->scopeCache[$key]);
            }
        }
        foreach (array_keys($this->accessibleBranchIdsCache) as $key) {
            if (str_starts_with($key, "{$userId}:")) {
                unset($this->accessibleBranchIdsCache[$key]);
            }
        }
    }

    /**
     * Returns the real set of branches the user may enter based on the current
     * org structure, not just ancestor presence.
     *
     * Branch-scoped roles only open their exact assigned node.
     * Regional managers open their region subtree.
     * HQ leadership (Owner, Director, Overall Manager) open the full company tree.
     *
     * @return int[]
     */
    private function getAccessibleBranchIds(int $userId, int $companyId): array
    {
        $cacheKey = "{$userId}:{$companyId}";
        if (isset($this->accessibleBranchIdsCache[$cacheKey])) {
            return $this->accessibleBranchIdsCache[$cacheKey];
        }

        $allActiveBranchIds = array_map('intval', $this->db->fetchFirstColumn(
            "SELECT id FROM branches
              WHERE company_id = :cid
                AND status     = 'active'
                AND deleted_at IS NULL
              ORDER BY depth ASC, is_hq DESC, name ASC",
            ['cid' => $companyId],
        ));

        if (empty($allActiveBranchIds)) {
            return $this->accessibleBranchIdsCache[$cacheKey] = [];
        }

        $hasCrossBranchLogin = $this->check($userId, $this->getAnyNodeId($userId), 'cross_branch_login');
        if ($hasCrossBranchLogin) {
            return $this->accessibleBranchIdsCache[$cacheKey] = $allActiveBranchIds;
        }

        $assignments = $this->db->fetchAllAssociative(
            "SELECT
                unr.node_id,
                b.type AS node_type,
                b.is_hq,
                r.name AS role_name
             FROM user_node_roles unr
             JOIN branches b ON b.id = unr.node_id
             JOIN roles r ON r.id = unr.role_id
             WHERE unr.user_id = :user_id
               AND b.company_id = :company_id
               AND b.deleted_at IS NULL
               AND r.deleted_at IS NULL",
            ['user_id' => $userId, 'company_id' => $companyId],
        );

        $accessibleIds = [];

        foreach ($assignments as $assignment) {
            $nodeId = (int) $assignment['node_id'];
            $roleName = strtolower(trim((string) $assignment['role_name']));
            $nodeType = strtolower((string) $assignment['node_type']);
            $isHq = (bool) $assignment['is_hq'];

            if ($isHq && in_array($roleName, ['owner', 'director', 'overall manager'], true)) {
                return $this->accessibleBranchIdsCache[$cacheKey] = $allActiveBranchIds;
            }

            if ($nodeType === 'region' && $roleName === 'regional manager') {
                $accessibleIds = array_merge($accessibleIds, $this->hierarchy->getSubtreeIds($nodeId));
                continue;
            }

            $accessibleIds[] = $nodeId;
        }

        return $this->accessibleBranchIdsCache[$cacheKey] = array_values(array_unique(array_map('intval', $accessibleIds)));
    }
}
