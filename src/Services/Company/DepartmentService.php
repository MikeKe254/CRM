<?php

declare(strict_types=1);

namespace App\Services\Company;

use Doctrine\DBAL\Connection;

/**
 * Manages company departments.
 *
 * Departments describe what staff do (Restaurant, Kitchen, Finance, etc.)
 * System departments (is_system=1) are platform defaults — they can be
 * deactivated but never deleted by tenant users.
 *
 * Every department is scoped to a specific branch via branch_id.
 */
final class DepartmentService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // READS
    // =========================================================================

    /**
     * Returns all departments for a branch, system ones first, then alphabetically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $companyId, int $branchId, bool $includeInactive = false): array
    {
        $statusFilter = $includeInactive ? '' : "AND d.status = 'active'";

        return $this->db->fetchAllAssociative(
            "SELECT d.id, d.name, d.description, d.is_system, d.status, d.created_at,
                    COUNT(u.id) AS user_count
               FROM departments d
               LEFT JOIN users u ON u.department_id = d.id AND u.deleted_at IS NULL
              WHERE d.company_id = :company_id
                AND d.branch_id  = :branch_id
                AND d.deleted_at IS NULL
                {$statusFilter}
              GROUP BY d.id
              ORDER BY d.is_system DESC, d.name ASC",
            ['company_id' => $companyId, 'branch_id' => $branchId],
        );
    }

    public function findById(int $id, int $companyId, int $branchId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM departments
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );

        return $row ?: null;
    }

    // =========================================================================
    // WRITES
    // =========================================================================

    public function create(int $companyId, int $branchId, string $name, ?string $description): int
    {
        $this->db->insert('departments', [
            'company_id'  => $companyId,
            'branch_id'   => $branchId,
            'name'        => $name,
            'description' => $description,
            'is_system'   => 0,
            'status'      => 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $companyId, int $branchId, string $name, ?string $description): void
    {
        $this->db->executeStatement(
            'UPDATE departments
                SET name = :name, description = :description
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id',
            ['name' => $name, 'description' => $description, 'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );
    }

    public function setStatus(int $id, int $companyId, int $branchId, string $status): void
    {
        $this->db->executeStatement(
            "UPDATE departments
                SET status = :status
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id AND deleted_at IS NULL",
            ['status' => $status, 'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );
    }

    /**
     * Soft-delete a department.
     * System departments cannot be deleted — callers must check is_system before calling.
     */
    public function delete(int $id, int $companyId, int $branchId): void
    {
        // Unassign users from this department first
        $this->db->executeStatement(
            'UPDATE users SET department_id = NULL WHERE department_id = :id',
            ['id' => $id],
        );

        $this->db->executeStatement(
            'UPDATE departments SET deleted_at = NOW()
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id',
            ['id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );
    }

    // =========================================================================
    // BOOTSTRAP
    // =========================================================================

    /**
     * Seed system departments for a freshly created company into its head-office branch.
     * Safe to call multiple times — skips duplicates.
     */
    public function bootstrapDefaults(int $companyId, int $branchId): void
    {
        $defaults = [
            ['Restaurant',              'Front-of-house restaurant service and dining operations'],
            ['Kitchen',                 'Food preparation, cooking, and kitchen operations'],
            ['Bar / Beverage',          'Bar service, beverages, and drink preparation'],
            ['Retail / Shop',           'Retail sales, merchandising, and shop operations'],
            ['Housekeeping',            'Cleaning, room preparation, and facility upkeep'],
            ['Maintenance / Facilities','Building maintenance, repairs, and facility management'],
            ['Finance',                 'Accounting, payroll, budgets, and financial reporting'],
            ['Administration',          'Admin support, coordination, and office management'],
            ['Security',                'Premises security, access control, and safety'],
        ];

        foreach ($defaults as [$name, $desc]) {
            $exists = $this->db->fetchOne(
                'SELECT id FROM departments
                  WHERE branch_id = :bid AND name = :name AND deleted_at IS NULL LIMIT 1',
                ['bid' => $branchId, 'name' => $name],
            );

            if (!$exists) {
                $this->db->insert('departments', [
                    'company_id'  => $companyId,
                    'branch_id'   => $branchId,
                    'name'        => $name,
                    'description' => $desc,
                    'is_system'   => 1,
                    'status'      => 'active',
                ]);
            }
        }
    }
}
