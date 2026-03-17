<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Permission\CheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/admin/users')]
class UserController extends AdminBaseController
{
    public function __construct(
        AuthService            $auth,
        CheckPermissionService $can,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $can);
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
                    GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS roles
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

        $name    = trim((string) $request->request->get('name', ''));
        $email   = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');
        $pin     = (string) $request->request->get('pin', '');
        $roleId  = (int) $request->request->get('role_id', 0);
        $canDash = (bool) $request->request->get('can_dashboard_login', false);
        $canPos  = (bool) $request->request->get('can_pos_login', false);

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
            'name'                => trim((string) $request->request->get('name', $user['name'])),
            'can_dashboard_login' => $request->request->has('can_dashboard_login') ? 1 : 0,
            'can_pos_login'       => $request->request->has('can_pos_login') ? 1 : 0,
        ];

        $email = trim((string) $request->request->get('email', ''));
        if ($email !== '') $data['email'] = $email;

        $password = (string) $request->request->get('password', '');
        if ($password !== '') $data['password'] = password_hash($password, PASSWORD_BCRYPT);

        $pin = (string) $request->request->get('pin', '');
        if ($pin !== '') $data['pin'] = password_hash($pin, PASSWORD_BCRYPT);

        $this->db->update('users', $data, [
            'id'         => $id,
            'company_id' => $session->company->id,
        ]);

        $roleId = (int) $request->request->get('role_id', 0);
        if ($roleId > 0) {
            $this->db->executeStatement('DELETE FROM user_roles WHERE user_id = :id', ['id' => $id]);
            $this->db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
        }

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

        $user = $this->db->fetchAssociative(
            'SELECT id, is_super_admin FROM users WHERE id = :id AND company_id = :company_id',
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

        return $this->success('User deleted successfully.');
    }
}
