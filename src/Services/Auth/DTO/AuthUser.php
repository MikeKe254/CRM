<?php

declare(strict_types=1);

namespace App\Services\Auth\DTO;

/**
 * Lightweight user snapshot attached to every AuthResult.
 */
final class AuthUser
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly ?string $email,
        public readonly bool   $isSuperAdmin,
        public readonly bool   $canDashboardLogin,
        public readonly bool   $canPosLogin,
        public readonly array  $roles,           // ['Admin', 'Manager', ...]
        public readonly bool   $isPlatformOwner = false,  // platform_admins.is_platform_owner
    ) {}

    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'email'               => $this->email,
            'is_super_admin'      => $this->isSuperAdmin,
            'can_dashboard_login' => $this->canDashboardLogin,
            'can_pos_login'       => $this->canPosLogin,
            'roles'               => $this->roles,
            'is_platform_owner'   => $this->isPlatformOwner,
        ];
    }
}
