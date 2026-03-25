<?php

namespace App\Services\User;

use Doctrine\DBAL\Connection;

/**
 * UserTypeService
 *
 * Manages user type classification (office, branch, both) and provides
 * utility methods for determining user context and capabilities.
 *
 * User Types:
 *  - 'office':  Can only be assigned to office/HQ/Regional level roles
 *  - 'branch':  Can only be assigned to branch-level roles
 *  - 'both':    Can be assigned to both office and branch roles
 */
class UserTypeService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Get user's current type
     */
    public function getUserType(int $userId): ?string
    {
        $result = $this->db->fetchOne("SELECT user_type FROM users WHERE id = :id", ['id' => $userId]);
        return $result ?: null;
    }

    /**
     * Check if user can be assigned office-level roles
     * Returns true if user_type is 'office' or 'both'
     */
    public function canBeOfficeUser(int $userId): bool
    {
        $type = $this->getUserType($userId);
        return in_array($type, ['office', 'both']);
    }

    /**
     * Check if user can be assigned branch-level roles
     * Returns true if user_type is 'branch' or 'both'
     */
    public function canBeBranchUser(int $userId): bool
    {
        $type = $this->getUserType($userId);
        return in_array($type, ['branch', 'both']);
    }

    /**
     * Infer user type from existing user_node_roles
     * Returns the inferred type without updating DB
     *
     * Logic:
     *  - If assigned only at depth=0 (HQ) → 'office'
     *  - If assigned only at depth≥1 (regional/branch) → 'branch'
     *  - If assigned at both levels → 'both'
     *  - No assignments → 'branch' (safe default)
     */
    public function inferUserType(int $userId): string
    {
        $result = $this->db->fetchAssociative(
            "SELECT
              COUNT(CASE WHEN b.depth = 0 THEN 1 END) as hq_count,
              COUNT(CASE WHEN b.depth > 0 THEN 1 END) as branch_count
            FROM user_node_roles unr
            JOIN branches b ON b.id = unr.node_id
            WHERE unr.user_id = :user_id",
            ['user_id' => $userId],
        );

        $hqCount = (int)($result['hq_count'] ?? 0);
        $branchCount = (int)($result['branch_count'] ?? 0);

        if ($hqCount > 0 && $branchCount > 0) {
            return 'both';
        } elseif ($hqCount > 0) {
            return 'office';
        } elseif ($branchCount > 0) {
            return 'branch';
        }

        return 'branch'; // default
    }

    /**
     * Update user's type in database
     */
    public function setUserType(int $userId, string $type): void
    {
        if (!in_array($type, ['office', 'branch', 'both'])) {
            throw new \InvalidArgumentException("Invalid user type: {$type}");
        }

        $this->db->update('users', ['user_type' => $type], ['id' => $userId]);
    }

    /**
     * Promote a user from one type to another
     * Only allows: 'branch' → 'both' or 'office' → 'both'
     */
    public function promoteUserType(int $userId, string $newType): void
    {
        $current = $this->getUserType($userId);

        // Only allow upgrades to 'both'
        if ($newType !== 'both') {
            throw new \InvalidArgumentException("Can only promote users to 'both' type");
        }

        if ($current === 'both') {
            throw new \InvalidArgumentException("User is already type 'both'");
        }

        $this->setUserType($userId, 'both');
    }

    /**
     * Get all office-level users (user_type IN ('office', 'both'))
     * @return array List of user IDs
     */
    public function getOfficeUsers(int $companyId): array
    {
        $results = $this->db->fetchAllAssociative(
            "SELECT id FROM users
            WHERE company_id = :company_id
              AND user_type IN ('office', 'both')
              AND deleted_at IS NULL
            ORDER BY name",
            ['company_id' => $companyId],
        );
        return array_column($results, 'id');
    }

    /**
     * Get all branch-level users (user_type IN ('branch', 'both'))
     * @return array List of user IDs
     */
    public function getBranchUsers(int $companyId): array
    {
        $results = $this->db->fetchAllAssociative(
            "SELECT id FROM users
            WHERE company_id = :company_id
              AND user_type IN ('branch', 'both')
              AND deleted_at IS NULL
            ORDER BY name",
            ['company_id' => $companyId],
        );
        return array_column($results, 'id');
    }

    /**
     * Get summary counts by type
     */
    public function getTypeCounts(int $companyId): array
    {
        $result = $this->db->fetchAllAssociative(
            "SELECT user_type, COUNT(*) as count
            FROM users
            WHERE company_id = :company_id AND deleted_at IS NULL
            GROUP BY user_type",
            ['company_id' => $companyId],
        );

        $counts = ['office' => 0, 'branch' => 0, 'both' => 0];
        foreach ($result as $row) {
            $counts[$row['user_type']] = (int)$row['count'];
        }

        return $counts;
    }
}
