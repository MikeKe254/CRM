<?php

declare(strict_types=1);

namespace App\Services\ActivityLog;

use App\Services\Auth\DTO\AuthResult;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║              Angavu User Activity Log Service                    ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Records what users actually DO inside a tenant — not just       ║
 * ║  permission changes, but real actions: viewed a page, sent an   ║
 * ║  STK push, updated a user, exported a report, etc.              ║
 * ║                                                                  ║
 * ║  Differs from user_logs (which only records permission           ║
 * ║  assignment/revocation events).                                  ║
 * ║                                                                  ║
 * ║  Features:                                                       ║
 * ║   • Never throws — logging must never break the main flow        ║
 * ║   • Detects actor_type automatically from the session            ║
 * ║   • Accepts slug strings — resolves to FK IDs internally         ║
 * ║   • Per-request in-memory cache — slug lookups run once          ║
 * ║   • Accepts optional Request for IP address capture              ║
 * ║   • Accepts optional JSON metadata for structured context        ║
 * ║   • Works for both tenant users and platform admins acting       ║
 * ║     on behalf of a tenant                                        ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Inject into any controller:                                     ║
 * ║                                                                  ║
 * ║    public function __construct(                                  ║
 * ║        ...                                                       ║
 * ║        private readonly UserActivityLogService $activityLog,    ║
 * ║    ) {}                                                          ║
 * ║                                                                  ║
 * ║  Basic usage (module only):                                      ║
 * ║    $this->activityLog->log(                                      ║
 * ║        session:     $session,                                    ║
 * ║        module:      self::MODULE_TRANSACTIONS,                   ║
 * ║        action:      self::ACTION_VIEW,                           ║
 * ║        description: 'Viewed transaction list',                   ║
 * ║    );                                                            ║
 * ║                                                                  ║
 * ║  Full usage (module + submodule + feature):                      ║
 * ║    $this->activityLog->log(                                      ║
 * ║        session:     $session,                                    ║
 * ║        module:      self::MODULE_TRANSACTIONS,                   ║
 * ║        action:      self::ACTION_SEND,                           ║
 * ║        description: 'Sent STK push of KES 1,500 to 0712345678', ║
 * ║        permission:  'send_stk',                                  ║
 * ║        submodule:   'payments',                                  ║
 * ║        feature:     'mpesa',                                     ║
 * ║        subjectType: 'stk_push',                                  ║
 * ║        subjectId:   1234,                                        ║
 * ║        metadata:    ['amount' => 1500, 'phone' => '0712345678'],║
 * ║        request:     $request,                                    ║
 * ║    );                                                            ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
final class UserActivityLogService
{
    // =========================================================================
    // MODULE SLUGS  — match modules.slug in the database
    // =========================================================================

    public const MODULE_CUSTOMER_CRM        = 'customer_crm';
    public const MODULE_TRANSACTIONS        = 'transactions';
    public const MODULE_BUSINESS_MANAGEMENT = 'business_management';
    public const MODULE_ANALYTICS           = 'analytics';
    public const MODULE_MENU                = 'menu';
    public const MODULE_ONLINE_ORDERS       = 'online_orders';
    public const MODULE_PAYMENTS            = 'payments';
    public const MODULE_MARKETING           = 'marketing';
    public const MODULE_LOYALTY             = 'loyalty';
    public const MODULE_COMMUNICATIONS      = 'communications';
    public const MODULE_INVENTORY           = 'inventory';
    public const MODULE_INTEGRATIONS        = 'integrations';
    public const MODULE_SETTINGS            = 'settings';
    public const MODULE_SECURITY            = 'security';

    // =========================================================================
    // ACTION VERBS  — stored uppercase in the database
    // =========================================================================

