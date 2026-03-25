<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Branch\BranchHierarchyService;
use App\Services\Branch\BranchPermissionService;
use App\Services\Branch\BranchResolverService;
use App\Services\Branch\DTO\BranchNode;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use App\Services\Role\RoleHierarchyService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/{branch}/dashboard/admin/org',
    host: '{subdomain}.{domain}',
    requirements: [
        'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
        'domain'    => '.+',
        'branch'    => '[A-Za-z0-9-]+',
    ]
)]
final class OrgController extends AdminBaseController
{
    private const ORG_ROLE_NAMES = [
        'overall manager',
        'regional manager',
        'branch manager',
        'director',
        'owner',
    ];

    private const ORG_CHART_MANAGER_NAMES = [
        'owner',
        'director',
        'overall manager',
    ];

    private const SINGLE_SLOT_ROLE_NAMES = [
        'owner',
        'overall manager',
        'regional manager',
        'branch manager',
    ];

    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService          $branchResolver,
        Connection                     $db,
        private readonly BranchHierarchyService   $hierarchy,
        private readonly BranchPermissionService  $branchPermissions,
        private readonly RoleHierarchyService     $roleHierarchy,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // INDEX — full org tree with assignments
    // =========================================================================

    #[Route('', name: 'admin_org', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request);
        if ($session instanceof Response) return $session;
        if (!$this->canAccessOrgChart($session)) {
            return $this->denyAccess($request, 'You do not have permission to manage the organisation chart.', 403, $session);
        }

        $companyId = $session->company->id;

        // Determine the scope for this org view:
        //   overall context / superadmin → full company tree
        //   region context               → subtree rooted at this region
        //   hq branch                    → full tree (HQ operational view)
        $isOverall = ($session->context ?? 'operational') === 'overall';
        $isRegion  = $session->branch !== null && $session->branch->type === 'region' && !$isOverall;

        if ($isRegion) {
            // Regional scope: show only nodes in this region's subtree.
            // Applies to both regional managers and platform admins viewing a region context.
            $scopeIds = $this->hierarchy->getSubtreeIds($session->branch->id);
            $ph = implode(',', array_fill(0, count($scopeIds), '?'));

            $rows = $this->db->fetchAllAssociative(
                "SELECT * FROM branches WHERE id IN ({$ph}) AND deleted_at IS NULL ORDER BY depth ASC, is_hq DESC, name ASC",
                $scopeIds,
            );
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
            $scopeIds = null; // null = whole company
            $tree = $this->hierarchy->buildTree($companyId);
        }

        $flat = [];
        $this->flattenTree($tree, $flat);
        $nodeMeta = [];
        foreach ($flat as $node) {
            $nodeMeta[(int) $node['id']] = $node;
        }

        // All user_node_role assignments, scoped to the visible tree
        if ($scopeIds !== null) {
            $ph2 = implode(',', array_fill(0, count($scopeIds), '?'));
            $assignmentRows = $this->db->fetchAllAssociative(
                "SELECT unr.node_id, unr.user_id, unr.role_id, unr.is_primary,
                        u.name  AS user_name,
                        u.email AS user_email,
                        u.department_id,
                        d.name  AS department_name,
                        r.name  AS role_name,
                        r.is_system_role
                   FROM user_node_roles unr
                   JOIN users    u ON u.id = unr.user_id  AND u.deleted_at  IS NULL
                   LEFT JOIN departments d ON d.id = u.department_id
                   JOIN roles    r ON r.id = unr.role_id  AND r.deleted_at  IS NULL
                  WHERE unr.node_id IN ({$ph2})
                  ORDER BY unr.node_id ASC, r.is_system_role DESC, r.name ASC, u.name ASC",
                $scopeIds,
            );
        } else {
            $assignmentRows = $this->db->fetchAllAssociative(
                "SELECT unr.node_id, unr.user_id, unr.role_id, unr.is_primary,
                        u.name  AS user_name,
                        u.email AS user_email,
                        u.department_id,
                        d.name  AS department_name,
                        r.name  AS role_name,
                        r.is_system_role
                   FROM user_node_roles unr
                   JOIN users    u ON u.id = unr.user_id  AND u.deleted_at  IS NULL
                   LEFT JOIN departments d ON d.id = u.department_id
                   JOIN roles    r ON r.id = unr.role_id  AND r.deleted_at  IS NULL
                   JOIN branches b ON b.id = unr.node_id  AND b.company_id = :cid
                  ORDER BY unr.node_id ASC, r.is_system_role DESC, r.name ASC, u.name ASC",
                ['cid' => $companyId],
            );
        }

