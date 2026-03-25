<?php

declare(strict_types=1);

namespace App\Services\Branch\DTO;

final class EffectivePermissions
{
    /**
     * @param string[] $permissions  action_key strings e.g. ['view_users', 'create_users']
     * @param int[]    $roleIds      all role IDs contributing to this permission set
     */
    public function __construct(
        public readonly int                    $userId,
        public readonly int                    $branchId,
        public readonly array                  $permissions,
        public readonly array                  $roleIds,
        public readonly \DateTimeImmutable     $resolvedAt,
    ) {}

    public function has(string $permissionKey): bool
    {
        return in_array(strtolower($permissionKey), $this->permissions, true);
    }

    public function hasAny(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->has($key)) return true;
        }
        return false;
    }

    public function all(): array
    {
        return $this->permissions;
    }
}
