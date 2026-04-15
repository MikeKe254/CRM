<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Auth\DTO\AuthCompany;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\DTO\AuthUser;
use App\Services\Auth\Exception\AuthException;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║               Angavu Authentication Service  (v2)               ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Security model:                                                 ║
 * ║   • Raw token returned to client, SHA-256 hash stored in DB.    ║
 * ║     Leaked DB data cannot hijack sessions.                       ║
 * ║   • POS login is PIN-only (no user_id required).                ║
 * ║     PIN is unique per tenant and matched across all POS users.   ║
 * ║   • POS login requires an authorized terminal.                  ║
 * ║     A dashboard user must first authorize the device.            ║
 * ║   • Nullable booleans (can_pos_login, can_dashboard_login)       ║
 * ║     are safely cast — NULL is treated as false.                  ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Inject into any controller:                                     ║
 * ║                                                                  ║
 * ║    public function __construct(                                  ║
 * ║        private readonly AuthService $auth                        ║
 * ║    ) {}                                                          ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
final class AuthService
{
    /** Session lifetime */
    private const SESSION_DAYS = 30;

    /** Raw token byte length — 64 bytes = 128 hex chars */
    private const TOKEN_BYTES = 64;

    public function __construct(
        private readonly Connection $db,
        private readonly PlatformAuthService $platformAuth,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Dashboard login: email + password, scoped to a subdomain tenant.
     *
     * On success the terminal_identifier is recorded in pos_terminals so this
     * device becomes an authorized POS terminal for the same tenant.
     *
     * @param string $terminalIdentifier  Stable client fingerprint (e.g. hashed
     *                                    device UUID). Send from every client so
     *                                    dashboard logins auto-authorize the device.
     * @throws AuthException
     */
    public function loginDashboard(
        string $subdomain,
        string $email,
        string $password,
        string $ipAddress          = '',
        string $userAgent          = '',
        string $deviceName         = '',
        string $terminalIdentifier = '',
    ): AuthResult {
        $company = $this->resolveCompany($subdomain);
        $tenantUser = $this->findUserByEmailOrNull($email, (int) $company['id']);

        if (
            $tenantUser
            && $this->safeBool($tenantUser['can_dashboard_login'])
            && !empty($tenantUser['password'])
            && password_verify($password, $tenantUser['password'])
        ) {
            $result = $this->createSession(
                user:       $tenantUser,
                company:    $company,
                deviceType: 'dashboard',
                ipAddress:  $ipAddress,
                userAgent:  $userAgent,
                deviceName: $deviceName ?: 'Dashboard',
            );

            if ($terminalIdentifier !== '') {
                $this->authorizeTerminal(
                    companyId:            (int) $company['id'],
                    terminalIdentifier:   $terminalIdentifier,
                    authorizedByUserId:   $this->getSessionUserId($tenantUser),
                    deviceName:           $deviceName ?: 'Dashboard',
                    ipAddress:            $ipAddress,
                );
            }

            return $result;
        }

        $platformAdmin = $this->platformAuth->findActivePlatformAdminByEmailOrNull($email);

        if ($platformAdmin && $this->platformAuth->passwordMatches($platformAdmin, $password)) {
            return $this->platformAuth->createCompanyContextSession(
                admin:      $platformAdmin,
                company:    $company,
                ipAddress:  $ipAddress,
                userAgent:  $userAgent,
                deviceName: $deviceName ?: 'Dashboard',
            );
        }

        if ($tenantUser && !$this->safeBool($tenantUser['can_dashboard_login'])) {
            throw AuthException::dashboardLoginNotAllowed();
        }

        if ($tenantUser || $platformAdmin) {
            throw AuthException::invalidCredentials();
        }

        throw AuthException::accountNotFound();
    }

    /**
     * POS login: PIN only, scoped to a subdomain tenant.
     *
     * - Verifies the terminal is authorized before attempting PIN match.
     * - Matches the PIN against all POS-enabled users in the tenant.
     *   PINs must therefore be unique per company (enforce at creation time).
     *
     * @param string $terminalIdentifier  The stable device fingerprint.
     *                                    Must match an active pos_terminals record.
     * @throws AuthException
     */
    public function loginPos(
        string $subdomain,
        string $pin,
        string $terminalIdentifier,
        string $ipAddress  = '',
        string $userAgent  = '',
        string $deviceName = '',
    ): AuthResult {
        $company = $this->resolveCompany($subdomain);

        // ── 1. Verify this is an authorized terminal ──────────────────────────
        $this->assertTerminalAuthorized((int) $company['id'], $terminalIdentifier);

        // ── 2. Find the matching user by PIN within this tenant ───────────────
        $user = $this->findUserByPin($pin, (int) $company['id']);

        if (!$this->safeBool($user['can_pos_login'])) {
            throw AuthException::posLoginNotAllowed();
        }

        return $this->createSession(
            user:       $user,
            company:    $company,
            deviceType: 'pos',
            ipAddress:  $ipAddress,
            userAgent:  $userAgent,
            deviceName: $deviceName ?: 'POS Terminal',
        );
    }

    /**
     * Super admin login: email + password, cross-tenant.
     *
     * @throws AuthException
     */
    public function loginSuperAdmin(
        string $email,
        string $password,
        string $ipAddress  = '',
        string $userAgent  = '',
        string $deviceName = '',
    ): AuthResult {
        return $this->platformAuth->login(
            email:      $email,
            password:   $password,
            ipAddress:  $ipAddress,
            userAgent:  $userAgent,
            deviceName: $deviceName ?: 'Super Admin',
        );
    }

    /**
     * Validate an incoming session token.
     * Hashes the raw token and looks up the hash — the raw token never touches DB.
     * Touches last_active_at on every successful call.
     *
     * @throws AuthException
     */
    public function validateSession(string $rawToken): AuthResult
    {
        $hash    = $this->hashToken($rawToken);
        $session = $this->db->fetchAssociative(
            'SELECT * FROM user_sessions WHERE token_hash = :hash LIMIT 1',
            ['hash' => $hash],
        );

        if (!$session) {
            throw AuthException::sessionNotFound();
        }

        if ($session['revoked_at'] !== null) {
            throw AuthException::sessionRevoked();
        }

        if (new \DateTimeImmutable($session['expires_at']) < new \DateTimeImmutable()) {
            throw AuthException::sessionExpired();
        }

        $this->db->executeStatement(
            'UPDATE user_sessions SET last_active_at = NOW() WHERE id = :id',
            ['id' => $session['id']],
        );

        if ((int) $session['user_id'] < 0) {
            return $this->platformAuth->buildAuthResultFromSession($session, $rawToken);
        }

        $user    = $this->findUserById((int) $session['user_id'], (int) $session['company_id']);
        $company = $this->resolveCompanyById((int) $session['company_id']);

        return $this->buildAuthResult(
            user:       $user,
            company:    $company,
            rawToken:   $rawToken,
            expiresAt:  new \DateTimeImmutable($session['expires_at']),
            deviceType: $session['device_type'] ?? 'dashboard',
        );
    }

    /**
     * Revoke a single session (logout current device).
     */
    public function logout(string $rawToken): void
    {
        $hash = $this->hashToken($rawToken);
        $this->db->executeStatement(
            'UPDATE user_sessions SET revoked_at = NOW() WHERE token_hash = :hash',
            ['hash' => $hash],
        );
    }

    /**
     * Revoke all sessions for a user within a tenant (logout all devices).
     *
     * @return int Number of sessions revoked
     */
    public function logoutAllDevices(int $userId, int $companyId): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE user_sessions
             SET    revoked_at = NOW()
             WHERE  user_id    = :user_id
               AND  company_id = :company_id
               AND  revoked_at IS NULL',
            ['user_id' => $userId, 'company_id' => $companyId],
        );
    }

    /**
     * Extend session by another SESSION_DAYS from now.
     *
     * @throws AuthException
     */
    public function refreshSession(string $rawToken): AuthResult
    {
        $hash    = $this->hashToken($rawToken);
        $session = $this->db->fetchAssociative(
            'SELECT * FROM user_sessions
             WHERE  token_hash = :hash
               AND  revoked_at IS NULL
             LIMIT 1',
            ['hash' => $hash],
        );

        if (!$session) {
            throw AuthException::sessionNotFound();
        }

        $newExpiry = (new \DateTimeImmutable())->modify('+' . self::SESSION_DAYS . ' days');

        $this->db->executeStatement(
            'UPDATE user_sessions
             SET expires_at = :expires_at, last_active_at = NOW()
             WHERE id = :id',
            ['expires_at' => $newExpiry->format('Y-m-d H:i:s'), 'id' => $session['id']],
        );

        if ((int) $session['user_id'] < 0) {
            return $this->platformAuth->buildAuthResultFromSession($session, $rawToken);
        }

        $user    = $this->findUserById((int) $session['user_id'], (int) $session['company_id']);
        $company = $this->resolveCompanyById((int) $session['company_id']);

        return $this->buildAuthResult(
            user:       $user,
            company:    $company,
            rawToken:   $rawToken,
            expiresAt:  $newExpiry,
            deviceType: $session['device_type'] ?? 'dashboard',
        );
    }

    /**
     * List all active (non-expired, non-revoked) sessions for a user.
     * Useful for a "Manage Devices" screen.
     */
    public function getActiveSessions(int $userId, int $companyId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, device_name, device_type, ip_address,
                    last_active_at, expires_at, created_at
             FROM   user_sessions
             WHERE  user_id    = :user_id
               AND  company_id = :company_id
               AND  revoked_at IS NULL
               AND  expires_at > NOW()
             ORDER  BY last_active_at DESC',
            ['user_id' => $userId, 'company_id' => $companyId],
        );
    }

    /**
     * Revoke a POS terminal authorization.
     * After this, PIN login from that device will be rejected.
     */
    public function revokeTerminal(int $companyId, string $terminalIdentifier): void
    {
        $this->db->executeStatement(
            'UPDATE pos_terminals
             SET    revoked_at = NOW()
             WHERE  company_id           = :company_id
               AND  terminal_identifier  = :identifier
               AND  revoked_at IS NULL',
            ['company_id' => $companyId, 'identifier' => $terminalIdentifier],
        );
    }

    /**
     * List all authorized (active) terminals for a tenant.
     */
    public function getAuthorizedTerminals(int $companyId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT pt.id, pt.terminal_identifier, pt.device_name,
                    pt.ip_address, pt.authorized_at,
                    COALESCE(u.name, pa.name, \'Platform Admin\') AS authorized_by
             FROM   pos_terminals pt
             LEFT JOIN users u
                    ON pt.authorized_by_user_id > 0
                   AND u.id = pt.authorized_by_user_id
             LEFT JOIN platform_admins pa
                    ON pt.authorized_by_user_id < 0
                   AND pa.id = ABS(pt.authorized_by_user_id)
             WHERE  pt.company_id = :company_id
               AND  pt.revoked_at IS NULL
             ORDER  BY pt.authorized_at DESC',
            ['company_id' => $companyId],
        );
    }

    // =========================================================================
    // PRIVATE — SESSION CREATION
    // =========================================================================

    /**
     * Insert a session row and return a populated AuthResult.
     * The raw token is returned to the caller; only its hash is persisted.
     */
    private function createSession(
        array  $user,
        array  $company,
        string $deviceType,
        string $ipAddress,
        string $userAgent,
        string $deviceName,
    ): AuthResult {
        $rawToken  = $this->generateRawToken();
        $tokenHash = $this->hashToken($rawToken);
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::SESSION_DAYS . ' days');
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->insert('user_sessions', [
            'company_id'     => $company['id'],
            'user_id'        => $this->getSessionUserId($user),
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
            user:       $user,
            company:    $company,
            rawToken:   $rawToken,
            expiresAt:  $expiresAt,
            deviceType: $deviceType,
        );
    }

    /**
     * Assemble an AuthResult DTO from raw data.
     */
    private function buildAuthResult(
        array              $user,
        array              $company,
        string             $rawToken,
        \DateTimeImmutable $expiresAt,
        string             $deviceType,
    ): AuthResult {
        $isPlatformAdmin = $this->isPlatformAdmin($user);
        $roles = $isPlatformAdmin
            ? ['PlatformAdmin']
            : $this->getUserRoles((int) $user['id'], (int) $company['id']);

        return new AuthResult(
            token:      $rawToken,
            expiresAt:  $expiresAt,
            deviceType: $deviceType,
            user: new AuthUser(
                id:                (int)  $user['id'],
                name:                     $user['name'],
                email:                    $user['email']  ?? null,
                isSuperAdmin:             $isPlatformAdmin,
                canDashboardLogin:        $isPlatformAdmin ? true : $this->safeBool($user['can_dashboard_login']),
                canPosLogin:              $isPlatformAdmin ? false : $this->safeBool($user['can_pos_login']),
                roles:                    $roles,
                isPlatformOwner:          $isPlatformAdmin && (bool) ($user['is_platform_owner'] ?? false),
            ),
            company: new AuthCompany(
                id:        (int) $company['id'],
                name:            $company['name'],
                subdomain:       $company['subdomain'],
            ),
        );
    }

    // =========================================================================
    // PRIVATE — TERMINAL AUTHORIZATION
    // =========================================================================

    /**
     * Assert that the terminal_identifier belongs to an active authorized terminal.
     *
     * @throws AuthException
     */
    private function assertTerminalAuthorized(int $companyId, string $terminalIdentifier): void
    {
        $terminal = $this->db->fetchAssociative(
            'SELECT id, expires_at FROM pos_terminals
             WHERE  company_id          = :company_id
               AND  terminal_identifier = :identifier
               AND  revoked_at IS NULL
             LIMIT 1',
            ['company_id' => $companyId, 'identifier' => $terminalIdentifier],
        );

        if (!$terminal) {
            throw AuthException::terminalNotAuthorized();
        }

        // Check expiry if set
        if (
            !empty($terminal['expires_at']) &&
            new \DateTimeImmutable($terminal['expires_at']) < new \DateTimeImmutable()
        ) {
            throw AuthException::terminalExpired();
        }
    }

    /**
     * Upsert an authorized terminal record.
     * If the terminal already exists (even if revoked), it is reactivated.
     */
    private function authorizeTerminal(
        int    $companyId,
        string $terminalIdentifier,
        int    $authorizedByUserId,
        string $deviceName,
        string $ipAddress,
    ): void {
        $expiresAt = (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d H:i:s');
        $branchId = $this->resolveTerminalBranchId($companyId, $authorizedByUserId);

        $existing = $this->db->fetchAssociative(
            'SELECT id FROM pos_terminals
             WHERE  company_id          = :company_id
               AND  terminal_identifier = :identifier
             LIMIT 1',
            ['company_id' => $companyId, 'identifier' => $terminalIdentifier],
        );

        if ($existing) {
            $this->db->executeStatement(
                'UPDATE pos_terminals
                 SET    authorized_by_user_id = :user_id,
                        branch_id             = :branch_id,
                        device_name           = :device_name,
                        ip_address            = :ip_address,
                        authorized_at         = NOW(),
                        expires_at            = :expires_at,
                        revoked_at            = NULL
                 WHERE  id = :id',
                [
                    'user_id'     => $authorizedByUserId,
                    'branch_id'   => $branchId,
                    'device_name' => $deviceName,
                    'ip_address'  => $ipAddress,
                    'expires_at'  => $expiresAt,
                    'id'          => $existing['id'],
                ],
            );
        } else {
            $this->db->insert('pos_terminals', [
                'company_id'            => $companyId,
                'branch_id'             => $branchId,
                'terminal_identifier'   => $terminalIdentifier,
                'authorized_by_user_id' => $authorizedByUserId,
                'device_name'           => $deviceName,
                'ip_address'            => $ipAddress,
                'authorized_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'expires_at'            => $expiresAt,
            ]);
        }
    }

    private function resolveTerminalBranchId(int $companyId, int $authorizedByUserId): ?int
    {
        if (!$this->isMultiBranchEnabled($companyId)) {
            $singleBranchId = $this->db->fetchOne(
                'SELECT id
                   FROM branches
                  WHERE company_id = :company_id
                    AND slug = :slug
                    AND deleted_at IS NULL
                  LIMIT 1',
                [
                    'company_id' => $companyId,
                    'slug' => 'head-office-branch',
                ],
            );

            if ($singleBranchId !== false) {
                return (int) $singleBranchId;
            }
        }

        if ($authorizedByUserId > 0) {
            $nodeId = $this->db->fetchOne(
                'SELECT unr.node_id
                   FROM user_node_roles unr
                   JOIN branches b ON b.id = unr.node_id
                  WHERE unr.user_id = :user_id
                    AND b.company_id = :company_id
                    AND b.deleted_at IS NULL
                  ORDER BY unr.is_primary DESC, unr.node_id ASC
                  LIMIT 1',
                [
                    'user_id' => $authorizedByUserId,
                    'company_id' => $companyId,
                ],
            );

            if ($nodeId !== false) {
                return (int) $nodeId;
            }
        }

        return null;
    }

    private function isMultiBranchEnabled(int $companyId): bool
    {
        $platformReleased = (bool) $this->db->fetchOne(
            'SELECT platform_released FROM modules WHERE slug = :slug LIMIT 1',
            ['slug' => 'multi_branch'],
        );

        if (!$platformReleased) {
            return false;
        }

        $inPlan = (bool) $this->db->fetchOne(
            'SELECT 1
               FROM company_subscriptions cs
               JOIN plan_features pf        ON pf.plan_id      = cs.plan_id
               JOIN module_features mf      ON mf.id           = pf.feature_id
               JOIN module_submodules ms    ON ms.id           = mf.submodule_id
               JOIN modules m               ON m.id            = ms.module_id
              WHERE cs.company_id = :cid
                AND m.slug        = :module
                AND cs.status    IN (\'trial\', \'active\')
                AND (cs.ends_at IS NULL OR cs.ends_at > NOW())
              LIMIT 1',
            ['cid' => $companyId, 'module' => 'multi_branch'],
        );

        if ($inPlan) {
            return true;
        }

        return (bool) $this->db->fetchOne(
            'SELECT tfo.is_enabled
               FROM tenant_feature_overrides tfo
               JOIN module_features mf   ON mf.id  = tfo.feature_id
               JOIN module_submodules ms ON ms.id  = mf.submodule_id
               JOIN modules m            ON m.id   = ms.module_id
              WHERE tfo.company_id = :cid
                AND m.slug         = :module
                AND tfo.is_enabled = 1
                AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
              LIMIT 1',
            ['cid' => $companyId, 'module' => 'multi_branch'],
        );
    }

    // =========================================================================
    // PRIVATE — DB LOOKUPS
    // =========================================================================

    /** @throws AuthException */
    private function resolveCompany(string $subdomain): array
    {
        $company = $this->db->fetchAssociative(
            'SELECT * FROM companies WHERE id <> 0 AND subdomain = :subdomain AND deleted_at IS NULL LIMIT 1',
            ['subdomain' => $subdomain],
        );

        if (!$company) {
            throw AuthException::tenantNotFound($subdomain);
        }

        return $company;
    }

    private function resolveCompanyById(int $companyId): array
    {
        if ($companyId === 0) {
            return $this->syntheticSuperAdminCompany();
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

    private function findUserByEmailOrNull(string $email, int $companyId): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT * FROM users WHERE email = :email AND company_id = :company_id AND deleted_at IS NULL LIMIT 1',
            ['email' => $email, 'company_id' => $companyId],
        );
    }

    /**
     * Find a POS user by matching their hashed PIN within a tenant.
     * All candidate users with can_pos_login = 1 are fetched and verified
     * using password_verify so timing attacks across users are mitigated.
     *
     * @throws AuthException
     */
    private function findUserByPin(string $pin, int $companyId): array
    {
        $candidates = $this->db->fetchAllAssociative(
            'SELECT * FROM users
             WHERE  company_id   = :company_id
               AND  can_pos_login = 1
               AND  pin IS NOT NULL
               AND  deleted_at IS NULL',
            ['company_id' => $companyId],
        );

        foreach ($candidates as $candidate) {
            if (password_verify($pin, $candidate['pin'])) {
                return $candidate;
            }
        }

        throw AuthException::invalidPin();
    }

    /** @throws AuthException */
    private function findUserById(int $userId, int $companyId): array
    {
        if ($companyId === 0) {
            $user = false;
        } else {
            $user = $this->db->fetchAssociative(
                'SELECT * FROM users WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL LIMIT 1',
                ['id' => $userId, 'company_id' => $companyId],
            );
        }

        if (!$user) {
            throw AuthException::accountNotFound();
        }

        return $user;
    }

    private function getUserRoles(int $userId, int $companyId): array
    {
        if ($companyId === 0) {
            return ['SuperAdmin'];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT r.name
             FROM   user_roles ur
             JOIN   roles r ON r.id = ur.role_id
             WHERE  ur.user_id    = :user_id
               AND  r.company_id  = :company_id',
            ['user_id' => $userId, 'company_id' => $companyId],
        );

        return array_column($rows, 'name');
    }

    private function isPlatformAdmin(array $user): bool
    {
        return ($user['__auth_source'] ?? null) === 'platform_admin';
    }

    private function getSessionUserId(array $user): int
    {
        if ($this->isPlatformAdmin($user)) {
            return -1 * (int) $user['id'];
        }

        return (int) $user['id'];
    }

    // =========================================================================
    // PRIVATE — UTILITIES
    // =========================================================================

    /**
     * Generate a cryptographically secure raw session token.
     */
    private function generateRawToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Hash a raw token for DB storage.
     * SHA-256 is appropriate here: tokens are already high-entropy random values,
     * so a fast hash is safe and avoids bcrypt overhead on every request.
     */
    private function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Safely cast a nullable DB value to bool.
     * NULL, 0, "0", false → false
     * 1, "1", true        → true
     */
    private function safeBool(mixed $value): bool
    {
        return $value !== null && (bool) $value;
    }

}
