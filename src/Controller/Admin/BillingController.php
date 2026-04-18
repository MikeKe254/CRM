<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/billing', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
final class BillingController extends AdminBaseController
{
    public function __construct(
        AuthService $auth,
        CheckPermissionService $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService $branchResolver,
        Connection $db,
        private readonly UserActivityLogService $activityLog,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    #[Route('', name: 'admin_billing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireBillingAccess($request);
        if ($session instanceof Response) return $session;

        $subscription = $this->db->fetchAssociative(
            'SELECT
                cs.id AS subscription_id,
                cs.status,
                cs.billing_cycle,
                cs.started_at,
                cs.ends_at,
                cs.trial_ends_at,
                cs.cancelled_at,
                cs.cancellation_reason,
                cs.renewed_at,
                cs.external_ref,
                cs.amount_paid,
                cs.notes,
                p.id AS plan_id,
                p.name AS plan_name,
                p.slug AS plan_slug,
                p.description AS plan_description,
                p.monthly_price,
                p.annual_price,
                p.currency,
                p.trial_days,
                p.grace_period_days
             FROM company_subscriptions cs
             JOIN plans p ON p.id = cs.plan_id
            WHERE cs.company_id = :company_id
              AND cs.deleted_at IS NULL
              AND p.deleted_at IS NULL
              AND ' . $this->subscriptionAccessCondition('cs') . '
            ORDER BY cs.id DESC
            LIMIT 1',
            ['company_id' => $session->company->id],
        );

        $plans = $this->db->fetchAllAssociative(
            'SELECT
                id,
                name,
                slug,
                description,
                monthly_price,
                annual_price,
                currency,
                trial_days,
                is_public,
                sort_order
             FROM plans
             WHERE deleted_at IS NULL
               AND is_active = 1
               AND is_public = 1
             ORDER BY sort_order ASC, id ASC',
        );

        $featureRows = $this->db->fetchAllAssociative(
            'SELECT
                pf.plan_id,
                m.name AS module_name,
                m.slug AS module_slug,
                ms.name AS submodule_name,
                ms.slug AS submodule_slug
             FROM plan_features pf
             JOIN plans p ON p.id = pf.plan_id
             JOIN module_features mf ON mf.id = pf.feature_id
             JOIN module_submodules ms ON ms.id = mf.submodule_id
             JOIN modules m ON m.id = ms.module_id
            WHERE p.deleted_at IS NULL
              AND p.is_active = 1
              AND p.is_public = 1
              AND pf.deleted_at IS NULL
              AND mf.deleted_at IS NULL
              AND mf.is_active = 1
              AND mf.platform_released = 1
              AND ms.deleted_at IS NULL
              AND ms.is_active = 1
              AND m.deleted_at IS NULL
              AND m.is_active = 1
              AND m.platform_released = 1
            GROUP BY pf.plan_id, m.id, m.name, m.slug, ms.id, ms.name, ms.slug
            ORDER BY p.sort_order ASC, m.sort_order ASC, ms.sort_order ASC, ms.name ASC',
        );

        $limitRows = $this->db->fetchAllAssociative(
            'SELECT
                pl.plan_id,
                pl.limit_key,
                pl.limit_value
             FROM plan_limits pl
             JOIN plans p ON p.id = pl.plan_id
            WHERE p.deleted_at IS NULL
              AND p.is_active = 1
              AND p.is_public = 1
              AND pl.deleted_at IS NULL
            ORDER BY p.sort_order ASC, pl.id ASC',
        );

        $comparisonSections = $this->buildPlanFeatureComparison($plans, $featureRows);
        $limitComparison = $this->buildPlanLimitComparison($plans, $limitRows);

        foreach ($plans as &$plan) {
            $planId = (int) $plan['id'];
            $plan['is_current'] = $subscription && (int) $subscription['plan_id'] === $planId;
            $plan['featured'] = $plan['slug'] === 'growth';
            $plan['highlights'] = $this->buildPlanHighlights($planId, $comparisonSections);
            $plan['limit_summary'] = [
                'users' => $limitComparison['by_plan'][$planId]['max_users'] ?? null,
                'branches' => $limitComparison['by_plan'][$planId]['max_branches'] ?? null,
                'sms' => $limitComparison['by_plan'][$planId]['sms_per_month'] ?? null,
            ];
        }
        unset($plan);

        $activeOverrides = $this->db->fetchAllAssociative(
            'SELECT
                tfo.id,
                tfo.is_enabled,
                tfo.reason,
                tfo.expires_at,
                mf.name AS feature_name,
                mf.slug AS feature_slug,
                ms.name AS submodule_name,
                m.name AS module_name
             FROM tenant_feature_overrides tfo
             JOIN module_features mf ON mf.id = tfo.feature_id
             JOIN module_submodules ms ON ms.id = mf.submodule_id
             JOIN modules m ON m.id = ms.module_id
            WHERE tfo.company_id = :company_id
              AND tfo.deleted_at IS NULL
              AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
              AND mf.deleted_at IS NULL
              AND ms.deleted_at IS NULL
              AND m.deleted_at IS NULL
            ORDER BY m.sort_order ASC, ms.sort_order ASC, mf.sort_order ASC, mf.name ASC',
            ['company_id' => $session->company->id],
        );

        $subscriptionHistory = $this->db->fetchAllAssociative(
            'SELECT
                cs.id,
                cs.status,
                cs.billing_cycle,
                cs.started_at,
                cs.ends_at,
                cs.trial_ends_at,
                cs.cancelled_at,
                cs.cancellation_reason,
                cs.renewed_at,
                cs.amount_paid,
                cs.notes,
                p.name AS plan_name,
                p.currency
             FROM company_subscriptions cs
             JOIN plans p ON p.id = cs.plan_id
            WHERE cs.company_id = :company_id
              AND cs.deleted_at IS NULL
              AND p.deleted_at IS NULL
            ORDER BY cs.id DESC
            LIMIT 8',
            ['company_id' => $session->company->id],
        );

        $requestHistory = $this->db->fetchAllAssociative(
            "SELECT
                ual.id,
                ual.description,
                ual.metadata,
                ual.created_at,
                ual.actor_type,
                COALESCE(u.name, pa.name, 'Unknown') AS requester_name
             FROM user_activity_logs ual
             LEFT JOIN users u
                    ON u.id = ual.user_id
                   AND ual.actor_type = 'tenant'
             LEFT JOIN platform_admins pa
                    ON pa.id = ual.user_id
                   AND ual.actor_type = 'superadmin'
            WHERE ual.company_id = :company_id
              AND ual.subject_type = :subject_type
            ORDER BY ual.id DESC
            LIMIT 8",
            [
                'company_id' => $session->company->id,
                'subject_type' => 'plan_change_request',
            ],
        );

        foreach ($requestHistory as &$row) {
            $meta = [];
            if (!empty($row['metadata'])) {
                $decoded = json_decode((string) $row['metadata'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $row['meta'] = $meta;
        }
        unset($row);

        return $this->render('admin/billing/index.html.twig', [
            'session' => $session,
            'subscription' => $subscription,
            'plans' => $plans,
            'comparison_sections' => $comparisonSections,
            'limit_rows' => $limitComparison['rows'],
            'active_overrides' => $activeOverrides,
            'subscription_history' => $subscriptionHistory,
            'subscription_insights' => $this->buildSubscriptionInsights($subscription),
            'request_history' => $requestHistory,
            'can_request_change' => $this->canRequestChange($session),
        ]);
    }

    #[Route('/request', name: 'admin_billing_request', methods: ['POST'])]
    public function requestPlanChange(Request $request): JsonResponse
    {
        $session = $this->requireBillingAccess($request, true);
        if ($session instanceof Response) {
            return new JsonResponse(['success' => false, 'message' => 'You do not have permission to do that.'], 403);
        }

        $requestType = trim((string) $request->request->get('request_type', 'plan_change'));
        $targetPlanId = (int) $request->request->get('target_plan_id', 0);
        $requestedCycle = trim((string) $request->request->get('requested_cycle', 'monthly'));
        $preferredDate = trim((string) $request->request->get('preferred_effective_date', ''));
        $urgency = trim((string) $request->request->get('urgency', 'normal'));
        $reason = trim((string) $request->request->get('reason', ''));

        if (!in_array($requestType, ['plan_change', 'pause_cancel'], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid request type.'], 422);
        }

        if ($reason === '') {
            return new JsonResponse(['success' => false, 'message' => 'Please explain what you need and why.'], 422);
        }

        $currentPlan = $this->db->fetchAssociative(
            'SELECT p.id, p.name
               FROM company_subscriptions cs
               JOIN plans p ON p.id = cs.plan_id
              WHERE cs.company_id = :company_id
                AND cs.deleted_at IS NULL
                AND p.deleted_at IS NULL
                AND ' . $this->subscriptionAccessCondition('cs') . '
              ORDER BY cs.id DESC
              LIMIT 1',
            ['company_id' => $session->company->id],
        );

        $targetPlan = null;
        if ($requestType === 'plan_change') {
            if ($targetPlanId <= 0) {
                return new JsonResponse(['success' => false, 'message' => 'Select the plan you want to move to.'], 422);
            }

            $targetPlan = $this->db->fetchAssociative(
                'SELECT id, name, slug
                   FROM plans
                  WHERE id = :id
                    AND deleted_at IS NULL
                    AND is_active = 1
                    AND is_public = 1
                  LIMIT 1',
                ['id' => $targetPlanId],
            );

            if (!$targetPlan) {
                return new JsonResponse(['success' => false, 'message' => 'Selected plan could not be found.'], 404);
            }
        }

        if (!in_array($requestedCycle, ['monthly', 'annual', 'custom'], true)) {
            $requestedCycle = 'monthly';
        }

        if (!in_array($urgency, ['normal', 'soon', 'urgent'], true)) {
            $urgency = 'normal';
        }

        $description = $requestType === 'plan_change'
            ? sprintf(
                'Requested plan change from %s to %s (%s).',
                $currentPlan['name'] ?? 'current plan',
                $targetPlan['name'] ?? 'selected plan',
                $requestedCycle
            )
            : 'Requested subscription pause or cancellation review.';

        $this->activityLog->log(
            session: $session,
            module: UserActivityLogService::MODULE_SETTINGS,
            action: UserActivityLogService::ACTION_CREATE,
            description: $description,
            permission: 'edit_settings',
            subjectType: 'plan_change_request',
            metadata: [
                'request_type' => $requestType,
                'current_plan_id' => $currentPlan['id'] ?? null,
                'current_plan_name' => $currentPlan['name'] ?? null,
                'target_plan_id' => $targetPlan['id'] ?? null,
                'target_plan_name' => $targetPlan['name'] ?? null,
                'requested_cycle' => $requestedCycle,
                'preferred_effective_date' => $preferredDate ?: null,
                'urgency' => $urgency,
                'reason' => $reason,
            ],
            request: $request,
        );

        return new JsonResponse([
            'success' => true,
            'message' => $requestType === 'plan_change'
                ? 'Plan change request recorded successfully.'
                : 'Pause or cancellation request recorded successfully.',
        ]);
    }

    private function requireBillingAccess(Request $request, bool $edit = false): \App\Services\Auth\DTO\AuthResult|Response
    {
        $permission = $edit ? 'edit_settings' : 'view_settings';
        $session = $this->requireAdmin($request, $permission);

        if ($session instanceof Response) {
            return $session;
        }

        if ($session->user->isSuperAdmin && !$this->platformCan->check($session, $permission)) {
            return $this->denyAccess(
                $request,
                $edit
                    ? 'You do not have permission to edit billing and plan settings.'
                    : 'You do not have permission to view billing and plan information.',
                403,
                $session,
            );
        }

        return $session;
    }

    private function canRequestChange(object $session): bool
    {
        if ($session->user->isSuperAdmin) {
            return $this->platformCan->check($session, 'edit_settings');
        }

        return $this->can->check($session, 'edit_settings');
    }

    private function subscriptionAccessCondition(string $alias): string
    {
        return "(
            ({$alias}.status IN ('trial', 'active') AND ({$alias}.ends_at IS NULL OR {$alias}.ends_at > NOW()))
            OR
            ({$alias}.status = 'past_due' AND " . $this->subscriptionGraceCondition($alias) . ')
        )';
    }

    private function subscriptionGraceCondition(string $alias): string
    {
        if ($this->hasColumn('company_subscriptions', 'grace_ends_at')) {
            return "{$alias}.grace_ends_at IS NOT NULL AND {$alias}.grace_ends_at > NOW()";
        }

        return '0 = 1';
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];

        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $cache[$key] = (bool) $this->db->fetchOne(
            'SELECT 1
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name
              LIMIT 1',
            [
                'table_name' => $table,
                'column_name' => $column,
            ],
        );

        return $cache[$key];
    }

    private function buildPlanFeatureComparison(array $plans, array $featureRows): array
    {
        $sections = [];

        foreach ($featureRows as $row) {
            $moduleKey = (string) $row['module_slug'];
            $submoduleKey = (string) $row['submodule_slug'];
            $planId = (int) $row['plan_id'];

            if (!isset($sections[$moduleKey])) {
                $sections[$moduleKey] = [
                    'title' => (string) $row['module_name'],
                    'rows' => [],
                ];
            }

            if (!isset($sections[$moduleKey]['rows'][$submoduleKey])) {
                $sections[$moduleKey]['rows'][$submoduleKey] = [
                    'label' => $this->formatPlanSubmoduleLabel((string) $row['submodule_name']),
                    'plans' => [],
                ];
            }

            $sections[$moduleKey]['rows'][$submoduleKey]['plans'][$planId] = true;
        }

        foreach ($sections as &$section) {
            $section['rows'] = array_values($section['rows']);
            foreach ($section['rows'] as &$row) {
                foreach ($plans as $plan) {
                    $planId = (int) $plan['id'];
                    $row['plans'][$planId] = $row['plans'][$planId] ?? false;
                }
            }
            unset($row);
        }
        unset($section);

        return array_values($sections);
    }

    private function buildPlanLimitComparison(array $plans, array $limitRows): array
    {
        $labels = [
            'max_users' => 'Users',
            'max_branches' => 'Branches',
            'max_products' => 'Services & items',
            'sms_per_month' => 'SMS per month',
            'api_calls_per_month' => 'API calls / month',
            'data_retention_days' => 'Data retention',
        ];

        $byPlan = [];
        foreach ($limitRows as $row) {
            $byPlan[(int) $row['plan_id']][(string) $row['limit_key']] = (int) $row['limit_value'];
        }

        $rows = [];
        foreach ($labels as $key => $label) {
            $values = [];
            foreach ($plans as $plan) {
                $planId = (int) $plan['id'];
                $values[$planId] = $this->formatPlanLimitValue($key, $byPlan[$planId][$key] ?? null);
            }

            $rows[] = [
                'label' => $label,
                'values' => $values,
            ];
        }

        return [
            'rows' => $rows,
            'by_plan' => $byPlan,
        ];
    }

    private function buildPlanHighlights(int $planId, array $sections): array
    {
        $highlights = [];

        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                if (($row['plans'][$planId] ?? false) === true) {
                    $highlights[] = $row['label'];
                }
            }
        }

        return array_slice($highlights, 0, 6);
    }

    private function formatPlanSubmoduleLabel(string $label): string
    {
        return match (strtolower(trim($label))) {
            'profiles' => 'Customer profiles',
            'activity' => 'Customer activity',
            'segmentation' => 'Customer segmentation',
            'records' => 'Transaction records',
            'orders' => 'Orders',
            'processing' => 'Payment processing',
            'refunds' => 'Refunds',
            'users' => 'Team access',
            'permissions' => 'Permissions',
            'branches' => 'Multi-branch operations',
            'revenue' => 'Revenue analytics',
            'products' => 'Products',
            'categories' => 'Categories',
            'availability' => 'Availability control',
            'campaigns' => 'Campaigns',
            'promotions' => 'Promotions',
            'points' => 'Points and rewards',
            'notifications' => 'Notifications',
            'automation' => 'Automation',
            'stock' => 'Stock tracking',
            'alerts' => 'Inventory alerts',
            'api' => 'API access',
            'webhooks' => 'Webhooks',
            default => $label,
        };
    }

    private function formatPlanLimitValue(string $key, ?int $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value === -1) {
            return 'Unlimited';
        }

        return match ($key) {
            'sms_per_month', 'api_calls_per_month', 'max_users', 'max_branches', 'max_products' => number_format($value),
            'data_retention_days' => $value . ' days',
            default => (string) $value,
        };
    }

