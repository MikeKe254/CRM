<?php

declare(strict_types=1);

namespace App\Services\Branch\DTO;

use App\Services\Auth\DTO\AuthCompany;

final class BranchContext
{
    /**
     * @param BranchNode[] $availableBranches
     * @param string $context  'overall' when user is at the company-wide OM context (/overall/ URL);
     *                         'operational' for all regular branch/region/hq URLs.
     */
    public function __construct(
        public readonly BranchNode           $branch,
        public readonly AuthCompany          $company,
        public readonly array                $availableBranches,
        public readonly EffectivePermissions $effectivePermissions,
        public readonly string               $context = 'operational',
    ) {}
}
