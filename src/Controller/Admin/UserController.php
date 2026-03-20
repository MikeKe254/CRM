<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/admin/users', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin$)[A-Za-z0-9-]+', 'domain' => '.+'])]
class UserController extends AdminBaseController
{
    public function __construct(
        AuthService            $auth,
        CheckPermissionService $can,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
        private readonly UserActivityLogService $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan);
    }

    // =========================================================================
    // GET /dashboard/admin/users — List (Twig)
    // =========================================================================

    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'view_users');
        if ($session instanceof Response) return $session;

        $users = $this->db->fetchAllAssociative(
            'SELECT u.id, u.name, u.email, u.can_dashboard_login, u.can_pos_login,
                    u.is_super_admin, u.created_at,
                    MIN(ur.role_id)                                        AS role_id,
                    GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ")    AS roles
             FROM   users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE  u.company_id = :company_id
             GROUP  BY u.id
             ORDER  BY u.name',
            ['company_id' => $session->company->id],
        );

        $roles = $this->db->fetchAllAssociative(
            'SELECT id, name FROM roles WHERE company_id = :company_id ORDER BY name',
            ['company_id' => $session->company->id],
        );

        $this->activityLog->record($session, 'user.view', request: $request);

        return $this->render('admin/users/index.html.twig', [
            'session' => $session,
            'users'   => $users,
            'roles'   => $roles,
            'can'     => [
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
                'SELECT id FROM users WHERE email = :email AND company_id = :company_id',
                ['email' => $email, 'company_id' => $session->company->id],
            );
            if ($exists) {
                return $this->error('A user with this email already exists.');
            }
        }

        $this->db->insert('users', [
            'company_id'          => $session->company->id,
            'name'                => $name,
            'email'               => $email ?: null,
            'password'            => $password ? password_hash($password, PASSWORD_BCRYPT) : null,
            'pin'                 => $pin ? password_hash($pin, PASSWORD_BCRYPT) : null,
            'can_dashboard_login' => $canDash ? 1 : 0,
            'can_pos_login'       => $canPos ? 1 : 0,
            'is_super_admin'      => 0,
            'created_at'          => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $userId = (int) $this->db->lastInsertId();

        if ($roleId > 0) {
            $this->db->insert('user_roles', [
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
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
            'SELECT * FROM users WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$user) {
            return $this->error('User not found.', 404);
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

        $password = (string) $request->request->get('password', '');
        if ($password !== '') $data['password'] = password_hash($password, PASSWORD_BCRYPT);

        $pin = (string) $request->request->get('pin', '');
        if ($pin !== '') $data['pin'] = password_hash($pin, PASSWORD_BCRYPT);

        // Block self-role-assignment
        $roleId = (int) $request->request->get('role_id', 0);
        if ($roleId > 0 && $id === $session->user->id) {
            return $this->error('You cannot assign a role to your own account.', 403);
        }

        // Peer-role protection: cannot edit a user who shares a role with you
        if (!$session->user->isSuperAdmin && $this->shareRole($id, $session->user->id, $session->company->id)) {
            return $this->error('You cannot edit a user who shares a role with you.', 403);
        }

        $this->db->update('users', $data, [
            'id'         => $id,
            'company_id' => $session->company->id,
        ]);

        if ($roleId > 0) {
            // Role containment guard: you cannot assign a role whose permission set
            // exceeds your own — prevents privilege escalation through role assignment.
            if (!$session->user->isSuperAdmin) {
                $escalation = (int) $this->db->fetchOne(
                    'SELECT COUNT(*)
                       FROM role_permissions rp
                      WHERE rp.role_id = :role_id
                        AND rp.permission_id NOT IN (
                            SELECT rp2.permission_id
                              FROM user_roles ur
                              JOIN role_permissions rp2 ON rp2.role_id = ur.role_id
                              JOIN roles r              ON r.id = ur.role_id
                             WHERE ur.user_id    = :user_id
                               AND r.company_id  = :company_id
                        )',
                    [
                        'role_id'    => $roleId,
                        'user_id'    => $session->user->id,
                        'company_id' => $session->company->id,
                    ],
                );

                if ($escalation > 0) {
                    return $this->error('You cannot assign a role that contains permissions you do not hold.', 403);
                }
            }

            $this->db->executeStatement('DELETE FROM user_roles WHERE user_id = :id', ['id' => $id]);
            $this->db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
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
                'SELECT name FROM roles WHERE id = :id AND company_id = :company_id',
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

        if ($id === $session->user->id) {
            return $this->error('You cannot delete your own account.');
        }

        // Peer-role protection
        if (!$session->user->isSuperAdmin && $this->shareRole($id, $session->user->id, $session->company->id)) {
            return $this->error('You cannot delete a user who shares a role with you.', 403);
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, is_super_admin FROM users WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$user) return $this->error('User not found.', 404);
        if ($user['is_super_admin']) return $this->error('Super admin accounts cannot be deleted.');

        $this->auth->logoutAllDevices($id, $session->company->id);
        $this->db->executeStatement('DELETE FROM user_roles WHERE user_id = :id', ['id' => $id]);
        $this->db->executeStatement(
            'DELETE FROM users WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        $this->activityLog->record($session, 'user.delete',
            ['name' => $user['name'], 'email' => $user['email'] ?: 'N/A'],
            permission: 'delete_users', subjectType: 'user', subjectId: $id, request: $request,
        );

        return $this->success('User deleted successfully.');
    }

    /**
     * Returns true if $targetId and $callerId share at least one role within the company.
     */
    private function shareRole(int $targetId, int $callerId, int $companyId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1
               FROM user_roles ur1
               JOIN user_roles ur2 ON ur2.role_id = ur1.role_id
               JOIN roles r ON r.id = ur1.role_id
              WHERE ur1.user_id    = :caller_id
                AND ur2.user_id    = :target_id
                AND r.company_id   = :company_id
              LIMIT 1',
            ['caller_id' => $callerId, 'target_id' => $targetId, 'company_id' => $companyId],
        );
    }
}
