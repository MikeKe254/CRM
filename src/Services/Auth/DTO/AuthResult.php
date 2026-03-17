<?php

declare(strict_types=1);

namespace App\Services\Auth\DTO;

/**
 * Returned by AuthService on every successful login.
 * Immutable value object — consume it, don't mutate it.
 */
final class AuthResult
{
    public function __construct(
        public readonly string $token,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly AuthUser $user,
        public readonly AuthCompany $company,
        public readonly string $deviceType,   // 'dashboard' | 'pos'
    ) {}

    public function toArray(): array
    {
        return [
            'token'       => $this->token,
            'expires_at'  => $this->expiresAt->format(\DateTimeInterface::ATOM),
            'device_type' => $this->deviceType,
            'user'        => $this->user->toArray(),
            'company'     => $this->company->toArray(),
        ];
    }
}