        $assignmentRows = array_values(array_filter(
            $assignmentRows,
            fn (array $row): bool => $this->isOrgRoleForNode(
                (string) ($row['role_name'] ?? ''),
                $nodeMeta[(int) $row['node_id']] ?? null,
            )
        ));

        // Group by node_id for O(1) lookup in template
        $byNode = [];
        $occupiedRoles = [];
        foreach ($assignmentRows as $row) {
            $byNode[(int) $row['node_id']][] = $row;
            $occupiedRoles[(int) $row['node_id']][(int) $row['role_id']] = [
                'user_id'   => (int) $row['user_id'],
                'user_name' => (string) $row['user_name'],
                'role_name' => (string) $row['role_name'],
            ];
        }

        // Summary counts by role name
        $roleSummary = [];
        foreach ($assignmentRows as $row) {
            $roleSummary[$row['role_name']] = ($roleSummary[$row['role_name']] ?? 0) + 1;
        }
        arsort($roleSummary);

        // Users for assign dropdown (active only), with department info
        $users = $this->db->fetchAllAssociative(
            "SELECT u.id, u.name, u.email, u.department_id, d.name AS department_name
               FROM users u
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE u.company_id = :cid AND u.deleted_at IS NULL
              ORDER BY u.name ASC",
            ['cid' => $companyId],
        );

        // Only leadership roles are assignable on this page.
        // Operational staff are assigned in the Users section per-branch.
        $roles = $this->db->fetchAllAssociative(
            "SELECT id, name, scope, is_system_role, is_head_role
               FROM roles
              WHERE company_id = :cid
                AND LOWER(name) IN (:names)
                AND deleted_at IS NULL
              ORDER BY scope ASC, name ASC",
            ['cid' => $companyId, 'names' => self::ORG_ROLE_NAMES],
            ['names' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $manageableRoleIds = $this->getManageableOrgRoleIds($session, $roles);

        $canManage = !empty($manageableRoleIds);

        return $this->render('admin/org/index.html.twig', [
            'session'     => $session,
            'tree'        => $tree,
            'flat'        => $flat,
            'byNode'      => $byNode,
            'roleSummary' => $roleSummary,
            'users'       => $users,
            'roles'       => $roles,
            'manageableRoleIds' => $manageableRoleIds,
            'occupiedRoles' => $occupiedRoles,
            'canManage'   => $canManage,
            'totalAssigned' => count($assignmentRows),
        ]);
    }

    // =========================================================================
    // ASSIGN — add a user+role to a node
    // =========================================================================

    #[Route('/assign', name: 'admin_org_assign', methods: ['POST'])]
    public function assign(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request);
        if ($session instanceof Response) return $this->error('Unauthorised.', 403);
        if (!$this->canAccessOrgChart($session)) {
            return $this->error('You do not have permission to manage the organisation chart.', 403);
        }

        $userId = (int) $request->request->get('user_id');
        $nodeId = (int) $request->request->get('node_id');
        $roleId = (int) $request->request->get('role_id');

        if (!$userId || !$nodeId || !$roleId) {
            return $this->error('User, branch and role are all required.');
        }

        // Verify node belongs to this company
        $node = $this->hierarchy->findById($nodeId);
        if ($node === null || $node->companyId !== $session->company->id) {
            return $this->error('Branch not found.', 404);
        }

        // Verify user belongs to this company
        $userExists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM users WHERE id = :id AND company_id = :cid AND deleted_at IS NULL',
            ['id' => $userId, 'cid' => $session->company->id],
        );
        if (!$userExists) {
            return $this->error('User not found.', 404);
        }

        // Verify role belongs to this company and load its scope
        $role = $this->db->fetchAssociative(
            'SELECT id, name, scope, is_system_role FROM roles WHERE id = :id AND company_id = :cid AND deleted_at IS NULL',
            ['id' => $roleId, 'cid' => $session->company->id],
        );
        if (!$role) {
            return $this->error('Role not found.', 404);
        }

