<?php

declare(strict_types=1);

namespace App\Services\Branch\DTO;

final class AuthorityScope
{
    /**
     * @param int[] $accessibleBranchIds  branches this user can operate in
     * @param int[] $manageableBranchIds  branches where user can manage users/roles
     */
    public function __construct(
        public readonly int   $userId,
        public readonly int   $fromNodeId,
        public readonly array $accessibleBranchIds,
        public readonly array $manageableBranchIds,
    ) {}

    public function canAccess(int $branchId): bool
    {
        return in_array($branchId, $this->accessibleBranchIds, true);
    }

    public function canManage(int $branchId): bool
    {
        return in_array($branchId, $this->manageableBranchIds, true);
    }
}
