<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Auth\DTO\AuthCompany;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\DTO\AuthUser;
use App\Services\Auth\Exception\AuthException;
use Doctrine\DBAL\Connection;

final class PlatformAuthService
{
    private const SESSION_DAYS = 30;
    private const TOKEN_BYTES = 64;

    public function __construct(private readonly Connection $db) {}

    /** @throws AuthException */
    public function login(
        string $email,
        string $password,
        string $ipAddress = '',
        string $userAgent = '',
        string $deviceName = '',
    ): AuthResult {
        $admin = $this->findPlatformAdminByEmail($email);

        if (empty($admin['password']) || !password_verify($password, $admin['password'])) {
            throw AuthException::invalidCredentials();
        }

        return $this->createSession(
            admin:      $admin,
            company:    $this->syntheticPlatformCompany(),
            deviceType: 'dashboard',
            ipAddress:  $ipAddress,
            userAgent:  $userAgent,
            deviceName: $deviceName ?: 'Platform Dashboard',
        );
    }

    public function findActivePlatformAdminByEmailOrNull(string $email): array|false
    {
        $admin = $this->db->fetchAssociative(
            'SELECT * FROM platform_admins WHERE email = :email AND deleted_at IS NULL LIMIT 1',
            ['email' => $email],
        );

        if (!$admin || ($admin['status'] ?? 'inactive') !== 'active') {
            return false;
        }

        return $admin;
    }

    public function passwordMatches(array $admin, string $password): bool
    {
        return !empty($admin['password']) && password_verify($password, $admin['password']);
    }

    public function createCompanyContextSession(
        array $admin,
        array $company,
        string $ipAddress = '',
        string $userAgent = '',
        string $deviceName = '',
    ): AuthResult {
        return $this->createSession(
            admin:      $admin,
            company:    $company,
            deviceType: 'dashboard',
            ipAddress:  $ipAddress,
            userAgent:  $userAgent,
            deviceName: $deviceName ?: 'Platform Dashboard',
        );
    }

    /** @throws AuthException */
    public function buildAuthResultFromSession(array $session, string $rawToken): AuthResult
    {
        $admin = $this->findPlatformAdminById(abs((int) $session['user_id']));

        return $this->buildAuthResult(
            admin:      $admin,
            company:    $this->resolveCompanyById((int) $session['company_id']),
            rawToken:   $rawToken,
            expiresAt:  new \DateTimeImmutable($session['expires_at']),
            deviceType: $session['device_type'] ?? 'dashboard',
        );
    }

    public function getSessionUserId(array $admin): int
    {
        return -1 * (int) $admin['id'];
    }

    /** @throws AuthException */
    private function findPlatformAdminByEmail(string $email): array
    {
        $admin = $this->findActivePlatformAdminByEmailOrNull($email);

        if (!$admin) {
            throw AuthException::superAdminNotFound();
        }

        return $admin;
    }

    /** @throws AuthException */
    private function findPlatformAdminById(int $platformAdminId): array
    {
        $admin = $this->db->fetchAssociative(
            'SELECT * FROM platform_admins WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $platformAdminId],
        );

        if (!$admin || ($admin['status'] ?? 'inactive') !== 'active') {
            throw AuthException::accountNotFound();
        }

        return $admin;
    }

    private function createSession(
        array $admin,
        array $company,
        string $deviceType,
        string $ipAddress,
        string $userAgent,
        string $deviceName,
    ): AuthResult {
        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::SESSION_DAYS . ' days');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->insert('user_sessions', [
            'company_id'     => $company['id'],
            'user_id'        => $this->getSessionUserId($admin),
            'token_hash'     => $tokenHash,
            'device_name'    => $deviceName,
            'device_type'    => $deviceType,
            'ip_address'     => $ipAddress,
            'user_agent'     => $userAgent,
            'last_active_at' => $now,
            'expires_at'     => $expiresAt->format('Y-m-d H:i:s'),
            'created_at'     => $now,
        ]);

        return $this->buildAuthResult(
            admin:      $admin,
            company:    $company,
            rawToken:   $rawToken,
            expiresAt:  $expiresAt,
            deviceType: $deviceType,
        );
    }

    private function buildAuthResult(
        array $admin,
        array $company,
        string $rawToken,
        \DateTimeImmutable $expiresAt,
        string $deviceType,
    ): AuthResult {
        return new AuthResult(
            token:      $rawToken,
            expiresAt:  $expiresAt,
            deviceType: $deviceType,
            user: new AuthUser(
                id:                (int) $admin['id'],
                name:                    $admin['name'],
                email:                   $admin['email'] ?? null,
                isSuperAdmin:            true,
                canDashboardLogin:       true,
                canPosLogin:             false,
                roles:                   $this->getPlatformAdminRoles((int) $admin['id']),
                isPlatformOwner:    (bool) ($admin['is_platform_owner'] ?? false),
            ),
            company: new AuthCompany(
                id:        (int) $company['id'],
                name:            $company['name'],
                subdomain:       $company['subdomain'],
            ),
        );
    }

    private function getPlatformAdminRoles(int $platformAdminId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT pr.name
             FROM   platform_admin_roles par
             JOIN   platform_roles pr ON pr.id = par.platform_role_id
             WHERE  par.platform_admin_id = :admin_id
             ORDER  BY pr.name',
            ['admin_id' => $platformAdminId],
        );

        return array_column($rows, 'name');
    }

    private function resolveCompanyById(int $companyId): array
    {
        if ($companyId === 0) {
            return $this->syntheticPlatformCompany();
        }

        $company = $this->db->fetchAssociative(
            'SELECT * FROM companies WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $companyId],
        );

        if (!$company) {
            throw AuthException::tenantNotFound("id:{$companyId}");
        }

        return $company;
    }

    private function syntheticPlatformCompany(): array
    {
        return ['id' => 0, 'name' => 'Angavu Platform', 'subdomain' => '__platform__'];
    }
}