    public const ACTION_VIEW    = 'VIEW';
    public const ACTION_CREATE  = 'CREATE';
    public const ACTION_UPDATE  = 'UPDATE';
    public const ACTION_DELETE  = 'DELETE';
    public const ACTION_SEND    = 'SEND';
    public const ACTION_EXPORT  = 'EXPORT';
    public const ACTION_APPROVE = 'APPROVE';
    public const ACTION_REJECT  = 'REJECT';
    public const ACTION_LOGIN   = 'LOGIN';
    public const ACTION_LOGOUT  = 'LOGOUT';
    public const ACTION_ASSIGN  = 'ASSIGN';
    public const ACTION_REVOKE  = 'REVOKE';
    public const ACTION_SEARCH  = 'SEARCH';
    public const ACTION_PRINT   = 'PRINT';
    public const ACTION_IMPORT  = 'IMPORT';
    public const ACTION_ENABLE  = 'ENABLE';
    public const ACTION_DISABLE = 'DISABLE';

    /**
     * Per-request in-memory slug → ID cache.
     * Structure: ['module' => ['transactions' => 2], 'submodule' => [...], 'feature' => [...]]
     */
    private array $slugCache = [
        'module'    => [],
        'submodule' => [],
        'feature'   => [],
    ];

    /**
     * Per-request cache for resolved activity_log_templates rows.
     * Key: action_key string. Value: row array|null (null = not found, cached to avoid repeat queries).
     */
    private array $templateCache = [];

    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // PRIMARY API  — template-driven (preferred)
    // =========================================================================

