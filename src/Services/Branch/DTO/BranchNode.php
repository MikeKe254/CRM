<?php

declare(strict_types=1);

namespace App\Services\Branch\DTO;

final class BranchNode
{
    /** @param BranchNode[] $children */
    public function __construct(
        public readonly int     $id,
        public readonly int     $companyId,
        public readonly ?int    $parentId,
        public readonly string  $name,
        public readonly string  $slug,
        public readonly string  $type,
        public readonly string  $path,
        public readonly int     $depth,
        public readonly bool    $isHq,
        public readonly string  $status,
        public array            $children = [],
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int)  $row['id'],
            companyId: (int)  $row['company_id'],
            parentId:  isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            name:      (string) $row['name'],
            slug:      (string) $row['slug'],
            type:      (string) $row['type'],
            path:      (string) $row['path'],
            depth:     (int)  $row['depth'],
            isHq:      (bool) $row['is_hq'],
            status:    (string) $row['status'],
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->deletedAt() === null;
    }

    private function deletedAt(): mixed
    {
        return null; // resolved at query level via deleted_at IS NULL
    }
}
