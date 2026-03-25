<?php

declare(strict_types=1);

namespace App\Services\Company;

use Doctrine\DBAL\Connection;

/**
 * Manages company areas.
 *
 * Areas describe where staff work (Reception, Kitchen Area, Bar Area, etc.)
 * System areas (is_system=1) are platform defaults — they can be
 * deactivated but never deleted by tenant users.
 */
final class AreaService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // READS
    // =========================================================================

    /**
     * Returns all areas for a company, system ones first, then alphabetically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $companyId, bool $includeInactive = false): array
    {
        $statusFilter = $includeInactive ? '' : "AND a.status = 'active'";

        return $this->db->fetchAllAssociative(
            "SELECT a.id, a.name, a.description, a.is_system, a.is_transactional, a.status, a.created_at,
                    COUNT(u.id) AS user_count
               FROM areas a
               LEFT JOIN user_areas ua ON ua.area_id = a.id
               LEFT JOIN users u ON u.id = ua.user_id AND u.deleted_at IS NULL
              WHERE a.company_id = :company_id
                AND a.deleted_at IS NULL
                {$statusFilter}
              GROUP BY a.id
              ORDER BY a.is_system DESC, a.name ASC",
            ['company_id' => $companyId],
        );
    }

    public function findById(int $id, int $companyId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM areas WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $companyId],
        );

        return $row ?: null;
    }

    // =========================================================================
    // WRITES
    // =========================================================================

    public function create(int $companyId, string $name, ?string $description, bool $isTransactional = false): int
    {
        $this->db->insert('areas', [
            'company_id'       => $companyId,
            'name'             => $name,
            'description'      => $description,
            'is_system'        => 0,
            'is_transactional' => $isTransactional ? 1 : 0,
            'status'           => 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $companyId, string $name, ?string $description, bool $isTransactional = false): void
    {
        $this->db->executeStatement(
            'UPDATE areas SET name = :name, description = :description, is_transactional = :is_transactional WHERE id = :id AND company_id = :company_id',
            ['name' => $name, 'description' => $description, 'is_transactional' => $isTransactional ? 1 : 0, 'id' => $id, 'company_id' => $companyId],
        );
    }

    public function setStatus(int $id, int $companyId, string $status): void
    {
        $this->db->executeStatement(
            "UPDATE areas SET status = :status WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL",
            ['status' => $status, 'id' => $id, 'company_id' => $companyId],
        );
    }

    /**
     * Soft-delete an area.
     * System areas cannot be deleted — callers must check is_system before calling.
     */
    public function delete(int $id, int $companyId): void
    {
        // user_areas rows are removed automatically by ON DELETE CASCADE
        $this->db->executeStatement(
            'UPDATE areas SET deleted_at = NOW() WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $companyId],
        );
    }

    // =========================================================================
    // BOOTSTRAP
    // =========================================================================

    /**
     * Seed system areas for a freshly created company.
     * Safe to call multiple times — skips duplicates.
     */
    public function bootstrapDefaults(int $companyId): void
    {
        $defaults = [
            ['Reception / Front Desk',   'Main entrance, check-in, and front-desk customer touchpoint'],
            ['Main Dining Area',         'Primary dining space for seated guests'],
            ['Bar Area',                 'Bar counter and drink service zone'],
            ['Outdoor Seating / Terrace','Terrace, balcony, or outdoor dining and lounge area'],
            ['Kitchen Area',             'Cooking, food prep, and back-of-house kitchen space'],
            ['Storage / Store',          'Stockroom, dry store, and inventory holding area'],
            ['Staff Area / Back Office', 'Staff room, lockers, and back-office workspace'],
            ['Rooms / Accommodation',    'Guest bedrooms and accommodation units'],
            ['Corridors / Blocks',       'Hallways, stairwells, and block/floor common areas'],
            ['Laundry Area',             'Linen washing, drying, and housekeeping preparation zone'],
            ['Swimming Pool',            'Pool basin, surrounding deck, and pool equipment room'],
            ['Poolside / Deck',          'Poolside lounge, sun deck, and outdoor relaxation space'],
            ['Garden / Event Grounds',   'Garden, lawns, and outdoor event hosting space'],
            ['Stage / Event Area',       'Indoor or outdoor performance and event staging area'],
            ['Washing Bay',              'Vehicle washing area, bays, and related equipment zone'],
            ['Waiting Area',             'Customer waiting lounge or seating before service'],
            ['Shop Area',                'Retail floor, shelving, and point-of-sale zone'],
            ['Parking Area',             'Customer and staff vehicle parking space'],
        ];

        foreach ($defaults as [$name, $desc]) {
            $exists = $this->db->fetchOne(
                'SELECT id FROM areas WHERE company_id = :cid AND name = :name AND deleted_at IS NULL LIMIT 1',
                ['cid' => $companyId, 'name' => $name],
            );

            if (!$exists) {
                $this->db->insert('areas', [
                    'company_id'  => $companyId,
                    'name'        => $name,
                    'description' => $desc,
                    'is_system'   => 1,
                    'status'      => 'active',
                ]);
            }
        }
    }
}