    /**
     * Record activity using a stored template. This is the preferred call site.
     *
     * The service looks up the action_key in activity_log_templates, fills
     * {placeholder} variables from $context, resolves module/submodule/feature
     * IDs automatically, and writes the log row.  If no template exists it
     * falls back to a readable description derived from the action_key itself —
     * so the log row is always written, even for undeclared actions.
     *
     * The $context array is also stored as JSON metadata so it's queryable.
     *
     * @param AuthResult   $session      Validated session
     * @param string       $actionKey    Dot-notation key matching activity_log_templates.action_key
     *                                    e.g. 'payment.stk.send', 'customer.create', 'auth.login'
     * @param array        $context      Placeholder values used to fill the template
     *                                    e.g. ['phone' => '0712345678', 'amount' => 5000, 'customer' => 'John']
     *                                    e.g. ['name' => 'Jane Smith', 'email' => 'jane@acme.com']
     *                                    e.g. ['from' => 'MPesa', 'to' => 'Cash']
     * @param string|null  $permission   Permission that covered this action (audit trail only)
     * @param string|null  $subjectType  Entity acted on: 'transaction', 'customer', 'user', etc.
     * @param int|null     $subjectId    ID of that entity
     * @param Request|null $request      Pass to capture the requester's IP address
     */
    public function record(
        AuthResult  $session,
        string      $actionKey,
        array       $context     = [],
        ?string     $permission  = null,
        ?string     $subjectType = null,
        ?int        $subjectId   = null,
        ?Request    $request     = null,
    ): void {
        try {
            $tpl = $this->resolveTemplate($actionKey);

            $description = $tpl !== null
                ? $this->fillTemplate($tpl['template'], $context)
                : $this->fallbackDescription($actionKey, $context);

            $action = strtoupper((string) ($tpl['default_action'] ?? 'ACTION'));

            $moduleId    = ($tpl['module_slug']    ?? null) !== null
                ? $this->resolveModule($tpl['module_slug'])
                : null;
            $submoduleId = ($tpl['submodule_slug'] ?? null) !== null && $moduleId !== null
                ? $this->resolveSubmodule($tpl['submodule_slug'], $moduleId)
                : null;
            $featureId   = ($tpl['feature_slug']   ?? null) !== null && $submoduleId !== null
                ? $this->resolveFeature($tpl['feature_slug'], $submoduleId)
                : null;

            $this->db->insert('user_activity_logs', [
                'company_id'   => $session->company->id,
                'branch_id'    => $session->branch?->id,
                'user_id'      => $session->user->id,
                'actor_type'   => $session->user->isSuperAdmin ? 'superadmin' : 'tenant',
                'module_id'    => $moduleId,
                'submodule_id' => $submoduleId,
                'feature_id'   => $featureId,
                'action'       => $action,
                'permission'   => $permission,
                'description'  => $description,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'metadata'     => empty($context) ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                'ip_address'   => $request?->getClientIp(),
                'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Logging must never break the main request flow.
        }
    }

    // =========================================================================
    // LEGACY API  — explicit description (backward-compatible, still supported)
    // =========================================================================

    /**
     * Record a user activity with an explicit description string.
     * Prefer record() for new code — this method exists for cases where
     * the description cannot be templated (e.g. very dynamic, one-off narratives).
     *
     * @param AuthResult   $session      Validated session — provides user_id, company_id, actor_type
     * @param string       $module       Module slug — use MODULE_* constants
     *                                    e.g. self::MODULE_TRANSACTIONS
     * @param string       $action       What was done — use ACTION_* constants
     *                                    e.g. self::ACTION_UPDATE
     * @param string       $description  Human-readable narrative of the action
     *                                    e.g. 'Changed status from pending to completed for TX #1234'
     *                                    e.g. 'Sent STK push of KES 1,500 to 0712345678 via shortcode 174379'
     *                                    e.g. 'Deleted user John Doe (john@acme.com)'
     * @param string|null  $permission   The permission that covered this action
     *                                    e.g. 'view_transactions', 'send_stk'
     * @param string|null  $submodule    Submodule slug within the module (optional)
     *                                    e.g. 'records', 'payments', 'orders'
     * @param string|null  $feature      Feature slug within the submodule (optional, most granular)
     *                                    e.g. 'mpesa', 'refunds', 'payment_status'
     * @param string|null  $subjectType  The type of entity acted on
     *                                    e.g. 'transaction', 'user', 'role', 'terminal', 'stk_push'
     * @param int|null     $subjectId    The ID of that entity
     * @param array        $metadata     Extra structured data stored as JSON
     *                                    e.g. ['amount' => 1500, 'phone' => '0712345678']
     *                                    e.g. ['from' => 'pending', 'to' => 'completed']
     *                                    e.g. ['shortcode' => '174379', 'reference' => 'ABC123']
     * @param Request|null $request      Pass to capture the requester's IP address
     */
    public function log(
        AuthResult  $session,
        string      $module,
        string      $action,
        string      $description,
        ?string     $permission  = null,
        ?string     $submodule   = null,
        ?string     $feature     = null,
        ?string     $subjectType = null,
        ?int        $subjectId   = null,
        array       $metadata    = [],
        ?Request    $request     = null,
    ): void {
        try {
            $moduleId    = $this->resolveModule($module);
            $submoduleId = ($submodule !== null && $moduleId !== null)
                ? $this->resolveSubmodule($submodule, $moduleId)
                : null;
            $featureId   = ($feature !== null && $submoduleId !== null)
                ? $this->resolveFeature($feature, $submoduleId)
                : null;

            $this->db->insert('user_activity_logs', [
                'company_id'   => $session->company->id,
                'branch_id'    => $session->branch?->id,
                'user_id'      => $session->user->id,
                'actor_type'   => $session->user->isSuperAdmin ? 'superadmin' : 'tenant',
                'module_id'    => $moduleId,
                'submodule_id' => $submoduleId,
                'feature_id'   => $featureId,
                'action'       => strtoupper($action),
                'permission'   => $permission,
                'description'  => $description,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'metadata'     => empty($metadata) ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'ip_address'   => $request?->getClientIp(),
                'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Logging must never break the main request flow.
        }
    }

    // =========================================================================
    // CONVENIENCE SHORTCUTS
    // =========================================================================

    /**
     * Log a VIEW action. Use on every index/show/detail page.
     *
     * Examples:
     *   $this->activityLog->view($session, self::MODULE_TRANSACTIONS,
     *       'Viewed transaction list', request: $request);
     *
     *   $this->activityLog->view($session, self::MODULE_CUSTOMER_CRM,
     *       'Viewed profile of John Doe', subjectType: 'customer', subjectId: 42, request: $request);
     */
    public function view(
        AuthResult  $session,
        string      $module,
        string      $description,
        ?string     $permission  = null,
        ?string     $submodule   = null,
        ?string     $feature     = null,
        ?string     $subjectType = null,
        ?int        $subjectId   = null,
        ?Request    $request     = null,
    ): void {
        $this->log(
            session:     $session,
            module:      $module,
            action:      self::ACTION_VIEW,
            description: $description,
            permission:  $permission,
            submodule:   $submodule,
            feature:     $feature,
            subjectType: $subjectType,
            subjectId:   $subjectId,
            request:     $request,
        );
    }

    /**
     * Log a CREATE action.
     *
     * Example:
     *   $this->activityLog->create(
     *       $session, self::MODULE_BUSINESS_MANAGEMENT,
     *       'Created user John Doe (john@acme.com)',
     *       permission:  'create_users',
     *       submodule:   'users',
     *       feature:     'user_accounts',
     *       subjectType: 'user',
     *       subjectId:   $newUserId,
     *       metadata:    ['email' => 'john@acme.com', 'roles' => ['Manager']],
     *       request:     $request,
     *   );
     */
    public function create(
        AuthResult  $session,
        string      $module,
        string      $description,
        ?string     $permission  = null,
        ?string     $submodule   = null,
        ?string     $feature     = null,
        ?string     $subjectType = null,
        ?int        $subjectId   = null,
        array       $metadata    = [],
        ?Request    $request     = null,
    ): void {
        $this->log(
            session:     $session,
            module:      $module,
            action:      self::ACTION_CREATE,
            description: $description,
            permission:  $permission,
            submodule:   $submodule,
            feature:     $feature,
            subjectType: $subjectType,
            subjectId:   $subjectId,
            metadata:    $metadata,
            request:     $request,
        );
    }

    /**
     * Log an UPDATE action. Always include what changed in the description or metadata.
     *
     * Example:
     *   $this->activityLog->update(
     *       $session, self::MODULE_TRANSACTIONS,
     *       'Changed status from pending to completed',
     *       permission:  'edit_transactions',
     *       submodule:   'records',
     *       subjectType: 'transaction',
     *       subjectId:   $txId,
     *       metadata:    ['from' => 'pending', 'to' => 'completed'],
     *       request:     $request,
     *   );
     */
    public function update(
        AuthResult  $session,
        string      $module,
        string      $description,
        ?string     $permission  = null,
        ?string     $submodule   = null,
        ?string     $feature     = null,
        ?string     $subjectType = null,
        ?int        $subjectId   = null,
        array       $metadata    = [],
        ?Request    $request     = null,
    ): void {
        $this->log(
            session:     $session,
            module:      $module,
            action:      self::ACTION_UPDATE,
            description: $description,
            permission:  $permission,
            submodule:   $submodule,
            feature:     $feature,
            subjectType: $subjectType,
            subjectId:   $subjectId,
            metadata:    $metadata,
            request:     $request,
        );
    }

    /**
     * Log a DELETE action. Always include identifying info in metadata.
     *
     * Example:
     *   $this->activityLog->delete(
     *       $session, self::MODULE_BUSINESS_MANAGEMENT,
     *       'Deleted user Jane Smith (jane@acme.com)',
     *       permission:  'delete_users',
     *       submodule:   'users',
     *       subjectType: 'user',
     *       subjectId:   $userId,
     *       metadata:    ['name' => 'Jane Smith', 'email' => 'jane@acme.com'],
     *       request:     $request,
     *   );
     */
    public function delete(
        AuthResult  $session,
        string      $module,
        string      $description,
        ?string     $permission  = null,
        ?string     $submodule   = null,
        ?string     $feature     = null,
        ?string     $subjectType = null,
        ?int        $subjectId   = null,
        array       $metadata    = [],
        ?Request    $request     = null,
    ): void {
        $this->log(
            session:     $session,
            module:      $module,
            action:      self::ACTION_DELETE,
            description: $description,
            permission:  $permission,
            submodule:   $submodule,
            feature:     $feature,
            subjectType: $subjectType,
            subjectId:   $subjectId,
            metadata:    $metadata,
            request:     $request,
        );
    }

    /**
     * Log a SEND action — STK pushes, SMS, emails, notifications, receipts.
     *
     * Example:
     *   $this->activityLog->send(
     *       $session, self::MODULE_TRANSACTIONS,
     *       'Sent STK push of KES 1,500 to 0712345678',
     *       permission:  'send_stk',
     *       submodule:   'payments',
     *       feature:     'mpesa',
     *       subjectType: 'stk_push',
     *       subjectId:   $pushId,
     *       metadata:    ['amount' => 1500, 'phone' => '0712345678', 'shortcode' => '174379'],
     *       request:     $request,
     *   );
     */
    public function send(
        AuthResult  $session,
        string      $module,
        string      $description,
        ?string     $permission  = null,
        ?string     $submodule   = null,
        ?string     $feature     = null,
        ?string     $subjectType = null,
        ?int        $subjectId   = null,
        array       $metadata    = [],
        ?Request    $request     = null,
    ): void {
        $this->log(
            session:     $session,
            module:      $module,
            action:      self::ACTION_SEND,
            description: $description,
            permission:  $permission,
            submodule:   $submodule,
            feature:     $feature,
            subjectType: $subjectType,
            subjectId:   $subjectId,
            metadata:    $metadata,
            request:     $request,
        );
    }

    // =========================================================================
    // QUERY HELPERS
    // =========================================================================

    /**
     * Fetch recent activity for a company, newest first.
     * Joins modules/submodules/features so the caller gets human-readable names.
     *
     * @param int         $companyId
     * @param int         $limit        Max rows (default 50, hard cap 500)
     * @param string|null $moduleSlug   Filter by module slug e.g. 'transactions'
     * @param string|null $action       Filter by action verb e.g. 'SEND'
     * @param int|null    $userId       Filter by specific user
     */
    public function recent(
        int     $companyId,
        int     $limit      = 50,
        ?string $moduleSlug = null,
        ?string $action     = null,
        ?int    $userId     = null,
    ): array {
        $where  = ['l.company_id = :company_id'];
        $params = ['company_id'  => $companyId];

        if ($moduleSlug !== null) {
            $moduleId = $this->resolveModule($moduleSlug);
            if ($moduleId !== null) {
                $where[]            = 'l.module_id = :module_id';
                $params['module_id'] = $moduleId;
            }
        }

        if ($action !== null) {
            $where[]          = 'l.action = :action';
            $params['action'] = strtoupper($action);
        }

        if ($userId !== null) {
            $where[]           = 'l.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $sql = sprintf(
            'SELECT
                l.*,
                u.name  AS user_name,
                m.name  AS module_name,
                sm.name AS submodule_name,
                f.name  AS feature_name
             FROM user_activity_logs l
             LEFT JOIN users            u  ON u.id  = l.user_id
             LEFT JOIN modules          m  ON m.id  = l.module_id
             LEFT JOIN module_submodules sm ON sm.id = l.submodule_id
             LEFT JOIN module_features   f  ON f.id  = l.feature_id
             WHERE %s
             ORDER BY l.created_at DESC
             LIMIT %d',
            implode(' AND ', $where),
            max(1, min(500, $limit)),
        );

        return $this->db->fetchAllAssociative($sql, $params);
    }

    /**
     * Fetch the full history of a specific subject entity — all actions on that record.
     *
     * Example: every action ever taken on transaction #1234
     *   $history = $this->activityLog->forSubject($companyId, 'transaction', 1234);
     */
    public function forSubject(int $companyId, string $subjectType, int $subjectId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT
                l.*,
                u.name  AS user_name,
                m.name  AS module_name,
                sm.name AS submodule_name,
                f.name  AS feature_name
             FROM user_activity_logs l
             LEFT JOIN users             u  ON u.id  = l.user_id
             LEFT JOIN modules           m  ON m.id  = l.module_id
             LEFT JOIN module_submodules sm ON sm.id = l.submodule_id
             LEFT JOIN module_features    f ON f.id  = l.feature_id
             WHERE l.company_id   = :company_id
               AND l.subject_type = :subject_type
               AND l.subject_id   = :subject_id
             ORDER BY l.created_at DESC',
            [
                'company_id'   => $companyId,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
            ],
        );
    }

    // =========================================================================
    // PRIVATE — TEMPLATE RESOLUTION
    // =========================================================================

    /**
     * Fetch a template row from activity_log_templates.
     * Result (row array or null) is cached per action_key for the lifetime of
     * this request so repeated calls for the same key hit the DB only once.
     */
    private function resolveTemplate(string $actionKey): ?array
    {
        if (array_key_exists($actionKey, $this->templateCache)) {
            return $this->templateCache[$actionKey];
        }

        $row = $this->db->fetchAssociative(
            'SELECT template, module_slug, submodule_slug, feature_slug, default_action
               FROM activity_log_templates
              WHERE action_key = :key
                AND is_active  = 1
              LIMIT 1',
            ['key' => $actionKey],
        );

        return $this->templateCache[$actionKey] = ($row ?: null);
    }

    /**
     * Replace {placeholder} tokens in a template string with context values.
     *
     * Example:
     *   fillTemplate('Sent STK to {customer} ({phone}) · KES {amount}',
     *                ['customer' => 'John', 'phone' => '0712345678', 'amount' => 5000])
     *   → 'Sent STK to John (0712345678) · KES 5000'
     */
    private function fillTemplate(string $template, array $context): string
    {
        if (empty($context)) {
            return $template;
        }

        $search  = array_map(static fn(string $k): string => '{' . $k . '}', array_keys($context));
        $replace = array_map('strval', array_values($context));

        return str_replace($search, $replace, $template);
    }

    /**
     * Build a readable fallback description when no template exists for an action_key.
     * Converts 'payment.stk.send' → 'Payment stk send', then appends context values.
     */
    private function fallbackDescription(string $actionKey, array $context): string
    {
        $readable = ucfirst(str_replace(['.', '_'], ' ', $actionKey));

        if (empty($context)) {
            return $readable;
        }

        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . ': ' . $value;
        }

        return $readable . ' — ' . implode(', ', $parts);
    }

    // =========================================================================
    // PRIVATE — SLUG RESOLUTION WITH CACHE
    // =========================================================================

    /**
     * Resolve a module slug to its database ID.
     * Result is cached in memory for the lifetime of this request.
     */
    private function resolveModule(string $slug): ?int
    {
        if (!array_key_exists($slug, $this->slugCache['module'])) {
            $id = $this->db->fetchOne(
                'SELECT id FROM modules WHERE slug = :slug',
                ['slug' => $slug],
            );
            $this->slugCache['module'][$slug] = $id ? (int) $id : null;
        }

        return $this->slugCache['module'][$slug];
    }

    /**
     * Resolve a submodule slug + parent module ID to its database ID.
     */
    private function resolveSubmodule(string $slug, int $moduleId): ?int
    {
        $cacheKey = "{$moduleId}:{$slug}";

        if (!array_key_exists($cacheKey, $this->slugCache['submodule'])) {
            $id = $this->db->fetchOne(
                'SELECT id FROM module_submodules WHERE slug = :slug AND module_id = :module_id',
                ['slug' => $slug, 'module_id' => $moduleId],
            );
            $this->slugCache['submodule'][$cacheKey] = $id ? (int) $id : null;
        }

        return $this->slugCache['submodule'][$cacheKey];
    }

    /**
     * Resolve a feature slug + parent submodule ID to its database ID.
     */
    private function resolveFeature(string $slug, int $submoduleId): ?int
    {
        $cacheKey = "{$submoduleId}:{$slug}";

        if (!array_key_exists($cacheKey, $this->slugCache['feature'])) {
            $id = $this->db->fetchOne(
                'SELECT id FROM module_features WHERE slug = :slug AND submodule_id = :submodule_id',
                ['slug' => $slug, 'submodule_id' => $submoduleId],
            );
            $this->slugCache['feature'][$cacheKey] = $id ? (int) $id : null;
        }

        return $this->slugCache['feature'][$cacheKey];
    }
}
