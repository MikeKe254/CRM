<?php

declare(strict_types=1);

namespace App\Services\Branch;

use App\Services\Branch\BranchResolverService;
use App\Services\Branch\DTO\BranchNode;
use App\Services\Branch\Exception\BranchHasActiveUsersException;
use App\Services\Branch\Exception\BranchSlugTakenException;
use Doctrine\DBAL\Connection;

/**
 * Owns all branch tree structure operations.
 * Nothing outside this service should touch `path` or `depth` directly.
 *
 * Uses materialised path for O(1) ancestor/descendant queries — no recursive CTEs.
 * Per-request in-memory cache prevents repeated DB hits for the same node.
 */
final class BranchHierarchyService
{
    /** @var array<int, BranchNode> */
    private array $nodeCache      = [];
    /** @var array<int, int[]> */
    private array $ancestorCache  = [];
    /** @var array<int, int[]> */
    private array $descendantCache = [];

    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // TREE READS
    // =========================================================================

    /**
     * Returns all ancestor node IDs including the branch itself, ordered root → leaf.
     * Uses the materialised path — no recursive query needed.
     */
    public function getAncestorIds(int $branchId): array
    {
        if (isset($this->ancestorCache[$branchId])) {
            return $this->ancestorCache[$branchId];
        }

        $node = $this->findById($branchId);
        if ($node === null) {
            return $this->ancestorCache[$branchId] = [];
        }

        // Path looks like /1/5/12/ — extract the non-empty numeric segments
        $ids = array_filter(explode('/', $node->path), fn($s) => $s !== '');
        $ids = array_map('intval', array_values($ids));

        // Always include the branch itself — guards against stale paths where
        // the node's own ID was not yet written (e.g. migration placeholder used company_id)
        if (!in_array($branchId, $ids, true)) {
            $ids[] = $branchId;
        }

        return $this->ancestorCache[$branchId] = $ids;
    }

    /**
     * Returns IDs of all descendant nodes (NOT including the node itself).
     */
    public function getDescendantIds(int $branchId): array
    {
        if (isset($this->descendantCache[$branchId])) {
            return $this->descendantCache[$branchId];
        }

        $node = $this->findById($branchId);
        if ($node === null) {
            return $this->descendantCache[$branchId] = [];
        }

        $rows = $this->db->fetchFirstColumn(
            "SELECT id FROM branches
              WHERE path LIKE :prefix
                AND id != :id
                AND deleted_at IS NULL",
            ['prefix' => $node->path . '%', 'id' => $branchId],
        );

        return $this->descendantCache[$branchId] = array_map('intval', $rows);
    }

    /**
     * Returns IDs of node + all descendants.
     */
    public function getSubtreeIds(int $branchId): array
    {
        return array_merge([$branchId], $this->getDescendantIds($branchId));
    }

    /**
     * Returns sibling nodes (same parent_id, same company).
     *
     * @return BranchNode[]
     */
    public function getSiblings(int $branchId): array
    {
        $node = $this->findById($branchId);
        if ($node === null) return [];

        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM branches
              WHERE company_id = :company_id
                AND parent_id  = :parent_id
                AND id        != :id
                AND deleted_at IS NULL
              ORDER BY name',
            ['company_id' => $node->companyId, 'parent_id' => $node->parentId, 'id' => $branchId],
        );

