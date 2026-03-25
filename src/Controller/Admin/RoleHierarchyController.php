<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Branch\BranchPermissionService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use App\Services\Role\RoleHierarchyService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/roles-hierarchy', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class RoleHierarchyController extends AdminBaseController
{
    public function __construct(
        AuthService                      $auth,
        CheckPermissionService           $can,
        PlatformCheckPermissionService   $platformCan,
        BranchResolverService            $branchResolver,
        Connection                       $db,
        private readonly BranchPermissionService $branchPermissions,
        private readonly RoleHierarchyService $hierarchy,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    /**
     * Display role hierarchy tree
     */
    #[Route('', name: 'admin_roles_hierarchy', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_roles_hierarchy');
        if ($session instanceof Response) return $session;

        $companyId = $session->company->id;
        $multiBranchEnabled = $this->isMultiBranchEnabled($companyId);

        // The hierarchy page always shows company-wide template roles only (branch_id IS NULL).
        // Branch copies are operational duplicates and carry no hierarchy data; showing them
        // would re-introduce the duplication problem (one copy per branch).
        $roles = $this->db->fetchAllAssociative(
            "SELECT
               r.id, r.name, r.description, r.scope, r.is_system_role, r.is_head_role, r.deleted_at,
               COALESCE(rh.level, 0) AS level,
               rh.parent_role_id,
               parent_role.name AS parent_role_name,
               COUNT(DISTINCT rp.id) AS permission_count
             FROM roles r
             LEFT JOIN role_hierarchy rh ON rh.role_id = r.id
             LEFT JOIN roles parent_role ON parent_role.id = rh.parent_role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             WHERE r.company_id = :company_id
               AND r.deleted_at IS NULL
               AND r.branch_id IS NULL
             GROUP BY r.id
             ORDER BY COALESCE(rh.level, 0) DESC, r.is_head_role DESC, r.scope ASC, r.name ASC",
            ['company_id' => $companyId],
        );

        if (!$multiBranchEnabled) {
            $roles = array_values(array_filter(
                $roles,
                fn (array $role) => !$this->isHiddenWhenMultiBranchDisabled((string) ($role['name'] ?? ''))
            ));
        }

        // Build hierarchy tree
        $byId = [];
        $roots = [];
        $editableRoleIds = $this->getEditableRoleIds($session, $companyId, $roles);
        $assignableParentRoleIds = $this->getAssignableParentRoleIds($session, $companyId, $roles);
        $maxAssignableLevel = $this->getMaxAssignableLevel($session, $companyId);

        // Index all roles by ID
        foreach ($roles as $role) {
            $byId[(int)$role['id']] = array_merge($role, ['children' => []]);
        }

        // Build parent-child relationships
        foreach ($roles as $role) {
            if ($role['parent_role_id'] === null || !isset($byId[(int)$role['parent_role_id']])) {
                // Root role
                $roots[] = &$byId[(int)$role['id']];
            } else {
                // Child role
                $byId[(int)$role['parent_role_id']]['children'][] = &$byId[(int)$role['id']];
            }
        }

        $this->sortHierarchyNodes($roots);

        return $this->render('admin/roles-hierarchy/index.html.twig', [
            'session'     => $session,
            'tree'        => $roots,
            'allRoles'    => $roles,
            'editableRoleIds' => $editableRoleIds,
            'assignableParentRoleIds' => $assignableParentRoleIds,
            'maxAssignableLevel' => $maxAssignableLevel,
            'can'         => [
                'edit'   => $this->can->check($session, 'edit_roles_hierarchy'),
                'assign' => $this->can->check($session, 'assign_permissions'),
            ],
        ]);
    }

    /**
     * Update role hierarchy relationship
     */
    #[Route('/{id}/set-parent', name: 'admin_roles_hierarchy_set_parent', methods: ['POST'])]
    public function setParent(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_roles_hierarchy');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $parentId = $request->request->get('parent_role_id') ? (int)$request->request->get('parent_role_id') : null;
        $level = $request->request->get('level') !== '' ? (int)$request->request->get('level') : 0;

        $companyId = $session->company->id;
        $maxAssignableLevel = $this->getMaxAssignableLevel($session, $companyId);

        // Verify role exists
        $role = $this->db->fetchAssociative(
            'SELECT r.id, COALESCE(rh.level, 0) AS level
             FROM roles r
             LEFT JOIN role_hierarchy rh ON rh.role_id = r.id AND rh.company_id = :company_id
             WHERE r.id = :id AND r.company_id = :company_id AND r.deleted_at IS NULL',
            ['id' => $id, 'company_id' => $companyId],
        );

        if (!$role) {
            return $this->error('Role not found.', 404);
        }

        if (!$this->canEditRoleHierarchy($session, (int) $id, $companyId)) {
            return $this->error('You cannot edit a role at your hierarchy level or above.', 403);
        }

        if ($level < 0 || $level > 4) {
            return $this->error('Invalid hierarchy level.', 422);
        }

        if ($maxAssignableLevel !== null && $level >= $maxAssignableLevel) {
            return $this->error('You cannot assign a hierarchy level at or above your own.', 403);
        }

        // Verify parent role exists and belongs to same company
        if ($parentId !== null) {
            $parentRole = $this->db->fetchAssociative(
                'SELECT r.id, COALESCE(rh.level, 0) AS level
                 FROM roles r
                 LEFT JOIN role_hierarchy rh ON rh.role_id = r.id AND rh.company_id = :company_id
                 WHERE r.id = :id AND r.company_id = :company_id AND r.deleted_at IS NULL',
                ['id' => $parentId, 'company_id' => $companyId],
            );

            if (!$parentRole) {
                return $this->error('Parent role not found.', 404);
            }

            // Prevent circular hierarchy
            if ($id === $parentId) {
                return $this->error('A role cannot be its own parent.', 422);
            }

            if (!$this->canAssignParentRole($session, (int) $parentId, $companyId)) {
                return $this->error('You cannot assign a parent role at or above your hierarchy.', 403);
            }
        }

        // Check if hierarchy entry exists
        $exists = $this->db->fetchOne(
            'SELECT id FROM role_hierarchy WHERE role_id = :role_id',
            ['role_id' => $id],
        );

        if ($exists) {
            // Update existing
            $this->db->update('role_hierarchy', [
                'parent_role_id' => $parentId,
                'level'          => $level,
            ], ['role_id' => $id]);
        } else {
            // Insert new
            $this->db->insert('role_hierarchy', [
                'role_id'        => $id,
                'parent_role_id' => $parentId,
                'level'          => $level,
                'company_id'     => $companyId,
            ]);
        }

        return $this->success('Hierarchy updated successfully.');
    }

    private function getEditableRoleIds(object $session, int $companyId, array $roles): array
    {
        if ($session->user->isSuperAdmin) {
            return array_map(static fn(array $role) => (int) $role['id'], $roles);
        }

        if ($session->branch === null) {
            return [];
        }

        $editable = [];
        $actorRoleIds = $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id);

        foreach ($roles as $role) {
            $targetRoleId = (int) $role['id'];
            foreach ($actorRoleIds as $actorRoleId) {
                if ($this->hierarchy->canManageRole((int) $actorRoleId, $targetRoleId, $companyId)) {
                    $editable[$targetRoleId] = true;
                    break;
                }
            }
        }

        return array_map('intval', array_keys($editable));
    }

    private function getAssignableParentRoleIds(object $session, int $companyId, array $roles): array
    {
        if ($session->user->isSuperAdmin) {
            return array_map(static fn(array $role) => (int) $role['id'], $roles);
        }

        if ($session->branch === null) {
            return [];
        }

        $allowed = [];
        $actorRoleIds = array_map('intval', $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id));

        foreach ($actorRoleIds as $actorRoleId) {
            $allowed[$actorRoleId] = true;
            foreach ($this->hierarchy->getAssignableRoles($actorRoleId, $companyId) as $roleId) {
                $allowed[(int) $roleId] = true;
            }
        }

        return array_map('intval', array_keys($allowed));
    }

    private function getMaxAssignableLevel(object $session, int $companyId): ?int
    {
        if ($session->user->isSuperAdmin) {
            return null;
        }

        if ($session->branch === null) {
            return null;
        }

        $levels = [];
        foreach ($this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id) as $actorRoleId) {
            $level = $this->hierarchy->getRoleLevel((int) $actorRoleId, $companyId);
            if ($level !== null) {
                $levels[] = $level;
            }
        }

        return empty($levels) ? null : max($levels);
    }

    private function canEditRoleHierarchy(object $session, int $targetRoleId, int $companyId): bool
    {
        if ($session->user->isSuperAdmin) {
            return true;
        }

        if ($session->branch === null) {
            return false;
        }

        foreach ($this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id) as $actorRoleId) {
            if ($this->hierarchy->canManageRole((int) $actorRoleId, $targetRoleId, $companyId)) {
                return true;
            }
        }

        return false;
    }

    private function canAssignParentRole(object $session, int $parentRoleId, int $companyId): bool
    {
        if ($session->user->isSuperAdmin) {
            return true;
        }

        if ($session->branch === null) {
            return false;
        }

        foreach ($this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id) as $actorRoleId) {
            if ((int) $actorRoleId === $parentRoleId) {
                return true;
            }

            if (in_array($parentRoleId, $this->hierarchy->getAssignableRoles((int) $actorRoleId, $companyId), true)) {
                return true;
            }
        }

        return false;
    }

    private function sortHierarchyNodes(array &$nodes): void
    {
        usort($nodes, static function (array $a, array $b): int {
            $aHasChildren = !empty($a['children']);
            $bHasChildren = !empty($b['children']);

            if ($aHasChildren !== $bHasChildren) {
                return $aHasChildren <=> $bHasChildren;
            }

            if ((int) $a['level'] !== (int) $b['level']) {
                return (int) $b['level'] <=> (int) $a['level'];
            }

            if ((int) $a['is_head_role'] !== (int) $b['is_head_role']) {
                return (int) $b['is_head_role'] <=> (int) $a['is_head_role'];
            }

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        foreach ($nodes as &$node) {
            if (!empty($node['children'])) {
                $this->sortHierarchyNodes($node['children']);
            }
        }
        unset($node);
    }

    private function isHiddenWhenMultiBranchDisabled(string $roleName): bool
    {
        return in_array(mb_strtolower(trim($roleName)), ['overall manager', 'regional manager'], true);
    }
}
