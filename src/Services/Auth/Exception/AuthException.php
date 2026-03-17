<?php

declare(strict_types=1);

namespace App\Services\Auth\Exception;

/**
 * Thrown by AuthService for any authentication failure.
 *
 * Usage in a controller:
 *
 *   catch (AuthException $e) {
 *       return $this->json(['error' => $e->getMessage()], $e->getHttpStatus());
 *   }
 */
final class AuthException extends \RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $httpStatus,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    // ── Factories ────────────────────────────────────────────────────────────

    public static function invalidCredentials(): self
    {
        return new self('Invalid email or password.', 401);
    }

    public static function invalidPin(): self
    {
        return new self('Invalid PIN.', 401);
    }

    public static function accountNotFound(): self
    {
        return new self('No account found.', 404);
    }

    public static function dashboardLoginNotAllowed(): self
    {
        return new self('This account is not permitted to access the dashboard.', 403);
    }

    public static function posLoginNotAllowed(): self
    {
        return new self('This account is not permitted to access the POS terminal.', 403);
    }

    public static function terminalNotAuthorized(): self
    {
        return new self(
            'This device is not an authorized POS terminal. '
            . 'A dashboard user must log in first to authorize this device.',
            403,
        );
    }

    public static function terminalExpired(): self
    {
        return new self(
            'This terminal authorization has expired. Please re-authorize this device.',
            403,
        );
    }

    public static function sessionExpired(): self
    {
        return new self('Your session has expired. Please log in again.', 401);
    }

    public static function sessionRevoked(): self
    {
        return new self('This session has been revoked.', 401);
    }

    public static function sessionNotFound(): self
    {
        return new self('Session not found.', 401);
    }

    public static function tenantNotFound(string $subdomain): self
    {
        return new self("Tenant '{$subdomain}' not found.", 404);
    }

    public static function superAdminNotFound(): self
    {
        return new self('Super admin account not found.', 404);
    }
}