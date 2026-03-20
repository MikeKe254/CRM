<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ⚠️  DISPOSABLE SEED CONTROLLER — DELETE AFTER USE
 *
 * Visit: GET /dev/seed-users
 * Registers 3 test users for company_id = 1.
 * Safe to run multiple times — skips existing emails/PINs.
 */
class SeedUsersController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/dev/seed-users', name: 'dev_seed_users', methods: ['GET'])]
    public function seed(): JsonResponse
    {
        $companyId = 1;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $users = [
            [
                'name'                => 'Mike One',
                'email'               => 'mike1@angavu.test',
                'password'            => 'Mike@132',
                'pin'                 => '4490',
                'can_dashboard_login' => 1,
                'can_pos_login'       => 1,
                'is_super_admin'      => 0,
                'role_id'             => 1,
            ],
            [
                'name'                => 'Mike Two',
                'email'               => 'mike2@angavu.test',
                'password'            => 'Mike@254',
                'pin'                 => '4495',
                'can_dashboard_login' => 1,
                'can_pos_login'       => 1,
                'is_super_admin'      => 0,
                'role_id'             => 4,
            ],
            [
                'name'                => 'Mike Poly',
                'email'               => 'mikepoly@angavu.test',
                'password'            => 'Mike@132poly',
                'pin'                 => '6061',
                'can_dashboard_login' => 1,
                'can_pos_login'       => 1,
                'is_super_admin'      => 0,
                'role_id'             => 5,
            ],
        ];

        $results = [];

        foreach ($users as $data) {
            // Skip if email already registered for this company
            $existing = $this->db->fetchOne(
                'SELECT id FROM users WHERE email = :email AND company_id = :company_id LIMIT 1',
                ['email' => $data['email'], 'company_id' => $companyId],
            );

            if ($existing) {
                $results[] = [
                    'name'   => $data['name'],
                    'status' => 'skipped — already exists',
                    'id'     => $existing,
                ];
                continue;
            }

            // Hash password and PIN using PASSWORD_BCRYPT
            // These are verified by AuthService via password_verify()
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
            $hashedPin      = password_hash($data['pin'],      PASSWORD_BCRYPT);

            // Insert user
            $this->db->insert('users', [
                'company_id'          => $companyId,
                'name'                => $data['name'],
                'email'               => $data['email'],
                'password'            => $hashedPassword,
                'pin'                 => $hashedPin,
                'can_dashboard_login' => $data['can_dashboard_login'],
                'can_pos_login'       => $data['can_pos_login'],
                'is_super_admin'      => $data['is_super_admin'],
                'created_at'          => $now,
            ]);

            $userId = (int) $this->db->lastInsertId();

            // Assign role
            $this->db->insert('user_roles', [
                'user_id' => $userId,
                'role_id' => $data['role_id'],
            ]);

            $results[] = [
                'name'    => $data['name'],
                'status'  => 'created',
                'id'      => $userId,
                'email'   => $data['email'],
                'role_id' => $data['role_id'],
            ];
        }

        $platformAdminResult = $this->seedPlatformAdmin(
            name: 'Mike US',
            email: 'mikenjagius@gmail.com',
            password: 'Mike@132',
            roleName: 'PlatformAdmin',
            now: $now,
        );

        return $this->json([
            'message' => 'Seed complete. Delete this controller when done.',
            'users'   => $results,
            'platform_admin' => $platformAdminResult,
        ]);
    }

    private function seedPlatformAdmin(
        string $name,
        string $email,
        string $password,
        string $roleName,
        string $now,
    ): array {
        $existing = $this->db->fetchAssociative(
            'SELECT id, email, is_platform_owner FROM platform_admins WHERE email = :email LIMIT 1',
            ['email' => $email],
        );

        if ($existing) {
            $platformAdminId = (int) $existing['id'];
        } else {
            $this->db->insert('platform_admins', [
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'status' => 'active',
                'is_platform_owner' => 0,
                'is_system_account' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $platformAdminId = (int) $this->db->lastInsertId();
        }

        $roleId = $this->db->fetchOne(
            'SELECT id FROM platform_roles WHERE name = :name LIMIT 1',
            ['name' => $roleName],
        );

        if (!$roleId) {
            return [
                'status' => 'error',
                'message' => sprintf('Platform role "%s" was not found.', $roleName),
            ];
        }

        $roleExists = $this->db->fetchOne(
            'SELECT id
             FROM platform_admin_roles
             WHERE platform_admin_id = :platform_admin_id
               AND platform_role_id = :platform_role_id
             LIMIT 1',
            [
                'platform_admin_id' => $platformAdminId,
                'platform_role_id' => (int) $roleId,
            ],
        );

        if (!$roleExists) {
            $this->db->insert('platform_admin_roles', [
                'platform_admin_id' => $platformAdminId,
                'platform_role_id' => (int) $roleId,
                'created_at' => $now,
            ]);
        }

        return [
            'name' => $name,
            'email' => $email,
            'id' => $platformAdminId,
            'role' => $roleName,
            'status' => $existing ? 'skipped - already exists' : 'created',
        ];
    }
}
