<?php

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Services\Branch\DTO\BranchNode;
use Doctrine\DBAL\Connection;

final class TerminalBranchAccessService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function resolveBranchNode(int $companyId, string $branchSlug): ?BranchNode
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, company_id, parent_id, name, slug, type, path, depth, is_hq, status
               FROM branches
              WHERE company_id = :company_id
                AND slug = :slug
                AND deleted_at IS NULL
              LIMIT 1',
            [
                'company_id' => $companyId,
                'slug' => $branchSlug,
            ],
        );

        return $row ? BranchNode::fromRow($row) : null;
    }

    public function terminalMatchesBranch(int $companyId, string $terminalIdentifier, int $branchId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1
               FROM pos_terminals
              WHERE company_id = :company_id
                AND branch_id = :branch_id
                AND terminal_identifier = :identifier
                AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1',
            [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'identifier' => $terminalIdentifier,
            ],
        );
    }

    public function userAssignedToBranch(int $userId, int $branchId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1
               FROM user_node_roles
              WHERE user_id = :user_id
                AND node_id = :branch_id
              LIMIT 1',
            [
                'user_id' => $userId,
                'branch_id' => $branchId,
            ],
        );
    }
}
