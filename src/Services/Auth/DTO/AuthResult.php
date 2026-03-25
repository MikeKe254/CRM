<?php

declare(strict_types=1);

namespace App\Services\Auth\DTO;

use App\Services\Branch\DTO\BranchNode;

/**
 * Returned by AuthService on every successful login.
 * Core fields are readonly. branch is resolved per-request from the URL
 * inside AdminBaseController::requireAdmin() and attached after construction.
 */
final class AuthResult
{
    /**
     * Active branch for this request.
     * Set by AdminBaseController::requireAdmin() after resolving the {branch} slug.
     * Null on login, branch picker, and platform admin routes.
     */
    public ?BranchNode $branch = null;

    /**
     * All branches this user can access (used for sidebar branch switcher).
     * Set alongside $branch by requireAdmin(). Empty on non-branch routes.
     *
     * @var BranchNode[]
     */
    public array $availableBranches = [];

    /**
     * Resolved navigation context for this request.
     *   'overall'     — user is at /overall/ (Overall Manager company-wide view)
     *   'operational' — user is at any regular /{branch}/ URL
     *
     * Set by requireAdmin() alongside $branch.
     */
    public string $context = 'operational';

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
