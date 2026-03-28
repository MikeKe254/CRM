<?php

declare(strict_types=1);

namespace App\Services\Company;

use App\Services\Branch\BranchHierarchyService;
use App\Services\Branch\BranchResolverService;
use App\Services\Branch\Exception\BranchSlugTakenException;
use App\Services\Company\DepartmentService;
use App\Services\Company\AreaService;

/**
 * Bootstraps a freshly created company with the required branch structure.
 *
 * Every company always gets two branches:
 *
 *   hq  (type=hq, depth=0)
 *   └── head-office-branch  (type=branch, depth=1)
 *
 * The 'hq' node is the structural root used by the multi-branch hierarchy.
 * It is only meaningfully accessible when multi-branch is enabled.
 *
 * The 'head-office-branch' is the default working branch for every user.
 * It is always present regardless of whether multi-branch is enabled.
 *
 * Call bootstrapBranches() immediately after inserting a new company row.
 */
final class CompanySetupService
{
    public function __construct(
        private readonly BranchHierarchyService $hierarchy,
        private readonly DepartmentService      $departments,
        private readonly AreaService            $areas,
    ) {}

    /**
     * Create the HQ root node and the default Head Office branch.
     * Safe to call only once — will not duplicate if branches already exist.
     *
     * @throws \RuntimeException if setup fails unexpectedly
     */
    public function bootstrapBranches(int $companyId): void
    {
        // Guard: skip if already bootstrapped
        $existing = $this->hierarchy->findBySlug($companyId, BranchResolverService::HQ_SLUG);
        if ($existing !== null) {
            return;
        }

        // 1. HQ root node (structural, multi-branch feature)
        $hq = $this->hierarchy->createSystemNode(
            companyId: $companyId,
            parentId:  null,
            name:      'HQ',
            slug:      BranchResolverService::HQ_SLUG,
            type:      'hq',
        );

        // 2. Head Office Branch — the always-present default working branch
        $headOfficeBranch = $this->hierarchy->createSystemNode(
            companyId: $companyId,
            parentId:  $hq->id,
            name:      'Head Office',
            slug:      BranchResolverService::HEAD_OFFICE_SLUG,
            type:      'branch',
        );

        // 3. Seed system departments and areas into the head-office branch
        $this->departments->bootstrapDefaults($companyId, $headOfficeBranch->id);
        $this->areas->bootstrapDefaults($companyId, $headOfficeBranch->id);
    }
}
