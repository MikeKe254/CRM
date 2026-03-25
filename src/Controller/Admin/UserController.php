<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchHierarchyService;
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

#[Route('/{branch}/dashboard/admin/users', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class UserController extends AdminBaseController
{
    public function __construct(
        AuthService            $auth,
        CheckPermissionService $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService  $branchResolver,
        Connection             $db,
        private readonly UserActivityLogService $activityLog,
        private readonly BranchHierarchyService $hierarchy,
        private readonly BranchPermissionService $branchPermissions,
        private readonly RoleHierarchyService $roleHierarchy,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // GET /dashboard/admin/users — List (Twig)
    // =========================================================================

    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_users');
        if ($session instanceof Response) return $session;

        $showDeleted = $session->user->isSuperAdmin
            && ($this->platformCan->isPlatformOwner($session) || $this->platformCan->check($session, 'view_deleted_entries'));

        $deletedFilter = $showDeleted ? '' : 'AND u.deleted_at IS NULL';
        $multiBranchEnabled = $this->isMultiBranchEnabled($session->company->id);
        $canViewLeadershipUsers = $this->canViewLeadershipUsers($session);

        // scope=branch  → exact active branch only
        // scope=subtree → active branch + all descendants (default)
        $scopeMode = $request->query->get('scope', 'subtree') === 'branch' ? 'branch' : 'subtree';

        // Scope: show users assigned within the active branch context's subtree.
        // When no branch context exists (shouldn't happen in normal flow), fall back to
        // showing the full company roster — this covers any edge cases gracefully.
        // Platform admins get the same context-scoped view as managers: switching to
        // "overall" (HQ root) shows the full company; switching to a branch shows that branch.
        if ($session->branch !== null) {
            if ($scopeMode === 'branch') {
                $nodeIds = [$session->branch->id];
            } else {
                $nodeIds = $this->hierarchy->getSubtreeIds($session->branch->id);
            }
            if (!$multiBranchEnabled && $canViewLeadershipUsers) {
                $hqNodeId = (int) ($this->db->fetchOne(
                    'SELECT id FROM branches WHERE company_id = :company_id AND is_hq = 1 AND deleted_at IS NULL LIMIT 1',
                    ['company_id' => $session->company->id],
                ) ?: 0);
                if ($hqNodeId > 0 && !in_array($hqNodeId, $nodeIds, true)) {
                    $nodeIds[] = $hqNodeId;
                }
            }
            $placeholders  = implode(',', array_fill(0, count($nodeIds), '?'));
            $currentNodeId = $session->branch->id;

            // current_node_role_id: the operational role the user holds at the
            // currently-active node specifically — used to pre-select the role
            // in the edit drawer so the manager edits the right node's role.
            $users = $this->db->fetchAllAssociative(
                "SELECT u.id, u.name, u.email, u.mobile, u.can_dashboard_login, u.can_pos_login,
                        u.is_super_admin, u.created_at, u.deleted_at, u.user_type, u.department_id,
                        d.name AS department_name,
                        GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles,
                        GROUP_CONCAT(DISTINCT unr.node_id ORDER BY unr.node_id SEPARATOR ',') AS node_ids,
                        GROUP_CONCAT(DISTINCT unr.role_id ORDER BY unr.role_id SEPARATOR ',') AS role_ids,
                        (SELECT GROUP_CONCAT(a.id ORDER BY a.name SEPARATOR ',')
                           FROM user_areas ua JOIN areas a ON a.id = ua.area_id
                          WHERE ua.user_id = u.id) AS area_ids,
                        (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ')
                           FROM user_areas ua JOIN areas a ON a.id = ua.area_id
                          WHERE ua.user_id = u.id) AS area_names,
                        (SELECT unr2.role_id FROM user_node_roles unr2
                           JOIN roles r2 ON r2.id = unr2.role_id
                          WHERE unr2.user_id = u.id AND unr2.node_id = :current_node_id
                            AND (r2.is_head_role = 0 OR (
                                :single_branch_mode = 1
                                AND r2.is_head_role = 1
                                AND r2.branch_id IS NULL
                                AND r2.scope = 'branch'
                            ))
                          LIMIT 1) AS current_node_role_id
                 FROM   users u
                 JOIN   user_node_roles unr ON unr.user_id = u.id
                                           AND unr.node_id IN ({$placeholders})
                 LEFT JOIN roles r ON r.id = unr.role_id
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE  u.company_id = :company_id
                   AND u.user_type IN ('branch', 'office', 'both')
                   {$deletedFilter}
                 GROUP  BY u.id
                 ORDER  BY u.name",
                array_merge(
                    [
                        'current_node_id' => $currentNodeId,
                        'single_branch_mode' => $multiBranchEnabled ? 0 : 1,
                        'company_id' => $session->company->id,
                    ],
                    $nodeIds,
                ),
            );
        } else {
            // No branch context — show branch-level users roster.
            $scopeMode = 'all';
            $users = $this->db->fetchAllAssociative(
                "SELECT u.id, u.name, u.email, u.mobile, u.can_dashboard_login, u.can_pos_login,
                        u.is_super_admin, u.created_at, u.deleted_at, u.user_type, u.department_id,
                        d.name AS department_name,
                        GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles,
                        (SELECT GROUP_CONCAT(a.id ORDER BY a.name SEPARATOR ',')
                           FROM user_areas ua JOIN areas a ON a.id = ua.area_id
                          WHERE ua.user_id = u.id) AS area_ids,
                        (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ')
                           FROM user_areas ua JOIN areas a ON a.id = ua.area_id
                          WHERE ua.user_id = u.id) AS area_names
                 FROM   users u
                 LEFT JOIN user_node_roles unr ON unr.user_id = u.id
                 LEFT JOIN roles r ON r.id = unr.role_id
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE  u.company_id = :company_id
                   AND u.user_type IN ('branch', 'office', 'both')
                   {$deletedFilter}
                 GROUP  BY u.id
                 ORDER  BY u.name",
                ['company_id' => $session->company->id],
            );
        }

        if (!$canViewLeadershipUsers) {
            $users = array_values(array_filter(
                $users,
                fn (array $listedUser) => !$this->userHasOwnerOrDirectorAssignment((int) $listedUser['id'], $session->company->id)
            ));
        } elseif (!$multiBranchEnabled) {
            $users = array_values(array_filter(
                $users,
                function (array $listedUser) use ($session): bool {
                    $userId = (int) $listedUser['id'];

                    if ($this->userHasOwnerOrDirectorAssignment($userId, $session->company->id)) {
                        return true;
                    }

                    return !$this->userHasSingleBranchExcludedLeadershipAssignment($userId, $session->company->id);
                }
            ));
        }

        // Role dropdown — branch-scoped, head roles excluded.
        // Branch/region context: only that node's own copied roles.
        // HQ context: company-wide non-head templates.
        $isHq = $session->branch !== null && $session->branch->isHq;

        if ($isHq || $session->branch === null) {
            $roles = $this->db->fetchAllAssociative(
                'SELECT id, name, scope FROM roles
                  WHERE company_id = :cid AND branch_id IS NULL
                    AND is_head_role = 0 AND deleted_at IS NULL
                  ORDER BY name',
                ['cid' => $session->company->id],
            );
        } else {
            $roles = $this->db->fetchAllAssociative(
                'SELECT id, name, scope FROM roles
                  WHERE company_id = :cid
                    AND deleted_at IS NULL
                    AND (
                        (branch_id = :bid AND is_head_role = 0)
                        OR (
                            :single_branch_mode = 1
                            AND branch_id IS NULL
                            AND is_head_role = 1
                            AND (
                                name = :branch_manager_name
                                OR (
                                    :can_view_leadership_users = 1
                                    AND name IN (:single_branch_leadership_role_names)
                                )
                            )
                        )
                    )
                  ORDER BY name',
                [
                    'cid' => $session->company->id,
                    'bid' => $session->branch->id,
                    'single_branch_mode' => $multiBranchEnabled ? 0 : 1,
                    'branch_manager_name' => 'Branch Manager',
                    'can_view_leadership_users' => $canViewLeadershipUsers ? 1 : 0,
                    'single_branch_leadership_role_names' => ['Owner', 'Director'],
                ],
                [
                    'single_branch_leadership_role_names' => \Doctrine\DBAL\ArrayParameterType::STRING,
                ],
            );
        }

        $roles = array_values(array_filter(
            $roles,
            fn (array $role) => $this->canAssignRoleInContext($session, (int) $role['id'])
        ));

        foreach ($users as &$listedUser) {
            $listedUser['can_manage_user'] = $this->canManageUserInContext($session, (int) $listedUser['id']);
            $listedUser['is_current_tenant_user'] = $this->isCurrentTenantUser($session, (int) $listedUser['id']);
            $listedUser['can_toggle_access'] = $listedUser['can_manage_user'] && !$listedUser['is_current_tenant_user'];
        }
        unset($listedUser);

        $this->activityLog->record($session, 'user.view', request: $request);

        // Show the scope toggle only when the current branch has sub-branches.
        $hasSubBranches = $session->branch !== null
            && count($this->hierarchy->getDescendantIds($session->branch->id)) > 0;

        $departments = $this->db->fetchAllAssociative(
            "SELECT id, name FROM departments WHERE company_id = :cid AND status = 'active' AND deleted_at IS NULL ORDER BY is_system DESC, name ASC",
            ['cid' => $session->company->id],
        );

        $areas = $this->db->fetchAllAssociative(
            "SELECT id, name FROM areas WHERE company_id = :cid AND status = 'active' AND deleted_at IS NULL ORDER BY is_system DESC, name ASC",
            ['cid' => $session->company->id],
        );

        return $this->render('admin/users/index.html.twig', [
            'session'       => $session,
            'users'         => $users,
            'roles'         => $roles,
            'departments'   => $departments,
            'areas'         => $areas,
            'showDeleted'   => $showDeleted,
            'scopeMode'     => $scopeMode,
            'hasSubBranches' => $hasSubBranches,
            'can'           => [
                'create' => $this->can->check($session, 'create_users'),
                'edit'   => $this->can->check($session, 'edit_users'),
                'delete' => $this->can->check($session, 'delete_users'),
            ],
        ]);
    }

    // =========================================================================
    // POST /dashboard/admin/users/create — Create (fetch)
    // =========================================================================

    #[Route('/create', name: 'admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'create_users');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $name     = trim((string) $request->request->get('name', ''));
        $email    = trim((string) $request->request->get('email', ''));
        $mobile   = trim((string) $request->request->get('mobile', '')) ?: null;
        $password = (string) $request->request->get('password', '');
        $pin      = (string) $request->request->get('pin', '');
        $roleId   = (int) $request->request->get('role_id', 0);
        $canDash  = (bool) $request->request->get('can_dashboard_login', false);
        $canPos   = (bool) $request->request->get('can_pos_login', false);

        if ($name === '') {
            return $this->error('Name is required.');
        }
        if ($canDash && $email === '') {
            return $this->error('Email is required for dashboard login.');
        }
        if ($canDash && $password === '') {
            return $this->error('Password is required for dashboard login.');
        }
        if ($canPos && $pin === '') {
            return $this->error('PIN is required for POS login.');
        }

        if ($email !== '') {
            $exists = $this->db->fetchOne(
                'SELECT id FROM users WHERE email = :email AND company_id = :company_id AND deleted_at IS NULL',
                ['email' => $email, 'company_id' => $session->company->id],
            );
            if ($exists) {
                return $this->error('A user with this email already exists.');
            }
        }

        // Validate that the role belongs to the current branch context and is not a head role.
        if ($roleId > 0 && $session->branch !== null) {
            $validRole = $this->resolveOperationalRole($roleId, $session);
            if ($validRole === null) {
                return $this->error('Role not found or not available in this branch context.', 422);
            }
            if (!$this->canAssignRoleInContext($session, $roleId)) {
                return $this->error('You cannot assign a role at your hierarchy level or above.', 403);
            }
        }

        $deptId  = (int) $request->request->get('department_id', 0) ?: null;
        $areaIds = array_filter(array_map('intval', (array) $request->request->all('area_ids')));

        // Validate department belongs to this company
        if ($deptId !== null) {
            $validDept = $this->db->fetchOne(
                'SELECT id FROM departments WHERE id = :id AND company_id = :cid AND deleted_at IS NULL',
                ['id' => $deptId, 'cid' => $session->company->id],
            );
            if (!$validDept) $deptId = null;
        }

        $this->db->insert('users', [
            'company_id'          => $session->company->id,
            'name'                => $name,
            'email'               => $email ?: null,
            'mobile'              => $mobile,
            'password'            => $password ? password_hash($password, PASSWORD_BCRYPT) : null,
            'pin'                 => $pin ? password_hash($pin, PASSWORD_BCRYPT) : null,
            'can_dashboard_login' => $canDash ? 1 : 0,
            'can_pos_login'       => $canPos ? 1 : 0,
            'is_super_admin'      => 0,
            'department_id'       => $deptId,
            'created_at'          => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $userId = (int) $this->db->lastInsertId();

        if ($roleId > 0 && $session->branch !== null) {
            $this->db->insert('user_node_roles', [
                'user_id'    => $userId,
                'node_id'    => $session->branch->id,
                'role_id'    => $roleId,
                'is_primary' => 1, // first assignment is always primary
            ]);
        }

        // Assign areas (validate each belongs to this company)
        foreach ($areaIds as $areaId) {
            $valid = $this->db->fetchOne(
                'SELECT id FROM areas WHERE id = :id AND company_id = :cid AND deleted_at IS NULL',
                ['id' => $areaId, 'cid' => $session->company->id],
            );
            if ($valid) {
                $this->db->insert('user_areas', ['user_id' => $userId, 'area_id' => $areaId]);
            }
        }

        $this->activityLog->record($session, 'user.create',
            ['name' => $name, 'email' => $email ?: 'N/A'],
            permission: 'create_users', subjectType: 'user', subjectId: $userId, request: $request,
        );

        return $this->success('User created successfully.', ['id' => $userId]);
    }

    // =========================================================================
    // POST /dashboard/admin/users/{id}/edit — Edit (fetch)
    // =========================================================================

    #[Route('/{id}/edit', name: 'admin_users_edit', methods: ['POST'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'edit_users');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $user = $this->db->fetchAssociative(
            'SELECT * FROM users WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$user) {
            return $this->error('User not found.', 404);
        }

        if ($this->isCurrentTenantUser($session, $id)) {
            return $this->error('You cannot edit your own account from this page.', 403);
        }

        if (!$this->canManageUserInContext($session, $id)) {
            return $this->error('You cannot manage a user at your hierarchy level or above.', 403);
        }

        $data = [
            'name' => trim((string) $request->request->get('name', $user['name'])),
        ];

        // Only update access flags when explicitly sent.
        // toggleUserAccess sends only one field at a time — using has() on both
        // would zero out the untouched field.
        if ($request->request->has('can_dashboard_login')) {
            $data['can_dashboard_login'] = (int) $request->request->get('can_dashboard_login');
        }
        if ($request->request->has('can_pos_login')) {
            $data['can_pos_login'] = (int) $request->request->get('can_pos_login');
        }

        $email = trim((string) $request->request->get('email', ''));
        if ($email !== '') $data['email'] = $email;

        if ($request->request->has('mobile')) {
            $data['mobile'] = trim((string) $request->request->get('mobile', '')) ?: null;
        }

        $password = (string) $request->request->get('password', '');
        if ($password !== '') $data['password'] = password_hash($password, PASSWORD_BCRYPT);

        $pin = (string) $request->request->get('pin', '');
        if ($pin !== '') $data['pin'] = password_hash($pin, PASSWORD_BCRYPT);

        if ($request->request->has('department_id')) {
            $deptId = (int) $request->request->get('department_id', 0) ?: null;
            if ($deptId !== null) {
                $validDept = $this->db->fetchOne(
                    'SELECT id FROM departments WHERE id = :id AND company_id = :cid AND deleted_at IS NULL',
                    ['id' => $deptId, 'cid' => $session->company->id],
                );
                if (!$validDept) $deptId = null;
            }
            $data['department_id'] = $deptId;
        }

        // Sync areas (replace all when area_ids is present in the request)
        if ($request->request->has('area_ids')) {
            $areaIds = array_filter(array_map('intval', (array) $request->request->all('area_ids')));
            $this->db->executeStatement('DELETE FROM user_areas WHERE user_id = :uid', ['uid' => $id]);
            foreach ($areaIds as $areaId) {
                $valid = $this->db->fetchOne(
                    'SELECT id FROM areas WHERE id = :id AND company_id = :cid AND deleted_at IS NULL',
                    ['id' => $areaId, 'cid' => $session->company->id],
                );
                if ($valid) {
                    $this->db->insert('user_areas', ['user_id' => $id, 'area_id' => $areaId]);
                }
            }
        }

        $roleId = (int) $request->request->get('role_id', 0);

        // Validate that the role belongs to the current branch context and is not a head role.
        if ($roleId > 0 && $session->branch !== null) {
            $validRole = $this->resolveOperationalRole($roleId, $session);
            if ($validRole === null) {
                return $this->error('Role not found or not available in this branch context.', 422);
            }
            if (!$this->canAssignRoleInContext($session, $roleId)) {
                return $this->error('You cannot assign a role at your hierarchy level or above.', 403);
            }
        }

        // Peer-role protection: cannot edit a user who shares a role at the same node
        if (!$session->user->isSuperAdmin && $session->branch !== null
            && $this->shareRole($id, $session->user->id, $session->branch->id)) {
            return $this->error('You cannot edit a user who shares a role with you.', 403);
        }

        $this->db->update('users', $data, [
            'id'         => $id,
            'company_id' => $session->company->id,
        ]);

        if ($roleId > 0 && $session->branch !== null) {
            // Replace the user's role assignment at this specific node
            $this->db->executeStatement(
                'DELETE FROM user_node_roles WHERE user_id = :user_id AND node_id = :node_id',
                ['user_id' => $id, 'node_id' => $session->branch->id],
            );

            // Preserve is_primary if this node was already their primary
            $isPrimary = (bool) $this->db->fetchOne(
                'SELECT 1 FROM user_node_roles WHERE user_id = :user_id AND is_primary = 1 LIMIT 1',
                ['user_id' => $id],
            ) ? 0 : 1;

            $this->db->insert('user_node_roles', [
                'user_id'    => $id,
                'node_id'    => $session->branch->id,
                'role_id'    => $roleId,
                'is_primary' => $isPrimary,
            ]);
        }

        $changes = [];
        if ($data['name'] !== $user['name'])          $changes[] = 'name';
        if (isset($data['email']))                    $changes[] = 'email';
        if (isset($data['password']))                 $changes[] = 'password';
        if (isset($data['pin']))                      $changes[] = 'PIN';
        if (isset($data['can_dashboard_login']))      $changes[] = ($data['can_dashboard_login'] ? 'enabled' : 'disabled') . ' dashboard login';
        if (isset($data['can_pos_login']))            $changes[] = ($data['can_pos_login'] ? 'enabled' : 'disabled') . ' POS login';
        if ($roleId > 0) {
            $roleName = (string) $this->db->fetchOne(
                'SELECT name FROM roles WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
                ['id' => $roleId, 'company_id' => $session->company->id],
            );
            $changes[] = 'role set to ' . ($roleName ?: "role #$roleId");
        }

        $this->activityLog->record($session, 'user.update',
            [
                'name'    => $data['name'],
                'changes' => $changes ? implode(', ', $changes) : 'no changes',
            ],
            permission: 'edit_users', subjectType: 'user', subjectId: $id, request: $request,
        );

        return $this->success('User updated successfully.');
    }

    // =========================================================================
    // POST /dashboard/admin/users/{id}/delete — Delete (fetch)
    // =========================================================================

    #[Route('/{id}/delete', name: 'admin_users_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'delete_users');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        if ($this->isCurrentTenantUser($session, $id)) {
            return $this->error('You cannot delete your own account.');
        }

        if (!$this->canManageUserInContext($session, $id)) {
            return $this->error('You cannot delete a user at your hierarchy level or above.', 403);
        }

        // Peer-role protection: scoped to active node
        if (!$session->user->isSuperAdmin && $session->branch !== null
            && $this->shareRole($id, $session->user->id, $session->branch->id)) {
            return $this->error('You cannot delete a user who shares a role with you.', 403);
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, is_super_admin FROM users WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$user) return $this->error('User not found.', 404);
        if ($user['is_super_admin']) return $this->error('Super admin accounts cannot be deleted.');

        $this->auth->logoutAllDevices($id, $session->company->id);
        $this->db->executeStatement('DELETE FROM user_node_roles WHERE user_id = :id', ['id' => $id]);
        $this->db->executeStatement(
            'UPDATE users SET deleted_at = NOW() WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        $this->activityLog->record($session, 'user.delete',
            ['name' => $user['name'], 'email' => $user['email'] ?: 'N/A'],
            permission: 'delete_users', subjectType: 'user', subjectId: $id, request: $request,
        );

        return $this->success('User deleted successfully.');
    }

    /**
     * Returns true if $targetId and $callerId share at least one role at the given node.
     * Scoped to the current node so a BM in Branch A cannot be blocked by a peer in Branch B.
     */
    private function shareRole(int $targetId, int $callerId, int $nodeId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1
               FROM user_node_roles unr1
               JOIN user_node_roles unr2 ON unr2.role_id = unr1.role_id
                                        AND unr2.node_id  = unr1.node_id
              WHERE unr1.user_id  = :caller_id
                AND unr2.user_id  = :target_id
                AND unr1.node_id  = :node_id
              LIMIT 1',
            ['caller_id' => $callerId, 'target_id' => $targetId, 'node_id' => $nodeId],
        );
    }

    /**
     * Resolve a role_id to a valid assignable role in the current branch context.
     * Returns the role row on success, null if invalid / out of scope.
     */
    private function resolveOperationalRole(int $roleId, mixed $session): ?array
    {
        $isHq = $session->branch->isHq;
        $multiBranchEnabled = $this->isMultiBranchEnabled($session->company->id);
        $canViewLeadershipUsers = $this->canViewLeadershipUsers($session);

        if ($isHq) {
            // HQ context: role must be a company-wide template (branch_id IS NULL)
            $role = $this->db->fetchAssociative(
                'SELECT id, name FROM roles
                  WHERE id = :id AND company_id = :cid
                    AND branch_id IS NULL AND is_head_role = 0 AND deleted_at IS NULL',
                ['id' => $roleId, 'cid' => $session->company->id],
            );
        } else {
            // Branch / region context: role must belong specifically to this branch
            $role = $this->db->fetchAssociative(
                'SELECT id, name FROM roles
                  WHERE id = :id AND company_id = :cid
                    AND (
                        (branch_id = :bid AND is_head_role = 0)
                        OR (
                            :single_branch_mode = 1
                            AND branch_id IS NULL
                            AND is_head_role = 1
                            AND (
                                name = :branch_manager_name
                                OR (
                                    :can_view_leadership_users = 1
                                    AND name IN (:single_branch_leadership_role_names)
                                )
                            )
                        )
                    )
                    AND deleted_at IS NULL',
                [
                    'id' => $roleId,
                    'cid' => $session->company->id,
                    'bid' => $session->branch->id,
                    'single_branch_mode' => $multiBranchEnabled ? 0 : 1,
                    'branch_manager_name' => 'Branch Manager',
                    'can_view_leadership_users' => $canViewLeadershipUsers ? 1 : 0,
                    'single_branch_leadership_role_names' => ['Owner', 'Director'],
                ],
                [
                    'single_branch_leadership_role_names' => \Doctrine\DBAL\ArrayParameterType::STRING,
                ],
            );
        }

        return $role ?: null;
    }

    private function canManageUserInContext(mixed $session, int $targetUserId): bool
    {
        if ($session->user->isSuperAdmin) {
            return true;
        }

        if ($this->isCurrentTenantUser($session, $targetUserId)) {
            return false;
        }

        if ($session->branch === null) {
            return false;
        }

        if (!$this->canViewLeadershipUsers($session) && $this->userHasOwnerOrDirectorAssignment($targetUserId, $session->company->id)) {
            return false;
        }

        $targetRoleIds = array_values(array_unique(array_merge(
            $this->getTargetRoleIdsInContext($session, $targetUserId),
            $this->getTargetLeadershipRoleIds($targetUserId, $session->company->id),
        )));
        if ($targetRoleIds === []) {
            return true;
        }

        $actorRoleIds = $this->getActorHierarchyRoleIds($session);
        if ($actorRoleIds === []) {
            return false;
        }

        foreach ($targetRoleIds as $targetRoleId) {
            $manageable = false;
            foreach ($actorRoleIds as $actorRoleId) {
                if ($this->roleHierarchy->canManageRole($actorRoleId, $targetRoleId, $session->company->id)) {
                    $manageable = true;
                    break;
                }
            }
            if (!$manageable) {
                return false;
            }
        }

        return true;
    }

    private function canAssignRoleInContext(mixed $session, int $roleId): bool
    {
        if ($session->user->isSuperAdmin) {
            return true;
        }

        if ($session->branch === null) {
            return false;
        }

        $targetHierarchyRoleId = $this->resolveHierarchyRoleId($roleId);
        if ($targetHierarchyRoleId === null) {
            return true;
        }

        foreach ($this->getActorHierarchyRoleIds($session) as $actorRoleId) {
            if ($this->roleHierarchy->canManageRole($actorRoleId, $targetHierarchyRoleId, $session->company->id)) {
                return true;
            }
        }

        return false;
    }

    private function getTargetRoleIdsInContext(mixed $session, int $targetUserId): array
    {
        $scopeNodeIds = $this->hierarchy->getSubtreeIds($session->branch->id);
        if ($scopeNodeIds === []) {
            $scopeNodeIds = [$session->branch->id];
        }

        $placeholders = implode(',', array_fill(0, count($scopeNodeIds), '?'));
        $roleIds = $this->db->fetchFirstColumn(
            "SELECT DISTINCT role_id
               FROM user_node_roles
              WHERE user_id = ?
                AND node_id IN ({$placeholders})",
            array_merge([$targetUserId], $scopeNodeIds),
        );

        $hierarchyRoleIds = [];
        foreach (array_map('intval', $roleIds) as $roleId) {
            $resolved = $this->resolveHierarchyRoleId($roleId);
            if ($resolved !== null) {
                $hierarchyRoleIds[] = $resolved;
            }
        }

        return array_values(array_unique($hierarchyRoleIds));
    }

    private function getTargetLeadershipRoleIds(int $targetUserId, int $companyId): array
    {
        $roleIds = $this->db->fetchFirstColumn(
            "SELECT DISTINCT COALESCE(r.source_role_id, r.id) AS hierarchy_role_id
               FROM user_node_roles unr
               JOIN roles r ON r.id = unr.role_id
              WHERE unr.user_id = :user_id
                AND r.company_id = :company_id
                AND r.deleted_at IS NULL
                AND r.name IN ('Owner', 'Director')",
            [
                'user_id' => $targetUserId,
                'company_id' => $companyId,
            ],
        );

        return array_values(array_unique(array_map('intval', $roleIds)));
    }

    private function canViewLeadershipUsers(mixed $session): bool
    {
        if ($session->user->isSuperAdmin) {
            return true;
        }

        if ($session->branch === null) {
            return false;
        }

        $roleIds = $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id);
        if ($roleIds === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $roleNames = array_map(
            'strtolower',
            $this->db->fetchFirstColumn(
                "SELECT name
                   FROM roles
                  WHERE id IN ({$placeholders})
                    AND company_id = ?
                    AND deleted_at IS NULL",
                array_merge($roleIds, [$session->company->id]),
            ),
        );

        return (bool) array_intersect($roleNames, ['owner', 'director']);
    }

    private function userHasOwnerOrDirectorAssignment(int $userId, int $companyId): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1
               FROM user_node_roles unr
               JOIN roles r ON r.id = unr.role_id
              WHERE unr.user_id = :user_id
                AND r.company_id = :company_id
                AND r.deleted_at IS NULL
                AND r.name IN ('Owner', 'Director')
              LIMIT 1",
            [
                'user_id' => $userId,
                'company_id' => $companyId,
            ],
        );
    }

    private function userHasSingleBranchExcludedLeadershipAssignment(int $userId, int $companyId): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1
               FROM user_node_roles unr
               JOIN roles r ON r.id = unr.role_id
              WHERE unr.user_id = :user_id
                AND r.company_id = :company_id
                AND r.deleted_at IS NULL
                AND r.name IN ('Overall Manager', 'Regional Manager')
              LIMIT 1",
            [
                'user_id' => $userId,
                'company_id' => $companyId,
            ],
        );
    }

    private function getActorHierarchyRoleIds(mixed $session): array
    {
        $actorRoleIds = $this->branchPermissions->getUserEffectiveRoles($session->user->id, $session->branch->id);
        $resolved = [];
        foreach ($actorRoleIds as $roleId) {
            $hierarchyRoleId = $this->resolveHierarchyRoleId((int) $roleId);
            if ($hierarchyRoleId !== null) {
                $resolved[] = $hierarchyRoleId;
            }
        }

        return array_values(array_unique($resolved));
    }

    private function resolveHierarchyRoleId(int $roleId): ?int
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, source_role_id FROM roles WHERE id = :id AND deleted_at IS NULL',
            ['id' => $roleId],
        );

        if (!$row) {
            return null;
        }

        $candidateId = (int) ($row['source_role_id'] ?: $row['id']);
        $existsInHierarchy = $this->db->fetchOne(
            'SELECT 1 FROM role_hierarchy WHERE role_id = :role_id LIMIT 1',
            ['role_id' => $candidateId],
        );

        return $existsInHierarchy ? $candidateId : null;
    }

    private function isCurrentTenantUser(mixed $session, int $targetUserId): bool
    {
        return !$session->user->isSuperAdmin && $targetUserId === $session->user->id;
    }
}
