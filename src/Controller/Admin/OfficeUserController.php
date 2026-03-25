<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * OfficeUserController
 *
 * Manages office-level users (HQ and Regional staff).
 * Differs from UserController in that it handles users with user_type IN ('office', 'both')
 * assigned at depth=0 (HQ) or depth=1 (Regional) nodes.
 *
 * Same logic as UserController, but filtered to office context only.
 */
#[Route('/admin/office-users')]
class OfficeUserController extends AdminBaseController
{
    /**
     * List all office-level users
     * Filters to: user_type IN ('office', 'both')
     */
    #[Route('', name: 'office_users_list', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request, 'view_users');

        $company = $this->getCompany($request);
        $db = $this->container->get('db');

        // Get office users (type = 'office' or 'both')
        $users = $db->query("
            SELECT
              u.id,
              u.name,
              u.email,
              u.status,
              u.user_type,
              u.department_id,
              d.name as department_name,
              GROUP_CONCAT(CONCAT(b.name, ' (', r.name, ')') SEPARATOR ', ') as assignments
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN user_node_roles unr ON unr.user_id = u.id
            LEFT JOIN branches b ON b.id = unr.node_id
            LEFT JOIN roles r ON r.id = unr.role_id
            WHERE u.company_id = :company_id
              AND u.user_type IN ('office', 'both')
              AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.name
        ", ['company_id' => $company['id']])->fetchAll();

        // Get departments for filter
        $departments = $db->query(
            "SELECT id, name FROM departments WHERE company_id = :company_id AND deleted_at IS NULL ORDER BY name",
            ['company_id' => $company['id']]
        )->fetchAll();

        return $this->render('admin/office-users/index.html.twig', [
            'users' => $users,
            'departments' => $departments,
            'company' => $company,
        ]);
    }

    /**
     * Create new office user
     */
    #[Route('/create', name: 'office_users_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin($request, 'create_users');

        $company = $this->getCompany($request);
        $db = $this->container->get('db');

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            // Validate required fields
            if (empty($data['name']) || empty($data['email'])) {
                return $this->json(['error' => 'Name and email required'], 400);
            }

            // Create user with user_type = 'office'
            $stmt = $db->prepare("
                INSERT INTO users (company_id, name, email, password, user_type, status, created_at)
                VALUES (:company_id, :name, :email, :password, 'office', :status, NOW())
            ");

            $password = password_hash($data['password'] ?? 'DefaultPass123!', PASSWORD_BCRYPT);
            $stmt->execute([
                'company_id' => $company['id'],
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $password,
                'status' => $data['status'] ?? 'active',
            ]);

            $userId = $db->lastInsertId();

            // Assign to department if provided
            if (!empty($data['department_id'])) {
                $db->prepare("UPDATE users SET department_id = :dept_id WHERE id = :id")
                    ->execute(['dept_id' => $data['department_id'], 'id' => $userId]);
            }

            return $this->json(['success' => true, 'user_id' => $userId]);
        }

        // GET: Show form
        $departments = $db->query(
            "SELECT id, name FROM departments WHERE company_id = :company_id AND deleted_at IS NULL ORDER BY name",
            ['company_id' => $company['id']]
        )->fetchAll();

        return $this->render('admin/office-users/_form.html.twig', [
            'user' => null,
            'departments' => $departments,
            'company' => $company,
        ]);
    }

    /**
     * Edit office user
     */
    #[Route('/{id}/edit', name: 'office_users_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $this->requireAdmin($request, 'edit_users');

        $company = $this->getCompany($request);
        $db = $this->container->get('db');

        // Get user
        $user = $db->query(
            "SELECT * FROM users WHERE id = :id AND company_id = :company_id",
            ['id' => $id, 'company_id' => $company['id']]
        )->fetch();

        if (!$user) {
            throw $this->createNotFoundException("User not found");
        }

        // Verify user is office type
        if (!in_array($user['user_type'], ['office', 'both'])) {
            return $this->json(['error' => 'This user is not an office-level user'], 403);
        }

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            $stmt = $db->prepare("
                UPDATE users SET
                  name = :name,
                  email = :email,
                  status = :status,
                  department_id = :dept_id
                WHERE id = :id
            ");

            $stmt->execute([
                'name' => $data['name'],
                'email' => $data['email'],
                'status' => $data['status'] ?? 'active',
                'dept_id' => $data['department_id'] ?? null,
                'id' => $id,
            ]);

            return $this->json(['success' => true]);
        }

        // GET: Show form
        $departments = $db->query(
            "SELECT id, name FROM departments WHERE company_id = :company_id AND deleted_at IS NULL ORDER BY name",
            ['company_id' => $company['id']]
        )->fetchAll();

        return $this->render('admin/office-users/_form.html.twig', [
            'user' => $user,
            'departments' => $departments,
            'company' => $company,
        ]);
    }

    /**
     * Delete (soft-delete) office user
     */
    #[Route('/{id}/delete', name: 'office_users_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $this->requireAdmin($request, 'delete_users');

        $company = $this->getCompany($request);
        $db = $this->container->get('db');

        $db->prepare("UPDATE users SET deleted_at = NOW() WHERE id = :id AND company_id = :company_id")
            ->execute(['id' => $id, 'company_id' => $company['id']]);

        return $this->json(['success' => true]);
    }

    /**
     * Assign office user to a role at HQ/Regional node
     * Similar to UserController::assignRole but office-context only
     */
    #[Route('/{id}/assign-role', name: 'office_users_assign_role', methods: ['POST'])]
    public function assignRole(int $id, Request $request): Response
    {
        $this->requireAdmin($request, 'assign_roles');

        $company = $this->getCompany($request);
        $data = $request->request->all();
        $db = $this->container->get('db');

        $branchId = (int)$data['branch_id'];
        $roleId = (int)$data['role_id'];

        // Validate branch is HQ or Regional (depth 0-1)
        $branch = $db->query(
            "SELECT depth FROM branches WHERE id = :id AND company_id = :company_id",
            ['id' => $branchId, 'company_id' => $company['id']]
        )->fetch();

        if (!$branch || $branch['depth'] > 1) {
            return $this->json(['error' => 'Office users can only be assigned at HQ or Regional nodes'], 400);
        }

        // Create assignment
        $stmt = $db->prepare("
            INSERT INTO user_node_roles (user_id, role_id, node_id, is_primary)
            VALUES (:user_id, :role_id, :node_id, :is_primary)
        ");

        $stmt->execute([
            'user_id' => $id,
            'role_id' => $roleId,
            'node_id' => $branchId,
            'is_primary' => isset($data['is_primary']) ? 1 : 0,
        ]);

        return $this->json(['success' => true]);
    }
}