        if (!$this->isOrgRoleForNode((string) $role['name'], [
            'id'   => $node->id,
            'type' => $node->type,
            'isHq' => $node->isHq,
        ])) {
            return $this->error('Only branch-head roles can be managed on this page.', 422);
        }

        if (!$this->canManageOrgRole($session, (int) $role['id'])) {
            return $this->error(sprintf('You cannot assign the %s role from your current hierarchy level.', (string) $role['name']), 403);
        }

        // ── Scope validation: role must match node type ──────────────────────
        // hq-scoped role must be assigned at an HQ node
        // region-scoped role must be assigned at a region node
        // branch-scoped role must be assigned at a branch node
        if ($role['scope'] !== 'any') {
            $nodeType = $node->isHq ? 'hq' : $node->type;
            if ($role['scope'] !== $nodeType) {
                $label = match ($role['scope']) {
                    'hq'     => 'HQ',
                    'region' => 'region',
                    'branch' => 'branch',
                    default  => $role['scope'],
                };
                return $this->error("The \"{$role['name']}\" role can only be assigned at a {$label}-level node.", 422);
            }
        }

        // ── One Overall Manager per company ──────────────────────────────────
        if ($role['scope'] === 'hq' && $role['is_system_role'] && strtolower((string) $role['name']) === 'overall manager') {
            $existing = $this->db->fetchOne(
                "SELECT u.name FROM user_node_roles unr
                   JOIN branches b ON b.id = unr.node_id AND b.company_id = :cid
                   JOIN users u    ON u.id = unr.user_id
                  WHERE unr.role_id = :rid AND unr.user_id != :uid AND unr.node_id != :nid
                  LIMIT 1",
                ['cid' => $session->company->id, 'rid' => $roleId, 'uid' => $userId, 'nid' => $nodeId],
            );
            if ($existing) {
                return $this->error("{$existing} is already the Overall Manager. Remove that assignment first.", 409);
            }
        }

