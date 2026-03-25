<?php

namespace App\Services\Role;

use Doctrine\DBAL\Connection;

/**
 * RoleHierarchyValidator
 *
 * Pre-assignment validation for role assignments.
 * Ensures data integrity before user-role-node assignments are persisted.
 *
 * Validates:
 *  1. Actor can manage target role (hierarchy check)
 *  2. Role scope matches branch level (scope check)
 *  3. User type is compatible with role level (user type check)
 *  4. No circular role hierarchy (parent check)
 *  5. Assignment doesn't create duplicate (uniqueness check)
 */
class RoleHierarchyValidator
{
    public function __construct(
        private readonly Connection $db,
        private readonly RoleHierarchyService $hierarchy,
        private readonly \App\Services\User\UserTypeService $userTypeService,
    ) {}

    /**
     * Comprehensive validation before assigning role to user at a node
     *
     * @throws \Exception with detailed validation error
     */
    public function validateAssignment(
        int $userId,
        int $roleId,
        int $branchId,
        int $companyId,
        ?int $actorId = null  // Actor performing the assignment (for privilege checks)
    ): bool {
        // 1. Verify all entities exist
        $this->validateEntityExists('users', $userId, $companyId);
        $this->validateEntityExists('roles', $roleId, $companyId);
        $this->validateEntityExists('branches', $branchId, $companyId);

        // 2. Check scope match
        if (!$this->hierarchy->validateScopeMatch($roleId, $branchId, $companyId)) {
            throw new \Exception(
                "Role scope does not match branch level. " .
                "Use role hierarchy page to reassign role to correct scope."
            );
        }

        // 3. Check user type compatibility
        $userType = $this->userTypeService->getUserType($userId);
        $roleLevel = $this->hierarchy->getRoleLevel($roleId, $companyId);

        if (!$this->isUserTypeCompatible($userType, $branchId, $companyId)) {
            throw new \Exception(
                "User type '{$userType}' cannot be assigned to roles at this branch level. " .
                "Consider promoting user to 'both' type."
            );
        }

        // 4. Check if assignment already exists
        if ($this->assignmentExists($userId, $roleId, $branchId)) {
            throw new \Exception("User already has this role at this branch.");
        }

        // 5. If actor provided, check their privilege to assign this role
        if ($actorId !== null) {
            $actorRoleId = $this->getUserRoleAtNode($actorId, $branchId);
            if (!$actorRoleId) {
                throw new \Exception("Actor does not have a role at this branch.");
            }

            if (!$this->hierarchy->canManageRole($actorRoleId, $roleId, $companyId)) {
                throw new \Exception(
                    "Actor does not have authority to assign this role. " .
                    "Only managers can assign roles to their subordinates."
                );
            }
        }

        return true;
    }

    /**
     * Validate role hierarchy integrity (no cycles, valid parents)
     */
    public function validateHierarchyIntegrity(int $roleId, int $companyId): bool
    {
        // Check for cycles
        $ancestors = $this->hierarchy->getAncestors($roleId, $companyId);
        if (in_array($roleId, $ancestors)) {
            throw new \Exception("Role hierarchy contains a cycle. Cannot proceed.");
        }

        // Check parent exists
        $parentId = $this->hierarchy->getParentRole($roleId, $companyId);
        if ($parentId !== null) {
            $exists = $this->db->fetchOne(
                "SELECT COUNT(*) FROM roles WHERE id = :id AND company_id = :company_id",
                ['id' => $parentId, 'company_id' => $companyId],
            );
            if (!$exists) {
                throw new \Exception("Parent role does not exist.");
            }
        }

        return true;
    }

    /**
     * Check if user type is compatible with branch level
     *
     * Rules:
     *  - 'office' users: can only be assigned at depth=0 (HQ)
     *  - 'branch' users: can only be assigned at depth≥1
     *  - 'both' users: can be assigned at any level
     */
    private function isUserTypeCompatible(string $userType, int $branchId, int $companyId): bool
    {
        $depth = $this->db->fetchOne(
            "SELECT depth FROM branches WHERE id = :id AND company_id = :company_id",
            ['id' => $branchId, 'company_id' => $companyId],
        );

        if ($depth === false || $depth === null) {
            return false;
        }

        $depth = (int)$depth;

        switch ($userType) {
            case 'office':
                return $depth === 0;  // HQ only

            case 'branch':
                return $depth >= 1;  // Branch or below

            case 'both':
                return true;  // Any level

            default:
                return false;
        }
    }

    /**
     * Check if assignment already exists (user + role + branch)
     */
    private function assignmentExists(int $userId, int $roleId, int $branchId): bool
    {
        $count = $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_node_roles
            WHERE user_id = :user_id
              AND role_id = :role_id
              AND node_id = :node_id",
            [
                'user_id' => $userId,
                'role_id' => $roleId,
                'node_id' => $branchId,
            ],
        );

        return (bool)$count;
    }

    /**
     * Get user's current role at a specific node
     * Returns the role_id if assignment exists, null otherwise
     */
    private function getUserRoleAtNode(int $userId, int $branchId): ?int
    {
        $result = $this->db->fetchOne(
            "SELECT role_id FROM user_node_roles
            WHERE user_id = :user_id AND node_id = :node_id
            LIMIT 1",
            ['user_id' => $userId, 'node_id' => $branchId],
        );
        return $result ? (int)$result : null;
    }

    /**
     * Validate that an entity exists in the database
     */
    private function validateEntityExists(string $table, int $id, int $companyId): void
    {
        $count = $this->db->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE id = :id AND company_id = :company_id",
            ['id' => $id, 'company_id' => $companyId],
        );

        if (!$count) {
            throw new \Exception("{$table} with id {$id} not found in company {$companyId}");
        }
    }

    /**
     * Batch validation of multiple assignments
     * @param array $assignments Array of ['user_id' => int, 'role_id' => int, 'branch_id' => int]
     */
    public function validateBatch(array $assignments, int $companyId): array
    {
        $errors = [];

        foreach ($assignments as $idx => $assignment) {
            try {
                $this->validateAssignment(
                    $assignment['user_id'],
                    $assignment['role_id'],
                    $assignment['branch_id'],
                    $companyId
                );
            } catch (\Exception $e) {
                $errors[$idx] = $e->getMessage();
            }
        }

        return $errors;  // Empty if all valid
    }
}