        return array_map(BranchNode::fromRow(...), $rows);
    }

    /**
     * Returns the HQ root node for a company.
     */
    public function getRoot(int $companyId): ?BranchNode
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM branches
              WHERE company_id = :company_id
                AND is_hq      = 1
                AND deleted_at IS NULL
              LIMIT 1',
            ['company_id' => $companyId],
        );

        return $row ? BranchNode::fromRow($row) : null;
    }

    public function findBySlug(int $companyId, string $slug): ?BranchNode
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM branches
              WHERE company_id = :company_id
                AND slug       = :slug
                AND deleted_at IS NULL
              LIMIT 1',
            ['company_id' => $companyId, 'slug' => $slug],
        );

        return $row ? BranchNode::fromRow($row) : null;
    }

    public function findById(int $branchId): ?BranchNode
    {
        if (isset($this->nodeCache[$branchId])) {
            return $this->nodeCache[$branchId];
        }

        $row = $this->db->fetchAssociative(
            'SELECT * FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $branchId],
        );

        if (!$row) return null;

        return $this->nodeCache[$branchId] = BranchNode::fromRow($row);
    }

    /**
     * Returns all active branches for a company as a flat list, ordered root → leaf.
     * Used when loading branch lists for users that bypass normal access validation
     * (e.g. platform admins who can see every branch without a role assignment).
     *
     * @return BranchNode[]
     */
    public function getAll(int $companyId): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM branches
              WHERE company_id = :company_id
                AND status     = 'active'
                AND deleted_at IS NULL
              ORDER BY depth ASC, is_hq DESC, name ASC",
            ['company_id' => $companyId],
        );

        return array_map(BranchNode::fromRow(...), $rows);
    }

    /**
     * Builds a nested BranchNode tree for the whole company.
     *
     * @return BranchNode[]  top-level nodes (HQ first)
     */
    public function buildTree(int $companyId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM branches
              WHERE company_id = :company_id
                AND deleted_at IS NULL
              ORDER BY depth ASC, is_hq DESC, name ASC',
            ['company_id' => $companyId],
        );

        /** @var array<int, BranchNode> $map */
        $map   = [];
        $roots = [];

        foreach ($rows as $row) {
            $node = BranchNode::fromRow($row);
            $map[$node->id] = $node;
        }

        foreach ($map as $node) {
            if ($node->parentId === null) {
                $roots[] = $node;
            } else {
                if (isset($map[$node->parentId])) {
                    $map[$node->parentId]->children[] = $node;
                }
            }
        }

        return $roots;
    }

    public function getDepth(int $branchId): int
    {
        return $this->findById($branchId)?->depth ?? 0;
    }

    // =========================================================================
    // TREE WRITES
    // =========================================================================

    /**
     * Create a new branch node. Computes path and depth automatically.
     */
    /**
     * Create a system branch node (hq, head-office-branch).
     * Bypasses the reserved-slug guard — only for use by CompanySetupService.
     * All other code must use createNode().
     */
    public function createSystemNode(
        int     $companyId,
        ?int    $parentId,
        string  $name,
        string  $slug,
        string  $type = 'branch',
    ): BranchNode {
        if ($this->findBySlug($companyId, $slug) !== null) {
            throw new BranchSlugTakenException($slug);
        }

        return $this->insertNode($companyId, $parentId, $name, $slug, $type);
    }

    public function createNode(
        int     $companyId,
        ?int    $parentId,
        string  $name,
        string  $slug,
        string  $type = 'branch',
    ): BranchNode {
        // Reject reserved platform slugs — they would conflict with virtual URL contexts.
        if (in_array($slug, BranchResolverService::RESERVED_SLUGS, true)) {
            throw new BranchSlugTakenException($slug);
        }

        // Validate slug uniqueness
        if ($this->findBySlug($companyId, $slug) !== null) {
            throw new BranchSlugTakenException($slug);
        }

        return $this->insertNode($companyId, $parentId, $name, $slug, $type);
    }

    private function insertNode(
        int     $companyId,
        ?int    $parentId,
        string  $name,
        string  $slug,
        string  $type,
    ): BranchNode {
        if ($parentId !== null) {
            $parent = $this->findById($parentId);
            $depth  = ($parent?->depth ?? 0) + 1;
        } else {
            $parent = null;
            $depth  = 0;
        }

        $this->db->insert('branches', [
            'company_id' => $companyId,
            'parent_id'  => $parentId,
            'name'       => $name,
            'slug'       => $slug,
            'type'       => $type,
            'path'       => '/', // temporary — updated below with actual ID
            'depth'      => $depth,
            'is_hq'      => $type === 'hq' ? 1 : 0,
            'status'     => 'active',
        ]);

        $newId = (int) $this->db->lastInsertId();

        // Build final path: parent.path + newId + /
        $path = ($parent !== null ? $parent->path : '/') . $newId . '/';

        $this->db->executeStatement(
            'UPDATE branches SET path = :path WHERE id = :id',
            ['path' => $path, 'id' => $newId],
        );

        return $this->findById($newId);
    }

    /**
     * Rename a node. Slug is immutable — only display name changes.
     */
    public function renameNode(int $branchId, string $newName): void
    {
        $this->db->executeStatement(
            'UPDATE branches SET name = :name WHERE id = :id',
            ['name' => $newName, 'id' => $branchId],
        );

        unset($this->nodeCache[$branchId]);
    }

    /**
     * Move a node to a new parent. Recomputes path and depth for
     * the node and ALL its descendants.
     */
    public function moveNode(int $branchId, int $newParentId): void
    {
        $node      = $this->findById($branchId);
        $newParent = $this->findById($newParentId);

        if ($node === null || $newParent === null) return;

        // Prevent moving into own subtree
        if (str_starts_with($newParent->path, $node->path)) {
            throw new \InvalidArgumentException('Cannot move a branch into its own subtree.');
        }

        $oldPath  = $node->path;
        $newPath  = $newParent->path . $branchId . '/';
        $newDepth = $newParent->depth + 1;
        $depthDiff = $newDepth - $node->depth;

        // Update the node itself
        $this->db->executeStatement(
            'UPDATE branches SET parent_id = :parent_id, path = :path, depth = :depth WHERE id = :id',
            ['parent_id' => $newParentId, 'path' => $newPath, 'depth' => $newDepth, 'id' => $branchId],
        );

        // Update all descendants — replace old path prefix with new path
        $descendants = $this->getDescendantIds($branchId);
        foreach ($descendants as $descId) {
            $desc    = $this->findById($descId);
            if ($desc === null) continue;
            $newDescPath  = $newPath . substr($desc->path, strlen($oldPath));
            $newDescDepth = $desc->depth + $depthDiff;
            $this->db->executeStatement(
                'UPDATE branches SET path = :path, depth = :depth WHERE id = :id',
                ['path' => $newDescPath, 'depth' => $newDescDepth, 'id' => $descId],
            );
        }

        // Bust cache
        $this->nodeCache      = [];
        $this->ancestorCache  = [];
        $this->descendantCache = [];
    }

    /**
     * Soft-deactivate a node and all its descendants.
     */
    public function deactivateNode(int $branchId): void
    {
        $node = $this->findById($branchId);
        if ($node === null) return;

        $this->db->executeStatement(
            "UPDATE branches SET status = 'inactive' WHERE path LIKE :prefix OR id = :id",
            ['prefix' => $node->path . '%', 'id' => $branchId],
        );

        $this->nodeCache = [];
    }

    /**
     * Soft-delete a node and all its descendants.
     * Throws if active user assignments exist in the subtree.
     */
    public function deleteNode(int $branchId): void
    {
        $node = $this->findById($branchId);
        if ($node === null) return;

        $subtreeIds = $this->getSubtreeIds($branchId);

        // Check for active users
        $activeUsers = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM user_node_roles WHERE node_id IN (' .
                implode(',', array_fill(0, count($subtreeIds), '?')) . ')',
            $subtreeIds,
        );

        if ($activeUsers > 0) {
            throw new BranchHasActiveUsersException($node->name);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->executeStatement(
            "UPDATE branches SET deleted_at = :now WHERE path LIKE :prefix OR id = :id",
            ['now' => $now, 'prefix' => $node->path . '%', 'id' => $branchId],
        );

        $this->nodeCache      = [];
        $this->ancestorCache  = [];
        $this->descendantCache = [];
    }
}
