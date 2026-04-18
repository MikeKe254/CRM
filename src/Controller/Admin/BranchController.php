<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchHierarchyService;
use App\Services\Branch\BranchPermissionService;
use App\Services\Branch\BranchResolverService;
use App\Services\Branch\Exception\BranchHasActiveUsersException;
use App\Services\Branch\Exception\BranchSlugTakenException;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/branches', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class BranchController extends AdminBaseController
{
    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService          $branchResolver,
        Connection                     $db,
        private readonly BranchHierarchyService    $hierarchy,
        private readonly BranchPermissionService   $branchPermissions,
        private readonly UserActivityLogService    $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // GET /dashboard/admin/branches — Tree view (Twig)
    // =========================================================================

    #[Route('', name: 'admin_branches', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'manage_branches');
        if ($session instanceof Response) return $session;
        if ($r = $this->requireMultiBranch($request, $session)) return $r;

        // Build the branch tree scoped by context:
        //   overall / superadmin → full company tree
        //   region context       → subtree rooted at this region node
        //   hq branch / other    → full company tree (HQ branch admins see everything)
        $isOverall  = ($session->context ?? 'operational') === 'overall';
        $isRegion   = $session->branch !== null && $session->branch->type === 'region' && !$isOverall;

        if ($isRegion) {
            // Regional context: show only this region's subtree.
            // Applies to both regular regional managers and platform admins viewing a region.
            $subtreeIds = $this->hierarchy->getSubtreeIds($session->branch->id);
            $placeholders = implode(',', array_fill(0, count($subtreeIds), '?'));
            $rows = $this->db->fetchAllAssociative(
                "SELECT * FROM branches
                  WHERE id IN ({$placeholders})
                    AND deleted_at IS NULL
                  ORDER BY depth ASC, is_hq DESC, name ASC",
                $subtreeIds,
            );
            // Build a partial tree from these rows
            $map   = [];
            $roots = [];
            foreach ($rows as $row) {
                $map[(int) $row['id']] = \App\Services\Branch\DTO\BranchNode::fromRow($row);
            }
            foreach ($map as $node) {
                if ($node->parentId === null || !isset($map[$node->parentId])) {
                    $roots[] = $node;
                } else {
                    $map[$node->parentId]->children[] = $node;
                }
            }
            $tree = $roots;
        } else {
            $tree = $this->hierarchy->buildTree($session->company->id);
        }

        // Collect branches for the "move to" parent selector dropdown.
        // Same scope as the tree — regional context sees only its subtree.
        if ($isRegion) {
            $allBranches = $this->db->fetchAllAssociative(
                "SELECT id, name, depth, is_hq, path FROM branches
                  WHERE id IN ({$placeholders})
                    AND deleted_at IS NULL
                  ORDER BY depth ASC, is_hq DESC, name ASC",
                $subtreeIds,
            );
        } else {
            $allBranches = $this->db->fetchAllAssociative(
                'SELECT id, name, depth, is_hq, path FROM branches
                  WHERE company_id = :company_id
                    AND deleted_at IS NULL
                  ORDER BY depth ASC, is_hq DESC, name ASC',
                ['company_id' => $session->company->id],
            );
        }

        return $this->render('admin/branches/index.html.twig', [
            'session'     => $session,
            'tree'        => $tree,
            'allBranches' => $allBranches,
            'can'         => [
                'create'     => $this->can->check($session, 'manage_branches'),
                'edit'       => $this->can->check($session, 'manage_branches'),
                'deactivate' => $this->can->check($session, 'manage_branches'),
                'delete'     => $this->can->check($session, 'manage_branches'),
            ],
        ]);
    }

    // =========================================================================
    // POST /dashboard/admin/branches/create — Create child branch (fetch)
    // =========================================================================

    #[Route('/create', name: 'admin_branches_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'manage_branches');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);
        if ($r = $this->requireMultiBranch($request, $session)) return $r;

        $name     = trim((string) $request->request->get('name', ''));
        $slug     = trim((string) $request->request->get('slug', ''));
        $parentId = (int) $request->request->get('parent_id', 0);

        if ($name === '') return $this->error('Branch name is required.');
        if ($slug === '') return $this->error('Branch slug is required.');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return $this->error('Slug may only contain lowercase letters, numbers and hyphens.');
        }
        if (in_array($slug, BranchResolverService::RESERVED_SLUGS, true)) {
            return $this->error("The slug \"{$slug}\" is reserved by the system and cannot be used.");
        }

        // Validate parent belongs to this company
        if ($parentId > 0) {
            $parent = $this->hierarchy->findById($parentId);
            if ($parent === null || $parent->companyId !== $session->company->id) {
                return $this->error('Parent branch not found.', 404);
            }
            // Non-superadmins can only create under their authority scope
            if (!$session->user->isSuperAdmin && $session->branch !== null) {
                $scope = $this->branchPermissions->getAuthorityScope($session->user->id, $session->branch->id);
                if (!in_array($parentId, $scope->accessibleBranchIds, true)) {
                    return $this->error('You do not have authority over the selected parent branch.', 403);
                }
            }
        } else {
            // Default to active branch as parent
            if ($session->branch !== null) {
                $parentId = $session->branch->id;
                $parent   = $this->hierarchy->findById($parentId);
                if ($parent === null) {
                    return $this->error('Parent branch not found.', 404);
                }
            } else {
                return $this->error('Parent branch is required.');
            }
        }

        // Branches are leaf nodes — they cannot have children
        if ($parent->type === 'branch' && !$parent->isHq) {
            return $this->error('Branch nodes cannot have sub-branches. Add children under a Region or HQ node.');
        }

        // Type derivation:
        //   Non-HQ parent → always 'branch'
        //   HQ parent     → 'region' by default, or 'branch' if explicitly requested
        //                    (one operational branch per HQ is allowed)
        if ($parent->isHq) {
            $requestedType = $request->request->get('type', 'region');
            $type = in_array($requestedType, ['region', 'branch'], true) ? $requestedType : 'region';
        } else {
            $type = 'branch';
        }

        // Enforce: HQ may have at most 1 direct branch child (its operational branch)
        if ($parent->isHq && $type === 'branch') {
            $existingHqBranch = $this->db->fetchOne(
                "SELECT id FROM branches WHERE parent_id = :pid AND type = 'branch' AND deleted_at IS NULL LIMIT 1",
                ['pid' => $parentId],
            );
            if ($existingHqBranch) {
                return $this->error('The HQ node already has an operational branch. Remove or reassign it before creating a new one.');
            }
        }

        try {
            $node = $this->hierarchy->createNode(
                companyId: $session->company->id,
                parentId: $parentId,
                name: $name,
                slug: $slug,
                type: $type,
            );
        } catch (BranchSlugTakenException) {
            return $this->error("The slug \"{$slug}\" is already in use. Choose a different slug.");
        }

        // Auto-copy all operational role templates for the new node.
        // Each branch/region gets its own independent role instances so
        // permissions can be customised without affecting other branches.
        $this->copyOperationalRoles($session->company->id, $node->id);

        $this->activityLog->record($session, 'branch.create',
            ['name' => $name, 'slug' => $slug, 'type' => $type],
            permission: 'manage_branches', subjectType: 'branch', subjectId: $node->id, request: $request,
        );

        return $this->success("Branch \"{$name}\" created successfully.", ['id' => $node->id, 'slug' => $node->slug]);
    }

    // =========================================================================
    // POST /dashboard/admin/branches/{id}/rename — Rename branch (fetch)
    // =========================================================================

    #[Route('/{id}/rename', name: 'admin_branches_rename', methods: ['POST'])]
    public function rename(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'manage_branches');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);
        if ($r = $this->requireMultiBranch($request, $session)) return $r;

        $newName = trim((string) $request->request->get('name', ''));
        if ($newName === '') return $this->error('Name is required.');

        $branch = $this->hierarchy->findById($id);
        if ($branch === null || $branch->companyId !== $session->company->id) {
            return $this->error('Branch not found.', 404);
        }

        if ($err = $this->assertAuthority($session, $id)) return $err;

        $oldName = $branch->name;
        $this->hierarchy->renameNode($id, $newName);

        $this->activityLog->record($session, 'branch.rename',
            ['old_name' => $oldName, 'new_name' => $newName],
            permission: 'manage_branches', subjectType: 'branch', subjectId: $id, request: $request,
        );

        return $this->success("Branch renamed to \"{$newName}\".");
    }

    // =========================================================================
    // POST /dashboard/admin/branches/{id}/move — Move branch (fetch)
    // =========================================================================

    #[Route('/{id}/move', name: 'admin_branches_move', methods: ['POST'])]
    public function move(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'manage_branches');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);
        if ($r = $this->requireMultiBranch($request, $session)) return $r;

        $newParentId = (int) $request->request->get('parent_id', 0);
        if ($newParentId <= 0) return $this->error('Target parent branch is required.');

        $branch = $this->hierarchy->findById($id);
        if ($branch === null || $branch->companyId !== $session->company->id) {
            return $this->error('Branch not found.', 404);
        }

        if ($branch->isHq) return $this->error('The HQ branch cannot be moved.');

        $newParent = $this->hierarchy->findById($newParentId);
        if ($newParent === null || $newParent->companyId !== $session->company->id) {
            return $this->error('Target parent branch not found.', 404);
        }

        // Branches are leaf nodes — cannot move anything under a branch
        if ($newParent->type === 'branch' && !$newParent->isHq) {
            return $this->error('Branch nodes cannot have children. Move under a Region or HQ node instead.');
        }

        // HQ may have at most 1 direct branch child
        if ($newParent->isHq && $branch->type === 'branch') {
            $existingHqBranch = $this->db->fetchOne(
                "SELECT id FROM branches WHERE parent_id = :pid AND type = 'branch' AND id != :self AND deleted_at IS NULL LIMIT 1",
                ['pid' => $newParentId, 'self' => $id],
            );
            if ($existingHqBranch) {
                return $this->error('The HQ node already has an operational branch. Remove or reassign it before moving another branch here.');
            }
        }

        if ($err = $this->assertAuthority($session, $id)) return $err;

        try {
            $this->hierarchy->moveNode($id, $newParentId);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        $this->activityLog->record($session, 'branch.move',
            ['branch' => $branch->name, 'new_parent' => $newParent->name],
            permission: 'manage_branches', subjectType: 'branch', subjectId: $id, request: $request,
        );

        return $this->success("\"{$branch->name}\" moved under \"{$newParent->name}\".");
    }

    // =========================================================================
    // POST /dashboard/admin/branches/{id}/deactivate — Deactivate branch (fetch)
    // =========================================================================

    #[Route('/{id}/deactivate', name: 'admin_branches_deactivate', methods: ['POST'])]
    public function deactivate(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'manage_branches');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);
        if ($r = $this->requireMultiBranch($request, $session)) return $r;

        $branch = $this->hierarchy->findById($id);
        if ($branch === null || $branch->companyId !== $session->company->id) {
            return $this->error('Branch not found.', 404);
        }

        if ($branch->isHq) return $this->error('The HQ branch cannot be deactivated.');
        if ($branch->status === 'inactive') return $this->error('Branch is already inactive.');

        // Cannot deactivate the branch the actor is currently operating from
        if ($session->branch !== null && $session->branch->id === $id) {
            return $this->error('You cannot deactivate your currently active branch.');
        }

        if ($err = $this->assertAuthority($session, $id)) return $err;

        $this->hierarchy->deactivateNode($id);

        $this->activityLog->record($session, 'branch.deactivate',
            ['name' => $branch->name],
            permission: 'manage_branches', subjectType: 'branch', subjectId: $id, request: $request,
        );

        return $this->success("\"{$branch->name}\" has been deactivated.");
    }

    // =========================================================================
    // POST /dashboard/admin/branches/{id}/activate — Reactivate branch (fetch)
    // =========================================================================

    #[Route('/{id}/activate', name: 'admin_branches_activate', methods: ['POST'])]
    public function activate(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'manage_branches');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);
        if ($r = $this->requireMultiBranch($request, $session)) return $r;

        // For inactive branches, findById won't find them since deleted_at IS NULL filter is fine
        // but we need to also see inactive ones — query directly
        $row = $this->db->fetchAssociative(
            'SELECT * FROM branches WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$row) return $this->error('Branch not found.', 404);

        if ($row['status'] === 'active') return $this->error('Branch is already active.');

        if ($err = $this->assertAuthority($session, $id)) return $err;

        $this->db->executeStatement(
            "UPDATE branches SET status = 'active' WHERE id = :id",
            ['id' => $id],
        );

        $this->activityLog->record($session, 'branch.activate',
            ['name' => $row['name']],
            permission: 'manage_branches', subjectType: 'branch', subjectId: $id, request: $request,
        );

        return $this->success("\"{$row['name']}\" has been reactivated.");
    }

    // =========================================================================
    // POST /dashboard/admin/branches/{id}/delete — Soft-delete branch (fetch)
    // =========================================================================

    #[Route('/{id}/delete', name: 'admin_branches_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'manage_branches');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);
        if ($r = $this->requireMultiBranch($request, $session)) return $r;

        $branch = $this->hierarchy->findById($id);
        if ($branch === null || $branch->companyId !== $session->company->id) {
            return $this->error('Branch not found.', 404);
        }

        if ($branch->isHq) return $this->error('The HQ branch cannot be deleted.');

        if ($session->branch !== null && $session->branch->id === $id) {
            return $this->error('You cannot delete your currently active branch.');
        }

        if ($err = $this->assertAuthority($session, $id)) return $err;

        try {
            $this->hierarchy->deleteNode($id);
        } catch (BranchHasActiveUsersException $e) {
            return $this->error($e->getMessage());
        }

        $this->activityLog->record($session, 'branch.delete',
            ['name' => $branch->name, 'slug' => $branch->slug],
            permission: 'manage_branches', subjectType: 'branch', subjectId: $id, request: $request,
        );

        return $this->success("\"{$branch->name}\" has been deleted.");
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Returns a 403 JSON error if the actor does not have authority over the given branch.
     * Superadmins bypass this check.
     */
    /**
     * Copy all operational role templates to a newly-created branch node.
     * Each branch gets its own independent role instances (is_head_role=0,
     * branch_id IS NULL) so permissions can be customised per-branch.
     */
    private function copyOperationalRoles(int $companyId, int $branchId): void
    {
        $templates = $this->db->fetchAllAssociative(
            'SELECT id, name, description, scope
               FROM roles
              WHERE company_id = :cid
                AND is_head_role = 0
                AND branch_id IS NULL
                AND deleted_at IS NULL',
            ['cid' => $companyId],
        );

        foreach ($templates as $t) {
            $this->db->insert('roles', [
                'company_id'     => $companyId,
                'branch_id'      => $branchId,
                'source_role_id' => $t['id'],
                'name'           => $t['name'],
                'description'    => $t['description'],
                'is_system_role' => 0,
                'is_head_role'   => 0,
                'scope'          => $t['scope'],
            ]);
            $newRoleId = (int) $this->db->lastInsertId();

            // Copy all permissions from the template
            $this->db->executeStatement(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT :new_id, permission_id FROM role_permissions WHERE role_id = :src_id',
                ['new_id' => $newRoleId, 'src_id' => $t['id']],
            );
        }
    }

    /**
     * Returns a 403 JSON error if the actor does not have authority over the given branch.
     * Superadmins bypass this check.
     */
    private function assertAuthority(mixed $session, int $branchId): ?JsonResponse
    {
        if ($session->user->isSuperAdmin) return null;
        if ($session->branch === null) return null;

        $scope = $this->branchPermissions->getAuthorityScope($session->user->id, $session->branch->id);
        if (!in_array($branchId, $scope->accessibleBranchIds, true)) {
            return $this->error('You do not have authority over this branch.', 403);
        }

        return null;
    }
}
