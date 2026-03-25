<?php

declare(strict_types=1);

namespace App\Services\Branch;

use App\Services\Auth\DTO\AuthCompany;
use App\Services\Branch\DTO\BranchContext;
use App\Services\Branch\DTO\BranchNode;
use App\Services\Branch\DTO\BranchPickerResult;
use App\Services\Branch\Exception\BranchAccessDeniedException;
use App\Services\Branch\Exception\BranchInactiveException;
use App\Services\Branch\Exception\NoBranchAssignmentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a branch from the URL slug, validates access,
 * and builds the BranchContext attached to every admin session.
 *
 * Called once per request inside AdminBaseController::requireAdmin().
 *
 * Reserved slugs (cannot be used as real branch slugs):
 *   'overall' — company-wide Overall Manager strategic context
 */
final class BranchResolverService
{
    /** Slug reserved for the company-wide Overall Manager context. */
    public const OVERALL_SLUG = 'overall';

    /**
     * The default operational branch — always created alongside every company.
     * This is the branch every user falls back to when multi-branch is disabled.
     * It cannot be deleted, renamed (slug), or reassigned.
     */
    public const HEAD_OFFICE_SLUG = 'head-office-branch';

    /**
     * The HQ structural root node — always created alongside every company.
     * Only meaningful when multi-branch is enabled; used as the hierarchy root.
     */
    public const HQ_SLUG = 'hq';

    /**
     * Slugs that are reserved and must never be used as real branch names.
     * Enforced in BranchController::create() and BranchHierarchyService::createNode().
     */
    public const RESERVED_SLUGS = [self::OVERALL_SLUG, self::HEAD_OFFICE_SLUG, self::HQ_SLUG];

    public function __construct(
        private readonly BranchHierarchyService  $hierarchy,
        private readonly BranchPermissionService $permissions,
    ) {}

    /**
     * Resolve branch from {branch} URL attribute, validate access, build context.
     *
     * The reserved slug 'overall' resolves to the company HQ node and sets
     * context='overall', granting the Overall Manager strategic view.
     *
     * @throws BranchAccessDeniedException if user has no role here or any ancestor
     * @throws BranchInactiveException     if the branch is inactive or deleted
     * @throws \RuntimeException           if branch slug does not exist
     */
    public function resolveFromRequest(
        Request     $request,
        int         $userId,
        int         $companyId,
        AuthCompany $company,
    ): BranchContext {
        $slug = (string) $request->attributes->get('branch', '');

        // ── Reserved: /overall/ ─────────────────────────────────────────────
        // Not a real branch — resolves to the HQ node with context='overall'.
        // Only accessible to users with assign_branch_heads at HQ (Overall Manager).
        if ($slug === self::OVERALL_SLUG) {
            return $this->resolveOverallContext($userId, $companyId, $company);
        }

        $branch = $this->hierarchy->findBySlug($companyId, $slug);

        if ($branch === null) {
            throw new \RuntimeException("Branch \"{$slug}\" not found.");
        }

        if ($branch->status !== 'active') {
            throw new BranchInactiveException($branch->name);
        }

        if (!$this->permissions->validateAccess($userId, $branch->id)) {
            throw new BranchAccessDeniedException($slug);
        }

        $effectivePermissions = $this->permissions->getEffectivePermissions($userId, $branch->id);
        $availableBranches    = $this->permissions->getAccessibleBranches($userId, $companyId);

        return new BranchContext(
            branch:               $branch,
            company:              $company,
            availableBranches:    $availableBranches,
            effectivePermissions: $effectivePermissions,
            context:              'operational',
        );
    }

    /**
     * Resolve the /overall/ virtual context.
     * Binds to the company HQ node for permission resolution but marks
     * context='overall' so controllers and the sidebar show the OM strategic view.
     *
     * @throws BranchAccessDeniedException if user does not hold assign_branch_heads at HQ
     * @throws \RuntimeException           if no HQ branch exists yet
     */
    private function resolveOverallContext(int $userId, int $companyId, AuthCompany $company): BranchContext
    {
        $hq = $this->hierarchy->getRoot($companyId);

        if ($hq === null) {
            throw new \RuntimeException('No HQ branch is configured for this company yet.');
        }

        if (!$this->permissions->check($userId, $hq->id, 'assign_branch_heads')) {
            throw new BranchAccessDeniedException(self::OVERALL_SLUG);
        }

        $effectivePermissions = $this->permissions->getEffectivePermissions($userId, $hq->id);
        $availableBranches    = $this->permissions->getAccessibleBranches($userId, $companyId);

        return new BranchContext(
            branch:               $hq,
            company:              $company,
            availableBranches:    $availableBranches,
            effectivePermissions: $effectivePermissions,
            context:              'overall',
        );
    }

