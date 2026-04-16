<?php

declare(strict_types=1);

namespace App\Services\Revenue;

use Doctrine\DBAL\Connection;

/**
 * Manages branch-scoped catalog items (services and products).
 *
 * Catalog items are lightweight revenue classifiers — a name, type, optional
 * category, and optional default price. They are NOT an inventory system.
 * Purpose: tag transactions so revenue can be sliced by "what was sold."
 */
final class CatalogService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // READS
    // =========================================================================

    /**
     * Returns all catalog items for a branch, ordered by type then name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $companyId, int $branchId, bool $includeInactive = false): array
    {
        $statusFilter = $includeInactive ? '' : "AND ci.status = 'active'";

        return $this->db->fetchAllAssociative(
            "SELECT ci.id, ci.name, ci.category, ci.type, ci.price, ci.show_in_terminal, ci.status, ci.created_at
               FROM catalog_items ci
              WHERE ci.company_id = :company_id
                AND ci.branch_id  = :branch_id
                AND ci.deleted_at IS NULL
                {$statusFilter}
              ORDER BY ci.type ASC, ci.category ASC, ci.name ASC",
            ['company_id' => $companyId, 'branch_id' => $branchId],
        );
    }

    /**
     * Returns active items only, for terminal quick-pick chips.
     * Groups by category so the terminal can organise chips.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActiveForTerminal(int $branchId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, name, category, type, price
               FROM catalog_items
              WHERE branch_id       = :branch_id
                AND status          = 'active'
                AND show_in_terminal = 1
                AND deleted_at      IS NULL
              ORDER BY category ASC, name ASC",
            ['branch_id' => $branchId],
        );
    }

    public function findById(int $id, int $companyId, int $branchId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM catalog_items
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );

        return $row ?: null;
    }

    // =========================================================================
    // WRITES
    // =========================================================================

    public function create(
        int $companyId,
        int $branchId,
        string $name,
        string $type,
        ?string $category,
        ?float $price,
        bool $showInTerminal = true,
    ): int {
        $this->db->insert('catalog_items', [
            'company_id'       => $companyId,
            'branch_id'        => $branchId,
            'name'             => $name,
            'type'             => $type,
            'category'         => $category,
            'price'            => $price,
            'show_in_terminal' => $showInTerminal ? 1 : 0,
            'status'           => 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        int $companyId,
        int $branchId,
        string $name,
        string $type,
        ?string $category,
        ?float $price,
        bool $showInTerminal = true,
    ): void {
        $this->db->executeStatement(
            'UPDATE catalog_items
                SET name = :name, type = :type, category = :category,
                    price = :price, show_in_terminal = :show_in_terminal
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id',
            [
                'name'             => $name,
                'type'             => $type,
                'category'         => $category,
                'price'            => $price,
                'show_in_terminal' => $showInTerminal ? 1 : 0,
                'id'               => $id,
                'company_id'       => $companyId,
                'branch_id'        => $branchId,
            ],
        );
    }

    public function setStatus(int $id, int $companyId, int $branchId, string $status): void
    {
        $this->db->executeStatement(
            'UPDATE catalog_items
                SET status = :status
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id AND deleted_at IS NULL',
            ['status' => $status, 'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );
    }

    public function delete(int $id, int $companyId, int $branchId): void
    {
        $this->db->executeStatement(
            'UPDATE catalog_items SET deleted_at = NOW()
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id',
            ['id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );
    }
}