        // Duplicate check
        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM user_node_roles WHERE user_id = :u AND node_id = :n AND role_id = :r',
            ['u' => $userId, 'n' => $nodeId, 'r' => $roleId],
        );
        if ($exists) {
            return $this->error('This assignment already exists.', 409);
        }

        $isSingleSlotRole = $this->isSingleSlotRole((string) $role['name']);
        $existingAssignment = $isSingleSlotRole ? $this->db->fetchAssociative(
            'SELECT unr.id, unr.user_id, unr.is_primary, u.name AS user_name, unr.role_id
               FROM user_node_roles unr
               JOIN users u ON u.id = unr.user_id AND u.deleted_at IS NULL
              WHERE unr.node_id = :n AND unr.role_id = :r
              LIMIT 1',
            ['n' => $nodeId, 'r' => $roleId],
        ) : false;

        if ($existingAssignment && !$this->canManageOrgRole($session, (int) $existingAssignment['role_id'])) {
            return $this->error(sprintf(
                'You cannot replace the current %s from your current hierarchy level.',
                (string) $role['name'],
            ), 403);
        }

        // First assignment for this user ever → mark as primary
        $hasPrimary = (bool) $this->db->fetchOne(
            'SELECT 1 FROM user_node_roles WHERE user_id = :u',
            ['u' => $userId],
        );

        $this->db->beginTransaction();
        try {
            if ($existingAssignment) {
                $existingUserId = (int) $existingAssignment['user_id'];

                $this->db->executeStatement(
                    'DELETE FROM user_node_roles WHERE id = :id',
                    ['id' => (int) $existingAssignment['id']],
                );

                if ((bool) $existingAssignment['is_primary']) {
                    $stillHasPrimary = (bool) $this->db->fetchOne(
                        'SELECT 1 FROM user_node_roles WHERE user_id = :u AND is_primary = 1',
                        ['u' => $existingUserId],
                    );
                    if (!$stillHasPrimary) {
                        $this->db->executeStatement(
                            'UPDATE user_node_roles SET is_primary = 1 WHERE user_id = :u ORDER BY id ASC LIMIT 1',
                            ['u' => $existingUserId],
                        );
                    }
                }
            }

            $this->db->executeStatement(
                'INSERT INTO user_node_roles (user_id, node_id, role_id, is_primary) VALUES (:u, :n, :r, :p)',
                ['u' => $userId, 'n' => $nodeId, 'r' => $roleId, 'p' => $hasPrimary ? 0 : 1],
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($existingAssignment) {
            return $this->success(sprintf(
                'Replaced %s with the new %s.',
                (string) $existingAssignment['user_name'],
                (string) $role['name'],
            ));
        }

        return $this->success('Assigned successfully.');
    }

    // =========================================================================
    // REVOKE — remove a user+role from a node
    // =========================================================================

    #[Route('/{nodeId}/{userId}/{roleId}/revoke', name: 'admin_org_revoke', methods: ['POST'])]
    public function revoke(int $nodeId, int $userId, int $roleId, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request);
        if ($session instanceof Response) return $this->error('Unauthorised.', 403);
        if (!$this->canAccessOrgChart($session)) {
            return $this->error('You do not have permission to manage the organisation chart.', 403);
        }

        $node = $this->hierarchy->findById($nodeId);
        if ($node === null || $node->companyId !== $session->company->id) {
            return $this->error('Branch not found.', 404);
        }

        if (!$this->canManageOrgRole($session, $roleId)) {
            return $this->error('You cannot remove that role from your current hierarchy level.', 403);
        }

        $roleName = $this->db->fetchOne(
            'SELECT LOWER(name) FROM roles WHERE id = :id AND company_id = :cid AND deleted_at IS NULL',
            ['id' => $roleId, 'cid' => $session->company->id],
        );
        if ($roleName === 'owner') {
            return $this->error('Owner cannot be removed directly. Replace the owner assignment instead.', 422);
        }

        $this->db->executeStatement(
            'DELETE FROM user_node_roles WHERE user_id = :u AND node_id = :n AND role_id = :r',
            ['u' => $userId, 'n' => $nodeId, 'r' => $roleId],
        );

        // If we removed the primary assignment, promote the next one
        $stillHasPrimary = (bool) $this->db->fetchOne(
            'SELECT 1 FROM user_node_roles WHERE user_id = :u AND is_primary = 1',
            ['u' => $userId],
        );
        if (!$stillHasPrimary) {
            $this->db->executeStatement(
                'UPDATE user_node_roles SET is_primary = 1 WHERE user_id = :u ORDER BY id ASC LIMIT 1',
                ['u' => $userId],
            );
        }

        return $this->success('Removed.');
    }

    // =========================================================================
    // SET PRIMARY — change a user's primary/landing branch
    // =========================================================================

    #[Route('/{userId}/primary/{nodeId}', name: 'admin_org_set_primary', methods: ['POST'])]
    public function setPrimary(int $userId, int $nodeId, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request);
        if ($session instanceof Response) return $this->error('Unauthorised.', 403);
        if (!$this->canAccessOrgChart($session)) {
            return $this->error('You do not have permission to manage the organisation chart.', 403);
        }

        // Confirm the assignment exists and the node belongs to this company
        $node = $this->hierarchy->findById($nodeId);
        if ($node === null || $node->companyId !== $session->company->id) {
            return $this->error('Branch not found.', 404);
        }

        $assignmentExists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM user_node_roles WHERE user_id = :u AND node_id = :n',
            ['u' => $userId, 'n' => $nodeId],
        );
        if (!$assignmentExists) {
            return $this->error('That user is not assigned to this branch.', 404);
        }

        $roleIds = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT role_id FROM user_node_roles WHERE user_id = :u AND node_id = :n',
            ['u' => $userId, 'n' => $nodeId],
        ));
        $orgRoleIds = array_values(array_filter(
            $roleIds,
            fn (int $roleId): bool => $this->isOrgRoleId($roleId, $session->company->id)
        ));
        if (!empty($orgRoleIds) && !$this->canManageAnyOrgRole($session, $orgRoleIds)) {
            return $this->error('You cannot update the primary branch for that leadership assignment.', 403);
        }

        // Clear existing primary then set new one
        $this->db->executeStatement(
            'UPDATE user_node_roles SET is_primary = 0 WHERE user_id = :u',
            ['u' => $userId],
        );
        $this->db->executeStatement(
            'UPDATE user_node_roles SET is_primary = 1 WHERE user_id = :u AND node_id = :n',
            ['u' => $userId, 'n' => $nodeId],
        );

        return $this->success('Primary branch updated.');
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /** Recursively flatten the nested tree into a depth-ordered array for JS. */
    private function flattenTree(array $nodes, array &$result): void
    {
        foreach ($nodes as $node) {
            /** @var BranchNode $node */
            $result[] = [
                'id'    => $node->id,
                'name'  => $node->name,
                'slug'  => $node->slug,
                'type'  => $node->type,
                'depth' => $node->depth,
                'isHq'  => $node->isHq,
            ];
            if (!empty($node->children)) {
                $this->flattenTree($node->children, $result);
            }
        }
    }

    private function isOrgRoleForNode(string $roleName, ?array $node): bool
    {
        $roleName = strtolower(trim($roleName));
        if (!in_array($roleName, self::ORG_ROLE_NAMES, true) || $node === null) {
            return false;
        }

        $isHq = (bool) ($node['isHq'] ?? false);
        if ($isHq) {
            return in_array($roleName, ['owner', 'director', 'overall manager'], true);
        }

        return in_array($roleName, ['regional manager', 'branch manager'], true);
    }

    private function canAccessOrgChart(\App\Services\Auth\DTO\AuthResult $session): bool
    {
        if ($session->user->isSuperAdmin) {
            return $this->platformCan->check($session, 'access_company_context')
                && $this->platformCan->check($session, 'view_company_org_chart');
        }

        if ($session->branch === null) {
            return false;
        }

        $effectiveRoleIds = $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id);
        if (empty($effectiveRoleIds)) {
            return false;
        }

        $roleNames = $this->db->fetchFirstColumn(
            'SELECT LOWER(name) FROM roles WHERE company_id = :cid AND id IN (:ids) AND deleted_at IS NULL',
            ['cid' => $session->company->id, 'ids' => $effectiveRoleIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        foreach ($roleNames as $roleName) {
            if (in_array((string) $roleName, self::ORG_CHART_MANAGER_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    private function getManageableOrgRoleIds(\App\Services\Auth\DTO\AuthResult $session, array $orgRoles): array
    {
        if ($session->user->isSuperAdmin) {
            if (
                !$this->platformCan->check($session, 'access_company_context')
                || !$this->platformCan->check($session, 'manage_company_org_chart')
            ) {
                return [];
            }
            return array_map('intval', array_column($orgRoles, 'id'));
        }

        if ($session->branch === null) {
            return [];
        }

        $actorRoleIds = $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id);
        $manageable = [];

        foreach ($orgRoles as $orgRole) {
            $targetRoleId = (int) $orgRole['id'];
            foreach ($actorRoleIds as $actorRoleId) {
                if ($this->roleHierarchy->canManageRole((int) $actorRoleId, $targetRoleId, $session->company->id)) {
                    $manageable[$targetRoleId] = $targetRoleId;
                    break;
                }
            }
        }

        return array_values($manageable);
    }

    private function canManageOrgRole(\App\Services\Auth\DTO\AuthResult $session, int $targetRoleId): bool
    {
        return $this->sessionCanManageRoleIds($session, [$targetRoleId]);
    }

    private function canManageAnyOrgRole(\App\Services\Auth\DTO\AuthResult $session, array $targetRoleIds): bool
    {
        return $this->sessionCanManageRoleIds($session, $targetRoleIds);
    }

    private function sessionCanManageRoleIds(\App\Services\Auth\DTO\AuthResult $session, array $targetRoleIds): bool
    {
        if ($session->user->isSuperAdmin) {
            return $this->platformCan->check($session, 'access_company_context')
                && $this->platformCan->check($session, 'manage_company_org_chart');
        }

        if ($session->branch === null) {
            return false;
        }

        $actorRoleIds = $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id);
        foreach ($targetRoleIds as $targetRoleId) {
            foreach ($actorRoleIds as $actorRoleId) {
                if ($this->roleHierarchy->canManageRole((int) $actorRoleId, (int) $targetRoleId, $session->company->id)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isSingleSlotRole(string $roleName): bool
    {
        return in_array(strtolower(trim($roleName)), self::SINGLE_SLOT_ROLE_NAMES, true);
    }

    private function isOrgRoleId(int $roleId, int $companyId): bool
    {
        $roleName = $this->db->fetchOne(
            'SELECT LOWER(name) FROM roles WHERE id = :id AND company_id = :cid AND deleted_at IS NULL',
            ['id' => $roleId, 'cid' => $companyId],
        );

        return is_string($roleName) && in_array($roleName, self::ORG_ROLE_NAMES, true);
    }
}