    /**
     * Resolve branch context for a platform admin visiting a tenant admin route.
     *
     * Platform admins have no user_node_roles entries — they bypass access validation
     * entirely. This method loads all company branches and resolves whichever slug is
     * in the URL (or falls back to HQ for the 'overall' virtual context).
     *
     * The returned EffectivePermissions is an empty stub; platform admins are granted
     * full access through PlatformCheckPermissionService, not through role assignments.
     *
     * @throws BranchInactiveException if the target branch is inactive
     * @throws \RuntimeException       if the slug does not match any branch
     */
    public function resolveForPlatformAdmin(
        Request     $request,
        int         $companyId,
        AuthCompany $company,
    ): BranchContext {
        $slug        = (string) $request->attributes->get('branch', '');
        $allBranches = $this->hierarchy->getAll($companyId);

        // Overall / no slug → resolve to HQ, context='overall'
        if ($slug === self::OVERALL_SLUG || $slug === '') {
            $hq = $this->hierarchy->getRoot($companyId);
            return new BranchContext(
                branch:               $hq,
                company:              $company,
                availableBranches:    $allBranches,
                effectivePermissions: new \App\Services\Branch\DTO\EffectivePermissions(
                    0, $hq?->id ?? 0, [], [], new \DateTimeImmutable(),
                ),
                context: 'overall',
            );
        }

        $branch = $this->hierarchy->findBySlug($companyId, $slug);

        if ($branch === null) {
            throw new \RuntimeException("Branch \"{$slug}\" not found.");
        }

        if ($branch->status !== 'active') {
            throw new BranchInactiveException($branch->name);
        }

        return new BranchContext(
            branch:               $branch,
            company:              $company,
            availableBranches:    $allBranches,
            effectivePermissions: new \App\Services\Branch\DTO\EffectivePermissions(
                0, $branch->id, [], [], new \DateTimeImmutable(),
            ),
            context: 'operational',
        );
    }

    /**
     * Returns the branch marked is_primary in user_node_roles.
     * Used for post-login redirect when user has a designated primary.
     */
    public function resolvePrimaryBranch(int $userId, int $companyId): ?BranchNode
    {
        return $this->permissions
            ->getAccessibleBranches($userId, $companyId)[0] // getAccessibleBranches sorts primary first
            ?? null;
    }

    /**
     * Returns all active branches the user is assigned to, sorted primary first.
     *
     * @return BranchNode[]
     */
    public function getBranchesForPicker(int $userId, int $companyId): array
    {
        return $this->permissions->getAccessibleBranches($userId, $companyId);
    }

    /**
     * Determines where to send the user after login.
     *
     * Overall Managers (users with assign_branch_heads at HQ) always land on
     * /overall/ regardless of how many branches they can access — they can
     * switch to any operational branch via the sidebar.
     *
     * @throws NoBranchAssignmentException if the user has no accessible branches
     */
    public function resolvePostLogin(int $userId, int $companyId): BranchPickerResult
    {
        $branches = $this->permissions->getAccessibleBranches($userId, $companyId);

        if (empty($branches)) {
            throw new NoBranchAssignmentException();
        }

        // Overall Manager → always land on /overall/
        $hq = $this->hierarchy->getRoot($companyId);
        if ($hq !== null && $this->permissions->check($userId, $hq->id, 'assign_branch_heads')) {
            // Build a virtual BranchNode with slug='overall' so the redirect URL
            // becomes /{branch}=overall/dashboard — resolveFromRequest handles the rest.
            $overallNode = new BranchNode(
                id:        $hq->id,
                companyId: $companyId,
                parentId:  null,
                name:      'Overall',
                slug:      self::OVERALL_SLUG,
                type:      'hq',
                path:      $hq->path,
                depth:     0,
                isHq:      true,
                status:    'active',
            );
            return BranchPickerResult::direct($overallNode);
        }

        if (count($branches) === 1) {
            return BranchPickerResult::direct($branches[0]);
        }

        return BranchPickerResult::picker($branches);
    }
}
