<?php

namespace App\Services\Role;

use Doctrine\DBAL\Connection;

/**
 * RoleHierarchyService
 *
 * Manages explicit role hierarchy relationships and provides queries to determine
 * role management authority without relying on permission counts.
 *
 * Hierarchy Structure:
 *  - level 4: Owner
 *  - level 3: Executive leadership (Director, Overall Manager)
 *  - level 2: Management (Regional Manager, Branch Manager, Assistant Manager, Department Manager)
 *  - level 1: Supervisor / team lead tier
 *  - level 0: Operational branch roles
 */
class RoleHierarchyService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Get role's parent role
     */
    public function getParentRole(int $roleId, int $companyId): ?int
    {
        $result = $this->db->fetchOne(
            'SELECT parent_role_id FROM role_hierarchy WHERE role_id = :role_id AND company_id = :company_id',
            ['role_id' => $roleId, 'company_id' => $companyId],
        );
        return $result ? (int)$result : null;
    }

    /**
     * Get role's hierarchy level (0-4)
     * 4 = Owner, 3 = Executive, 2 = Management, 1 = Supervisor, 0 = Operational
     */
    public function getRoleLevel(int $roleId, int $companyId): ?int
    {
        $result = $this->db->fetchOne(
            'SELECT level FROM role_hierarchy WHERE role_id = :role_id AND company_id = :company_id',
            ['role_id' => $roleId, 'company_id' => $companyId],
        );
        return $result !== null && $result !== false ? (int)$result : null;
    }

    /**
     * Get all child roles (direct subordinates) of a role
     * @return array List of role IDs
     */
    public function getChildRoles(int $parentRoleId, int $companyId): array
    {
        $results = $this->db->fetchAllAssociative(
            'SELECT role_id FROM role_hierarchy WHERE parent_role_id = :parent_id AND company_id = :company_id ORDER BY role_id',
            ['parent_id' => $parentRoleId, 'company_id' => $companyId],
        );
        return array_column($results, 'role_id');
    }

    /**
     * Get all descendant roles (recursively) of a role
     * Includes the role itself
     * @return array List of role IDs
     */
    public function getAllDescendants(int $roleId, int $companyId): array
    {
        $descendants = [$roleId];
        $children = $this->getChildRoles($roleId, $companyId);

        foreach ($children as $childId) {
            $descendants = array_merge($descendants, $this->getAllDescendants($childId, $companyId));
        }

        return array_unique($descendants);
    }

    /**
     * Build complete hierarchy tree for a company
     * @return array Nested array structure
     */
    public function buildHierarchyTree(int $companyId): array
    {
        // Get all roles with hierarchy info
        $allRoles = $this->db->fetchAllAssociative(
            "SELECT
              rh.role_id,
              rh.parent_role_id,
              rh.level,
              rh.scope,
              r.name as role_name,
              r.description
            FROM role_hierarchy rh
            LEFT JOIN roles r ON r.id = rh.role_id
            WHERE rh.company_id = :company_id
            ORDER BY rh.level DESC, r.name",
            ['company_id' => $companyId],
        );

        // Build tree recursively
        $tree = [];
        foreach ($allRoles as $role) {
            if ($role['parent_role_id'] === null) {
                // Top-level role
                $tree[] = $this->buildNode($role, $allRoles);
            }
        }

        return $tree;
    }

    /**
     * Helper to build a tree node recursively
     */
    private function buildNode(array $role, array $allRoles): array
    {
        $node = [
            'id' => $role['role_id'],
            'name' => $role['role_name'],
            'description' => $role['description'],
            'level' => $role['level'],
            'scope' => $role['scope'],
            'children' => [],
        ];

        // Find children
        foreach ($allRoles as $candidate) {
            if ($candidate['parent_role_id'] == $role['role_id']) {
                $node['children'][] = $this->buildNode($candidate, $allRoles);
            }
        }

        return $node;
    }

    /**
     * Check if actor role can manage target role
     * Returns true if:
     *  - Actor is owner (level 4)
     *  - Actor is direct parent of target
     *  - Actor is ancestor of target
     */
    public function canManageRole(int $actorRoleId, int $targetRoleId, int $companyId): bool
    {
        // Get actor's level
        $actorLevel = $this->getRoleLevel($actorRoleId, $companyId);
        $targetLevel = $this->getRoleLevel($targetRoleId, $companyId);

        if ($actorLevel === null || $targetLevel === null) {
            return false;
        }

        // Owner can manage anyone below them
        if ($actorLevel >= 4) {
            return $targetLevel < $actorLevel;
        }

        // Check if actor is ancestor of target
        $ancestors = $this->getAncestors($targetRoleId, $companyId);
        return in_array($actorRoleId, $ancestors);
    }

    /**
     * Get all ancestor roles of a role (parents, grandparents, etc.)
     * Does NOT include the role itself
     * @return array List of role IDs from direct parent to root
     */
    public function getAncestors(int $roleId, int $companyId): array
    {
        $ancestors = [];
        $currentId = $roleId;

        while ($parentId = $this->getParentRole($currentId, $companyId)) {
            $ancestors[] = $parentId;
            $currentId = $parentId;
        }

        return $ancestors;
    }

    /**
     * Validate scope consistency between role and node
     * A role's scope must be compatible with the node it's assigned to
     *
     * Scope Rules:
     *  - 'any': can be assigned to any node
     *  - 'hq': can only be assigned to depth=0 (HQ) node
     *  - 'region': can be assigned to depth=0 or depth=1 nodes
     *  - 'branch': can be assigned to any depth≥1 node
     */
    public function validateScopeMatch(int $roleId, int $branchId, int $companyId): bool
    {
        // Get role scope
        $scope = $this->db->fetchOne(
            'SELECT scope FROM role_hierarchy WHERE role_id = :role_id AND company_id = :company_id',
            ['role_id' => $roleId, 'company_id' => $companyId],
        );

        if (!$scope) {
            return false;
        }

        // Get branch depth
        $depth = $this->db->fetchOne(
            'SELECT depth FROM branches WHERE id = :id AND company_id = :company_id',
            ['id' => $branchId, 'company_id' => $companyId],
        );

        if ($depth === false || $depth === null) {
            return false;
        }

        $depth = (int)$depth;

        // Validate scope rules
        switch ($scope) {
            case 'any':
                return true;  // Any depth is OK

            case 'hq':
                return $depth === 0;  // HQ only

            case 'region':
                return $depth <= 1;  // HQ or regional

            case 'branch':
                return $depth >= 1;  // Regional, area, or branch

            default:
                return false;
        }
    }

    /**
     * Get all roles that an actor can assign (based on hierarchy)
     * Actor can assign: themselves + all descendants
     * @return array List of assignable role IDs
     */
    public function getAssignableRoles(int $actorRoleId, int $companyId): array
    {
        // Actor can assign roles they can manage, which includes:
        // - Their own role (for peer-to-peer within same level)
        // - All subordinate roles
        $descendants = $this->getAllDescendants($actorRoleId, $companyId);

        // Remove self, keep descendants only (prevent self-assignment in peer scenarios)
        return array_filter($descendants, fn($id) => $id !== $actorRoleId);
    }

    /**
     * Update role hierarchy parent
     */
    public function setParentRole(int $roleId, ?int $parentRoleId, int $companyId): void
    {
        $this->db->update('role_hierarchy', [
            'parent_role_id' => $parentRoleId,
        ], [
            'role_id' => $roleId,
            'company_id' => $companyId,
        ]);
    }

    /**
     * Update role level
     */
    public function setRoleLevel(int $roleId, int $level, int $companyId): void
    {
        if ($level < 0 || $level > 4) {
            throw new \InvalidArgumentException("Role level must be 0-4");
        }

        $this->db->update('role_hierarchy', [
            'level' => $level,
        ], [
            'role_id' => $roleId,
            'company_id' => $companyId,
        ]);
    }
}