    private function buildSubscriptionInsights(array|false $subscription): array
    {
        if (!$subscription) {
            return [
                'tone' => 'amber',
                'headline' => 'No active subscription found',
                'summary' => 'This company does not currently have a live subscription record driving feature entitlement.',
                'next_label' => 'Next billing date',
                'next_date' => null,
                'period_label' => 'Access window',
                'period_value' => 'Unavailable',
            ];
        }

        $status = (string) ($subscription['status'] ?? '');
        $billingCycle = (string) ($subscription['billing_cycle'] ?? '');
        $trialEndsAt = $subscription['trial_ends_at'] ?? null;
        $endsAt = $subscription['ends_at'] ?? null;
        $startedAt = $subscription['started_at'] ?? null;

        $tone = match ($status) {
            'active' => 'emerald',
            'trial' => 'indigo',
            'past_due' => 'amber',
            'cancelled', 'expired' => 'red',
            default => 'slate',
        };

        $headline = match ($status) {
            'active' => 'Subscription active',
            'trial' => 'Trial currently running',
            'past_due' => 'Payment attention needed',
            'cancelled' => 'Subscription cancelled',
            'expired' => 'Subscription expired',
            default => 'Subscription status unavailable',
        };

        $summary = match ($status) {
            'active' => 'The company is currently operating on an active paid or free plan.',
            'trial' => 'The company is currently inside a trial window before normal billing takes over.',
            'past_due' => 'The current subscription is in a past-due state and may lose access if not regularized.',
            'cancelled' => 'The current subscription was cancelled and is only retained here for history and access tracking.',
            'expired' => 'The previous subscription period ended and no current renewal is holding access open.',
            default => 'Review the subscription record below to understand the current company state.',
        };

        $nextLabel = $status === 'trial' ? 'Trial ends' : 'Next billing date';
        $nextDate = $status === 'trial' ? $trialEndsAt : $endsAt;

        $periodLabel = 'Access window';
        $periodValue = 'Open-ended';

        if ($startedAt && $endsAt) {
            $periodValue = sprintf('%s → %s', $startedAt, $endsAt);
        } elseif ($startedAt) {
            $periodValue = sprintf('Started %s', $startedAt);
        }

        if (in_array($billingCycle, ['lifetime', 'custom'], true) && !$endsAt) {
            $nextLabel = 'Renewal model';
            $nextDate = null;
        }

        return [
            'tone' => $tone,
            'headline' => $headline,
            'summary' => $summary,
            'next_label' => $nextLabel,
            'next_date' => $nextDate,
            'period_label' => $periodLabel,
            'period_value' => $periodValue,
        ];
    }
}
