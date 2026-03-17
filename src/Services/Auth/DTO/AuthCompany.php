<?php

declare(strict_types=1);

namespace App\Services\Auth\DTO;

/**
 * Tenant snapshot attached to every AuthResult.
 */
final class AuthCompany
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $subdomain,
    ) {}

    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'subdomain' => $this->subdomain,
        ];
    }
}
