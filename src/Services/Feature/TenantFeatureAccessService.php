<?php

declare(strict_types=1);

namespace App\Services\Feature;

use App\Services\Auth\DTO\AuthResult;
use App\Services\Feature\Exception\FeatureNotAvailableException;
use Doctrine\DBAL\Connection;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║           Angavu Tenant Feature Access Service                   ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Answers one question: "Does this company have feature X?"       ║
 * ║                                                                  ║
 * ║  This service is PLAN-AWARE, not permission-aware.               ║
 * ║  Use CheckPermissionService for role/permission checks.          ║
 * ║  Use UserActivityLogService to record what happened.             ║
 * ║                                                                  ║
 * ║  Resolution order (first match wins):                            ║
 * ║    1. tenant_feature_overrides — company-specific override,      ║
 * ║       wins regardless of plan (can enable OR disable a feature)  ║
 * ║    2. plan_features — feature is in the company's current plan   ║
 * ║    3. Default → not available                                    ║
 * ║                                                                  ║
 * ║  Results are cached per (company_id + feature_slug) for the      ║
 * ║  lifetime of the request — safe to call repeatedly.              ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Inject into any controller:                                     ║
 * ║                                                                  ║
 * ║    public function __construct(                                  ║
 * ║        private readonly TenantFeatureAccessService $features,    ║
 * ║    ) {}                                                          ║
 * ║                                                                  ║
 * ║  Usage:                                                          ║
 * ║    $this->features->require($session, self::FEATURE_STK_PUSH);   ║
 * ║    if ($this->features->can($session->company->id, 'mpesa')) {   ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
final class TenantFeatureAccessService
{
    // =========================================================================
    // FEATURE SLUGS  — match module_features.slug in the database
    // =========================================================================

    // ── Customer CRM ─────────────────────────────────────────────────────────
    public const FEATURE_CUSTOMER_PROFILES     = 'customer_profiles';
    public const FEATURE_CONTACT_DETAILS       = 'contact_details';
    public const FEATURE_CUSTOMER_NOTES        = 'customer_notes';
    public const FEATURE_TAGS_LABELS           = 'tags_labels';
    public const FEATURE_MERGE_DUPLICATES      = 'merge_duplicates';
    public const FEATURE_TRANSACTION_HISTORY   = 'transaction_history';
    public const FEATURE_ORDER_HISTORY         = 'order_history';
    public const FEATURE_VISIT_FREQUENCY       = 'visit_frequency';
    public const FEATURE_CUSTOMER_GROUPS       = 'customer_groups';
    public const FEATURE_CUSTOMER_FILTERS      = 'customer_filters';
    public const FEATURE_DYNAMIC_SEGMENTS      = 'dynamic_segments';

    // ── Transactions ─────────────────────────────────────────────────────────
    public const FEATURE_TRANSACTION_LOGGING   = 'transaction_logging';
    public const FEATURE_PAYMENT_METHODS       = 'payment_methods';
    public const FEATURE_PAYMENT_REFERENCES    = 'payment_references';
    public const FEATURE_ORDER_RECORDS         = 'order_records';
    public const FEATURE_ORDER_STATUS          = 'order_status';
    public const FEATURE_PAYMENT_STATUS        = 'payment_status';
    public const FEATURE_PARTIAL_PAYMENTS      = 'partial_payments';
    public const FEATURE_REFUNDS               = 'refunds';

    // ── Business Management ───────────────────────────────────────────────────
    public const FEATURE_BRANCH_MANAGEMENT     = 'branch_management';
    public const FEATURE_BRANCH_ASSIGNMENT     = 'branch_assignment';
    public const FEATURE_USER_ACCOUNTS         = 'user_accounts';
    public const FEATURE_STAFF_ROLES           = 'staff_roles';
    public const FEATURE_ROLE_BASED_ACCESS     = 'role_based_access';
    public const FEATURE_MODULE_PERMISSIONS    = 'module_level_permissions';

    // ── Analytics ────────────────────────────────────────────────────────────
    public const FEATURE_REVENUE_REPORTS       = 'revenue_reports';
    public const FEATURE_REVENUE_TRENDS        = 'revenue_trends';
    public const FEATURE_REVENUE_PER_BRANCH    = 'revenue_per_branch';
    public const FEATURE_CUSTOMER_LTV          = 'customer_lifetime_value';
    public const FEATURE_TOP_CUSTOMERS         = 'top_customers';
    public const FEATURE_RETENTION_CHURN       = 'retention_churn';
    public const FEATURE_AVG_ORDER_VALUE       = 'average_order_value';
    public const FEATURE_SPEND_PATTERNS        = 'customer_spend_patterns';
    public const FEATURE_BEST_SELLING_ITEMS    = 'best_selling_items';

    // ── Menu ─────────────────────────────────────────────────────────────────
    public const FEATURE_PRODUCT_MANAGEMENT    = 'product_management';
    public const FEATURE_PRICING               = 'pricing';
    public const FEATURE_PRODUCT_IMAGES        = 'images';
    public const FEATURE_CATEGORY_MANAGEMENT   = 'category_management';
    public const FEATURE_STOCK_STATUS          = 'stock_status';
    public const FEATURE_TIME_AVAILABILITY     = 'time_based_availability';
    public const FEATURE_PUBLIC_MENU_LINK      = 'public_menu_link';
    public const FEATURE_QR_CODE               = 'qr_code';
    public const FEATURE_MULTI_BRANCH_MENUS    = 'multi_branch_menus';

    // ── Online Orders ─────────────────────────────────────────────────────────
    public const FEATURE_CART                  = 'cart';
    public const FEATURE_PLACE_ORDER           = 'place_order';
    public const FEATURE_ORDER_STATUS_TRACKING = 'order_status_tracking';
    public const FEATURE_BRANCH_SELECTION      = 'branch_selection';

    // ── Payments ─────────────────────────────────────────────────────────────
    public const FEATURE_MPESA                 = 'mpesa';
    public const FEATURE_BANK_PAYMENTS         = 'bank_payments';
    public const FEATURE_CUSTOM_PAYMENT_METHODS = 'custom_payment_methods';
    public const FEATURE_PAYMENT_CONFIRMATION  = 'payment_confirmation';
    public const FEATURE_PAYMENT_LINKING       = 'payment_linking';

    // ── Marketing ────────────────────────────────────────────────────────────
    public const FEATURE_SMS_CAMPAIGNS         = 'sms_campaigns';
    public const FEATURE_EMAIL_CAMPAIGNS       = 'email_campaigns';
    public const FEATURE_SEGMENTATION_TARGETING = 'segmentation_targeting';
    public const FEATURE_DISCOUNTS             = 'discounts';
    public const FEATURE_COUPONS               = 'coupons';

    // ── Loyalty ──────────────────────────────────────────────────────────────
    public const FEATURE_EARN_POINTS           = 'earn_points';
    public const FEATURE_REDEEM_POINTS         = 'redeem_points';
    public const FEATURE_REWARD_SETUP          = 'reward_setup';
    public const FEATURE_LOYALTY_BALANCE       = 'loyalty_balance';

    // ── Communications ───────────────────────────────────────────────────────
    public const FEATURE_SMS_NOTIFICATIONS     = 'sms_notifications';
    public const FEATURE_EMAIL_NOTIFICATIONS   = 'email_notifications';
    public const FEATURE_PAYMENT_CONFIRMATIONS_AUTO = 'payment_confirmations_auto';
    public const FEATURE_ORDER_ALERTS          = 'order_alerts';

    // ── Inventory ────────────────────────────────────────────────────────────
    public const FEATURE_STOCK_LEVELS          = 'stock_levels';
    public const FEATURE_STOCK_UPDATES         = 'stock_updates';
    public const FEATURE_LOW_STOCK_ALERTS      = 'low_stock_alerts';

    // ── Integrations ─────────────────────────────────────────────────────────
    public const FEATURE_API_ACCESS            = 'api_access';
    public const FEATURE_API_KEYS              = 'api_keys';
    public const FEATURE_EVENT_TRIGGERS        = 'event_triggers';
    public const FEATURE_EXTERNAL_SYNC         = 'external_sync';

    // ── Settings ─────────────────────────────────────────────────────────────
    public const FEATURE_BUSINESS_SETTINGS     = 'business_settings';
    public const FEATURE_MODULE_TOGGLES        = 'module_toggles';

    // ── Security ─────────────────────────────────────────────────────────────
    public const FEATURE_AUTHENTICATION        = 'authentication';
    public const FEATURE_SESSION_CONTROL       = 'session_control';
    public const FEATURE_ACTIVITY_LOGS         = 'activity_logs';
    public const FEATURE_AUDIT_LOGS            = 'audit_logs';

    // =========================================================================

    /**
     * Per-request cache.
     * Key:   "{companyId}:{featureSlug}"
     * Value: bool — true = has access, false = no access
     */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Returns true if the company may use the given feature.
     *
     * Resolution order (first match wins):
     *   1. tenant_feature_overrides — explicit admin override, wins regardless of plan
     *   2. company_subscriptions + plan_features — feature is in the active plan
     *      (status must be 'trial' or 'active', ends_at must not have passed)
     *   3. Default → false
     *
     * @param int    $companyId   The company to check
     * @param string $featureSlug Feature slug — use FEATURE_* constants
     */
    public function can(int $companyId, string $featureSlug): bool
    {
        $cacheKey = "{$companyId}:{$featureSlug}";

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // ── 1. Check tenant_feature_overrides (explicit override wins) ────────
        $override = $this->db->fetchOne(
            'SELECT tfo.is_enabled
               FROM tenant_feature_overrides tfo
               JOIN module_features mf ON mf.id = tfo.feature_id
              WHERE tfo.company_id = :company_id
                AND mf.slug        = :slug
                AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
              LIMIT 1',
            ['company_id' => $companyId, 'slug' => $featureSlug],
        );

        if ($override !== false && $override !== null) {
            return $this->cache[$cacheKey] = (bool) $override;
        }

        // ── 2. Check plan_features via an active subscription ─────────────────
        //    Two access conditions (either grants access):
        //      a) status is trial/active and ends_at hasn't passed
        //      b) status is past_due and grace_ends_at hasn't passed yet
        //         (gives 3–5 days for the tenant to resolve a failed payment)
        $inPlan = $this->db->fetchOne(
            'SELECT 1
               FROM company_subscriptions cs
               JOIN plan_features pf   ON pf.plan_id = cs.plan_id
               JOIN module_features mf ON mf.id      = pf.feature_id
              WHERE cs.company_id = :company_id
                AND mf.slug       = :slug
                AND mf.is_active  = 1
                AND (
                      -- Normal active/trial access
                      (cs.status IN (\'trial\', \'active\')
                       AND (cs.ends_at IS NULL OR cs.ends_at > NOW()))
                      OR
                      -- Past due but within grace period
                      (cs.status = \'past_due\'
                       AND cs.grace_ends_at IS NOT NULL
                       AND cs.grace_ends_at > NOW())
                    )
              LIMIT 1',
            ['company_id' => $companyId, 'slug' => $featureSlug],
        );

        return $this->cache[$cacheKey] = (bool) $inPlan;
    }

    /**
     * Assert that the company has access to the feature.
     * Throws FeatureNotAvailableException (HTTP 403) if not.
     *
     * Typical controller usage:
     *   $this->features->require($session, self::FEATURE_MPESA);
     *
     * @throws FeatureNotAvailableException
     */
    public function require(AuthResult $session, string $featureSlug): void
    {
        if (!$this->can($session->company->id, $featureSlug)) {
            throw new FeatureNotAvailableException($featureSlug);
        }
    }

    /**
     * Returns true only if ALL listed features are available.
     *
     * @param int    $companyId
     * @param string ...$featureSlugs
     */
    public function canAll(int $companyId, string ...$featureSlugs): bool
    {
        foreach ($featureSlugs as $slug) {
            if (!$this->can($companyId, $slug)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true if at least one of the listed features is available.
     *
     * @param int    $companyId
     * @param string ...$featureSlugs
     */
    public function canAny(int $companyId, string ...$featureSlugs): bool
    {
        foreach ($featureSlugs as $slug) {
            if ($this->can($companyId, $slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return all feature slugs currently available to a company.
     * Combines plan_features with any active overrides.
     * Useful for sending a feature manifest to the frontend on login.
     *
     * @param int $companyId
     * @return string[]  e.g. ['customer_profiles', 'mpesa', 'sms_campaigns', ...]
     */
    public function all(int $companyId): array
    {
        // Plan features — join through active subscription (source of truth)
        // Includes past_due within grace period (same logic as can())
        $planSlugs = $this->db->fetchFirstColumn(
            'SELECT mf.slug
               FROM company_subscriptions cs
               JOIN plan_features pf   ON pf.plan_id = cs.plan_id
               JOIN module_features mf ON mf.id      = pf.feature_id
              WHERE cs.company_id = :company_id
                AND mf.is_active  = 1
                AND (
                      (cs.status IN (\'trial\', \'active\')
                       AND (cs.ends_at IS NULL OR cs.ends_at > NOW()))
                      OR
                      (cs.status = \'past_due\'
                       AND cs.grace_ends_at IS NOT NULL
                       AND cs.grace_ends_at > NOW())
                    )',
            ['company_id' => $companyId],
        );

        $enabled = array_flip($planSlugs);

        // Apply active overrides on top
        $overrides = $this->db->fetchAllAssociative(
            'SELECT mf.slug, tfo.is_enabled
               FROM tenant_feature_overrides tfo
               JOIN module_features mf ON mf.id = tfo.feature_id
              WHERE tfo.company_id = :company_id
                AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())',
            ['company_id' => $companyId],
        );

        foreach ($overrides as $row) {
            if ((bool) $row['is_enabled']) {
                $enabled[$row['slug']] = true;
            } else {
                unset($enabled[$row['slug']]);
            }
        }

        return array_keys($enabled);
    }

    /**
     * Clear the in-memory cache.
     * Useful after a plan change or override update within the same request.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
