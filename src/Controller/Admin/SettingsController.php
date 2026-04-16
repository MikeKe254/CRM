<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Encryption\CredentialEncryptionService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use App\Services\Feature\TenantFeatureAccessService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/settings', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
class SettingsController extends AdminBaseController
{
    public function __construct(
        AuthService $auth,
        CheckPermissionService $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService $branchResolver,
        Connection $db,
        private readonly TenantFeatureAccessService $features,
        private readonly CredentialEncryptionService $encryption,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // REDIRECTS — /settings → /settings/company
    // =========================================================================

    #[Route('', name: 'admin_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request);
        if ($session instanceof Response) return $session;
        return $this->redirectToRoute('admin_settings_company', $this->rp($request));
    }

    // =========================================================================
    // TAB: COMPANY
    // =========================================================================

    #[Route('/company', name: 'admin_settings_company', methods: ['GET'])]
    public function company(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request);
        if ($session instanceof Response) return $session;

        $company = $this->db->fetchAssociative(
            'SELECT name, email, phone FROM companies WHERE id = ? LIMIT 1',
            [$session->company->id],
        );

        return $this->render('admin/settings/company.html.twig', [
            'session' => $session,
            'company' => $company,
        ]);
    }

    #[Route('/save/company', name: 'admin_settings_save_company', methods: ['POST'])]
    public function saveCompany(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $session;

        $name  = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        $phone = trim((string) $request->request->get('phone', ''));

        if ($name === '') {
            $this->addFlash('error', 'Company name is required.');
            return $this->redirectToRoute('admin_settings_company', $this->rp($request));
        }

        $this->db->executeStatement(
            'UPDATE companies SET name = ?, email = ?, phone = ? WHERE id = ?',
            [$name, $email ?: null, $phone ?: null, $session->company->id],
        );

        $this->addFlash('success', 'Company profile updated.');
        return $this->redirectToRoute('admin_settings_company', $this->rp($request));
    }

    // =========================================================================
    // TAB: LOCALISATION
    // =========================================================================

    #[Route('/localisation', name: 'admin_settings_localisation', methods: ['GET'])]
    public function localisation(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request);
        if ($session instanceof Response) return $session;

        $company = $this->db->fetchAssociative(
            'SELECT currency_code, timezone, date_format FROM companies WHERE id = ? LIMIT 1',
            [$session->company->id],
        );

        $currencies = $this->db->fetchAllAssociative(
            'SELECT code, name, symbol FROM currencies WHERE is_active = 1 ORDER BY
             CASE WHEN code IN (\'KES\',\'USD\',\'EUR\',\'GBP\',\'UGX\',\'TZS\',\'RWF\',\'ETB\',\'NGN\',\'ZAR\',\'GHS\') THEN 0 ELSE 1 END,
             name ASC',
        );

        $timezones = \DateTimeZone::listIdentifiers();
        $tzGrouped = [];
        foreach ($timezones as $tz) {
            $parts = explode('/', $tz, 2);
            $region = $parts[0];
            $tzGrouped[$region][] = $tz;
        }
        ksort($tzGrouped);

        return $this->render('admin/settings/localisation.html.twig', [
            'session'    => $session,
            'company'    => $company,
            'currencies' => $currencies,
            'tz_grouped' => $tzGrouped,
        ]);
    }

    #[Route('/save/localisation', name: 'admin_settings_save_localisation', methods: ['POST'])]
    public function saveLocalisation(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $session;

        $currencyCode = strtoupper(trim((string) $request->request->get('currency_code', 'KES')));
        $timezone     = trim((string) $request->request->get('timezone', 'Africa/Nairobi'));
        $dateFormat   = $request->request->get('date_format', 'DD/MM/YYYY');

        // Validate currency exists
        $validCurrency = $this->db->fetchOne(
            'SELECT code FROM currencies WHERE code = ? AND is_active = 1 LIMIT 1',
            [$currencyCode],
        );
        if (!$validCurrency) {
            $this->addFlash('error', 'Invalid currency selected.');
            return $this->redirectToRoute('admin_settings_localisation', $this->rp($request));
        }

        // Validate timezone
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            $this->addFlash('error', 'Invalid timezone selected.');
            return $this->redirectToRoute('admin_settings_localisation', $this->rp($request));
        }

        $validFormats = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'D MMM YYYY'];
        if (!in_array($dateFormat, $validFormats, true)) {
            $dateFormat = 'DD/MM/YYYY';
        }

        $this->db->executeStatement(
            'UPDATE companies SET currency_code = ?, timezone = ?, date_format = ? WHERE id = ?',
            [$currencyCode, $timezone, $dateFormat, $session->company->id],
        );

        $this->addFlash('success', 'Localisation settings saved.');
        return $this->redirectToRoute('admin_settings_localisation', $this->rp($request));
    }

    // =========================================================================
    // TAB: MODULES
    // =========================================================================

    #[Route('/modules', name: 'admin_settings_modules', methods: ['GET'])]
    public function modules(Request $request): Response
    {
        $session = $this->requireModuleOverrideAccess($request);
        if ($session instanceof Response) return $session;

        $subscription = $this->db->fetchAssociative(
            'SELECT p.name AS plan_name, cs.ends_at, cs.status
               FROM company_subscriptions cs
               JOIN plans p ON p.id = cs.plan_id
              WHERE cs.company_id = :company_id
                AND ' . $this->subscriptionAccessCondition('cs') . '
              ORDER BY cs.id DESC
              LIMIT 1',
            ['company_id' => $session->company->id],
        );

        $rows = $this->db->fetchAllAssociative(
            'SELECT
                m.id,
                m.name,
                m.slug,
                m.description,
                COUNT(DISTINCT CASE
                    WHEN mf.id IS NOT NULL
                     AND mf.deleted_at IS NULL
                     AND mf.is_active = 1
                     AND mf.platform_released = 1
                    THEN mf.id END) AS total_feature_count,
                COUNT(DISTINCT CASE
                    WHEN pf.feature_id IS NOT NULL
                    THEN mf.id END) AS plan_feature_count,
                COUNT(DISTINCT CASE
                    WHEN tfo.id IS NOT NULL
                     AND tfo.is_enabled = 0
                     AND tfo.deleted_at IS NULL
                     AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
                    THEN mf.id END) AS disabled_override_count,
                COUNT(DISTINCT CASE
                    WHEN tfo.id IS NOT NULL
                     AND tfo.is_enabled = 1
                     AND tfo.deleted_at IS NULL
                     AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
                    THEN mf.id END) AS enabled_override_count,
                GROUP_CONCAT(DISTINCT CASE
                    WHEN tfo.id IS NOT NULL
                     AND tfo.deleted_at IS NULL
                     AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
                    THEN tfo.reason END SEPARATOR \' | \') AS override_reasons,
                MIN(CASE
                    WHEN tfo.id IS NOT NULL
                     AND tfo.deleted_at IS NULL
                     AND (tfo.expires_at IS NULL OR tfo.expires_at > NOW())
                    THEN tfo.expires_at END) AS override_expires_at
             FROM modules m
             LEFT JOIN module_submodules ms
                    ON ms.module_id = m.id
                   AND ms.deleted_at IS NULL
                   AND ms.is_active = 1
             LEFT JOIN module_features mf
                    ON mf.submodule_id = ms.id
                   AND mf.deleted_at IS NULL
                   AND mf.is_active = 1
                   AND mf.platform_released = 1
             LEFT JOIN company_subscriptions cs
                    ON cs.company_id = :company_id
                   AND ' . $this->subscriptionAccessCondition('cs') . '
             LEFT JOIN plan_features pf
                    ON pf.plan_id = cs.plan_id
                   AND pf.feature_id = mf.id
                   AND pf.deleted_at IS NULL
             LEFT JOIN tenant_feature_overrides tfo
                    ON tfo.company_id = :company_id
                   AND tfo.feature_id = mf.id
                   AND tfo.deleted_at IS NULL
             WHERE m.deleted_at IS NULL
               AND m.is_active = 1
               AND m.platform_released = 1
               AND m.slug <> :settings_slug
             GROUP BY m.id, m.name, m.slug, m.description
             ORDER BY m.sort_order ASC, m.name ASC',
            [
                'company_id' => $session->company->id,
                'settings_slug' => 'settings',
            ],
        );

        $modules = array_map(function (array $row) use ($session): array {
            $planFeatureCount = (int) ($row['plan_feature_count'] ?? 0);
            $disabledOverrideCount = (int) ($row['disabled_override_count'] ?? 0);
            $enabledOverrideCount = (int) ($row['enabled_override_count'] ?? 0);
            $totalFeatureCount = (int) ($row['total_feature_count'] ?? 0);

            $state = 'unavailable';
            if ($enabledOverrideCount > 0 && $planFeatureCount === 0) {
                $state = $enabledOverrideCount >= $totalFeatureCount
                    ? 'overridden_on'
                    : 'partially_overridden_on';
            } elseif ($planFeatureCount > 0) {
                $state = $disabledOverrideCount >= $planFeatureCount
                    ? 'overridden_off'
                    : ($disabledOverrideCount > 0 ? 'partially_overridden' : 'active');
            }

            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'description' => (string) ($row['description'] ?? ''),
                'total_feature_count' => $totalFeatureCount,
                'plan_feature_count' => $planFeatureCount,
                'disabled_override_count' => $disabledOverrideCount,
                'enabled_override_count' => $enabledOverrideCount,
                'override_reason' => trim((string) ($row['override_reasons'] ?? '')) ?: null,
                'override_expires_at' => $row['override_expires_at'] ?? null,
                'state' => $state,
                'effective_enabled' => $this->moduleHasAccess($session->company->id, (string) $row['slug']),
                'has_any_access' => $this->moduleHasAccess($session->company->id, (string) $row['slug']),
            ];
        }, $rows);

        return $this->render('admin/settings/modules.html.twig', [
            'session' => $session,
            'subscription' => $subscription,
            'modules' => $modules,
        ]);
    }

    #[Route('/save/modules', name: 'admin_settings_save_modules', methods: ['POST'])]
    public function saveModules(Request $request): Response
    {
        $session = $this->requireModuleOverrideAccess($request);
        if ($session instanceof Response) return $session;

        $moduleId = (int) $request->request->get('module_id', 0);
        $action = trim((string) $request->request->get('action', ''));

        $module = $this->db->fetchAssociative(
            'SELECT id, name, slug
               FROM modules
              WHERE id = :id
                AND deleted_at IS NULL
                AND is_active = 1
                AND platform_released = 1
              LIMIT 1',
            ['id' => $moduleId],
        );
        if (!$module) {
            $this->addFlash('error', 'Module not found.');
            return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
        }

        $moduleFeatureIds = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT mf.id
               FROM module_features mf
               JOIN module_submodules ms ON ms.id = mf.submodule_id
               JOIN modules m ON m.id = ms.module_id
              WHERE m.id = :module_id
                AND m.deleted_at IS NULL
                AND m.is_active = 1
                AND m.platform_released = 1
                AND ms.deleted_at IS NULL
                AND ms.is_active = 1
                AND mf.deleted_at IS NULL
                AND mf.is_active = 1
                AND mf.platform_released = 1',
            ['module_id' => $moduleId],
        ));

        if ($moduleFeatureIds === []) {
            $this->addFlash('error', 'This module does not have any released features to override.');
            return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
        }

        $planFeatureIds = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT mf.id
               FROM company_subscriptions cs
               JOIN plan_features pf ON pf.plan_id = cs.plan_id AND pf.deleted_at IS NULL
               JOIN module_features mf ON mf.id = pf.feature_id
               JOIN module_submodules ms ON ms.id = mf.submodule_id
               JOIN modules m ON m.id = ms.module_id
              WHERE cs.company_id = :company_id
                AND ' . $this->subscriptionAccessCondition('cs') . '
                AND m.id = :module_id
                AND m.deleted_at IS NULL
                AND m.is_active = 1
                AND m.platform_released = 1
                AND ms.deleted_at IS NULL
                AND ms.is_active = 1
                AND mf.deleted_at IS NULL
                AND mf.is_active = 1
                AND mf.platform_released = 1',
            [
                'company_id' => $session->company->id,
                'module_id' => $moduleId,
            ],
        ));

        $reason = trim((string) $request->request->get('reason', ''));
        $expiresAt = trim((string) $request->request->get('expires_at', ''));

        if (in_array($action, ['disable', 'enable'], true)) {

            if ($reason === '') {
                $this->addFlash('error', 'An explanation is required before applying a module override.');
                return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
            }

            if ($expiresAt === '') {
                $this->addFlash('error', 'An override end date is required.');
                return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
            }

            try {
                $expires = new \DateTimeImmutable($expiresAt);
            } catch (\Exception) {
                $this->addFlash('error', 'Invalid override end date.');
                return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
            }

            if ($expires <= new \DateTimeImmutable('now')) {
                $this->addFlash('error', 'The override end date must be in the future.');
                return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
            }

            $targetFeatureIds = $action === 'disable' ? $planFeatureIds : $moduleFeatureIds;

            if ($targetFeatureIds === []) {
                $this->addFlash(
                    'error',
                    $action === 'disable'
                        ? 'This company plan does not include that module, so there is nothing to disable.'
                        : 'This module does not have any released features to enable.'
                );
                return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
            }

            foreach ($targetFeatureIds as $featureId) {
                $this->db->executeStatement(
                    'INSERT INTO tenant_feature_overrides
                        (company_id, feature_id, is_enabled, reason, added_by_admin_id, expires_at, created_at, deleted_at)
                     VALUES
                        (:company_id, :feature_id, :is_enabled, :reason, :admin_id, :expires_at, NOW(), NULL)
                     ON DUPLICATE KEY UPDATE
                        is_enabled = VALUES(is_enabled),
                        reason = VALUES(reason),
                        added_by_admin_id = VALUES(added_by_admin_id),
                        expires_at = VALUES(expires_at),
                        deleted_at = NULL',
                    [
                        'company_id' => $session->company->id,
                        'feature_id' => (int) $featureId,
                        'is_enabled' => $action === 'enable' ? 1 : 0,
                        'reason' => $reason,
                        'admin_id' => $session->user->id,
                        'expires_at' => $expires->format('Y-m-d H:i:s'),
                    ],
                );
            }

            $this->syncLegacyModuleFlags($session->company->id, (string) $module['slug'], $action === 'enable');
            $this->features->clearCache();
            $this->addFlash(
                'success',
                sprintf(
                    '%s has been temporarily overridden %s until %s.',
                    $module['name'],
                    $action === 'enable' ? 'on' : 'off',
                    $expires->format('d M Y H:i')
                )
            );
            return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
        }

        if ($action === 'restore') {
            $this->db->executeStatement(
                'UPDATE tenant_feature_overrides
                    SET deleted_at = NOW()
                  WHERE company_id = :company_id
                    AND feature_id IN (:feature_ids)
                    AND deleted_at IS NULL',
                [
                    'company_id' => $session->company->id,
                    'feature_ids' => $moduleFeatureIds,
                ],
                [
                    'feature_ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
                ],
            );

            $this->syncLegacyModuleFlags($session->company->id, (string) $module['slug'], true);
            $this->features->clearCache();
            $this->addFlash('success', sprintf('%s has been restored to the plan default.', $module['name']));
            return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
        }

        $this->addFlash('error', 'Unknown module override action.');
        return $this->redirectToRoute('admin_settings_modules', $this->rp($request));
    }

    // =========================================================================
    // TAB: PAYMENT
    // =========================================================================

    #[Route('/payment', name: 'admin_settings_payment', methods: ['GET'])]
    public function payment(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request);
        if ($session instanceof Response) return $session;
        $branchId = $this->activeBranchId($session);

        if (!$this->features->isEnabled(TenantFeatureAccessService::FEATURE_PAYMENTS, $session)) {
            throw $this->createAccessDeniedException('Payment settings are not available on your plan.');
        }

        $canViewCredentials =
            ($session->user->isSuperAdmin && $this->platformCan->check($session, 'view_api_credentials'))
            || $this->can->check($session, 'VIEW_API_CREDENTIALS');
        $canEditCredentials = $canViewCredentials && (
            ($session->user->isSuperAdmin && $this->platformCan->check($session, 'edit_api_credentials'))
            || $this->can->check($session, 'EDIT_API_CREDENTIALS')
        );

        // Which payment methods has the platform released to tenants?
        $releasedMethods = $this->db->fetchAllKeyValue(
              'SELECT method_key, released_to_tenants FROM payment_methods',
          );

          $mpesaConfigs = $this->db->fetchAllAssociative(
            'SELECT id, account_name, type, integration_mode, manual_code_required, environment,
                    shortcode, till_number, forward_urls, is_active, integration_enabled,
                    auto_award_loyalty,
                    callback_url, confirmation_url, credentials_encrypted,
                    consumer_key, consumer_secret, passkey,
                    (consumer_key IS NOT NULL AND consumer_key <> \'\') AS has_consumer_key,
                    (consumer_secret IS NOT NULL AND consumer_secret <> \'\') AS has_consumer_secret,
                    (passkey IS NOT NULL AND passkey <> \'\') AS has_passkey
               FROM mpesa_configs WHERE company_id = ? AND branch_id = ? AND deleted_at IS NULL ORDER BY id ASC',
              [$session->company->id, $branchId],
          );
        foreach ($mpesaConfigs as &$cfg) {
            $raw = $cfg['forward_urls'] ?? null;
            $cfg['forward_urls_decoded'] = ($raw !== null && $raw !== '')
                ? (json_decode($raw, true) ?? [])
                : [];
            $cfg['has_consumer_key']    = (bool) $cfg['has_consumer_key'];
            $cfg['has_consumer_secret'] = (bool) $cfg['has_consumer_secret'];
            $cfg['has_passkey']         = (bool) $cfg['has_passkey'];
            $cfg['integration_modes'] = array_values(array_filter(
                array_map('trim', explode(',', (string) ($cfg['integration_mode'] ?? 'manual')))
            ));

            // Decrypt and expose values to authorised viewers; strip raw data otherwise
            $encrypted = (bool) ($cfg['credentials_encrypted'] ?? false);
            if ($canViewCredentials) {
                $cfg['consumer_key_value']    = $cfg['has_consumer_key']    ? $this->encryption->read((string) $cfg['consumer_key'],    $encrypted) : '';
                $cfg['consumer_secret_value'] = $cfg['has_consumer_secret'] ? $this->encryption->read((string) $cfg['consumer_secret'], $encrypted) : '';
                $cfg['passkey_value']         = $cfg['has_passkey']         ? $this->encryption->read((string) $cfg['passkey'],         $encrypted) : '';
            } else {
                $cfg['consumer_key_value']    = '';
                $cfg['consumer_secret_value'] = '';
                $cfg['passkey_value']         = '';
            }
            // Never send raw encrypted blobs to the frontend
            unset($cfg['consumer_key'], $cfg['consumer_secret'], $cfg['passkey'], $cfg['credentials_encrypted']);
        }
        unset($cfg);

        $cashConfigs = $this->db->fetchAllAssociative(
            'SELECT id, account_name, currency, min_amount, max_amount,
                    requires_receipt, requires_approval, approval_threshold,
                    float_amount, reconciliation_email, notes, is_active
               FROM cash_configs WHERE company_id = ? AND branch_id = ? AND deleted_at IS NULL ORDER BY id ASC',
              [$session->company->id, $branchId],
          );

          $bankConfigs = $this->db->fetchAllAssociative(
            'SELECT id, account_name, bank_name, bank_branch, bank_code, bank_swift_code,
                    account_number, account_holder_name, currency, payment_instructions,
                    reference_format, reconciliation_email, auto_confirm, is_active
               FROM bank_transfer_configs WHERE company_id = ? AND branch_id = ? AND deleted_at IS NULL ORDER BY id ASC',
              [$session->company->id, $branchId],
          );

          $pesapalConfigs = $this->db->fetchAllAssociative(
            'SELECT id, environment, is_active, integration_enabled,
                    accepts_cards, accepts_mpesa, accepts_airtel, accepts_bank,
                    ipn_url, callback_url, cancellation_url, notification_id,
                    (consumer_key IS NOT NULL AND consumer_key <> \'\') AS has_consumer_key,
                    (consumer_secret IS NOT NULL AND consumer_secret <> \'\') AS has_consumer_secret
               FROM pesapal_configs WHERE company_id = ? AND branch_id = ? AND deleted_at IS NULL ORDER BY id ASC',
              [$session->company->id, $branchId],
          );
        foreach ($pesapalConfigs as &$pcfg) {
            $pcfg['has_consumer_key']    = (bool) $pcfg['has_consumer_key'];
            $pcfg['has_consumer_secret'] = (bool) $pcfg['has_consumer_secret'];
        }
        unset($pcfg);

        $cardConfigs = $this->db->fetchAllAssociative(
            'SELECT id, type, account_name, acquiring_bank, merchant_id, terminal_id,
                    accepted_cards, currency, is_active, requires_receipt, transaction_code_required, notes
               FROM card_configs WHERE company_id = ? AND branch_id = ? AND deleted_at IS NULL ORDER BY id ASC',
              [$session->company->id, $branchId],
          );
        foreach ($cardConfigs as &$ccfg) {
            $raw = $ccfg['accepted_cards'] ?? null;
            $ccfg['accepted_cards_decoded'] = ($raw !== null && $raw !== '')
                ? (json_decode($raw, true) ?? [])
                : [];
        }
        unset($ccfg);

        // Other: enabled by default unless the branch has explicitly disabled it
        $otherBranchRow = $this->db->fetchAssociative(
            'SELECT branch_enabled FROM branch_payment_methods
              WHERE company_id = ? AND branch_id = ? AND method_key = \'other\' LIMIT 1',
            [$session->company->id, $session->branch->id],
        );
        $otherEnabled = $otherBranchRow === false || (bool) $otherBranchRow['branch_enabled'];

        return $this->render('admin/settings/payment.html.twig', [
            'session'               => $session,
            'mpesa_configs'         => $mpesaConfigs,
            'cash_configs'          => $cashConfigs,
            'bank_configs'          => $bankConfigs,
            'pesapal_configs'       => $pesapalConfigs,
            'card_configs'          => $cardConfigs,
            'can_view_credentials'  => $canViewCredentials,
            'can_edit_credentials'  => $canEditCredentials,
            'released_methods'      => $releasedMethods,
            'other_enabled'         => $otherEnabled,
        ]);
    }

    #[Route('/save/payment/{configId}', name: 'admin_settings_save_payment', methods: ['POST'])]
    public function savePayment(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission to edit company settings.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $owned = $this->db->fetchOne(
            'SELECT id FROM mpesa_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$owned) {
            return $this->error('Configuration not found.', 404);
        }

        $accountName   = trim((string) $request->request->get('account_name', ''));
        $environment   = $request->request->get('environment', 'sandbox');
        $manualCodeReq = $request->request->get('manual_code_required') === '1' ? 1 : 0;
        $isActive      = $request->request->get('is_active') === '1' ? 1 : 0;
        $intEnabled    = $request->request->get('integration_enabled') === '1' ? 1 : 0;
        $autoAwardLoyalty = $request->request->get('auto_award_loyalty') === '1' ? 1 : 0;

        // Multiple modes via checkboxes (integration_modes[])
        $validModes = ['stk_push', 'callback', 'manual'];
        $submittedModes = array_values(array_filter(
            (array) $request->request->all('integration_modes'),
            fn($m) => in_array($m, $validModes, true),
        ));
        $integrationMode = !empty($submittedModes) ? implode(',', $submittedModes) : 'manual';

        $urlLines = array_filter(
            array_map('trim', explode("\n", (string) $request->request->get('forward_urls', ''))),
            fn($u) => $u !== '' && filter_var($u, FILTER_VALIDATE_URL),
        );
        $forwardUrls = !empty($urlLines) ? json_encode(array_values($urlLines)) : null;

        $set  = 'account_name = ?, integration_mode = ?, environment = ?, manual_code_required = ?,
                 is_active = ?, integration_enabled = ?, auto_award_loyalty = ?, forward_urls = ?';
        $vals = [
            $accountName ?: null,
            $integrationMode,
            in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox',
            $manualCodeReq,
            $isActive,
            $intEnabled,
            $autoAwardLoyalty,
            $forwardUrls,
        ];

        // Credentials gated by VIEW_API_CREDENTIALS + EDIT_API_CREDENTIALS (platform or tenant)
        $canEditCredentials =
            ($session->user->isSuperAdmin && $this->platformCan->check($session, 'view_api_credentials') && $this->platformCan->check($session, 'edit_api_credentials'))
            || $this->can->check($session, 'EDIT_API_CREDENTIALS');

        if ($canEditCredentials) {
            $consumerKey    = trim((string) $request->request->get('consumer_key', ''));
            $consumerSecret = trim((string) $request->request->get('consumer_secret', ''));
            $passkey        = trim((string) $request->request->get('passkey', ''));

            if ($consumerKey !== '') {
                $set .= ', consumer_key = ?, credentials_encrypted = 1';
                $vals[] = $this->encryption->encrypt($consumerKey);
            }
            if ($consumerSecret !== '') {
                $set .= ', consumer_secret = ?, credentials_encrypted = 1';
                $vals[] = $this->encryption->encrypt($consumerSecret);
            }
            if ($passkey !== '') {
                $set .= ', passkey = ?, credentials_encrypted = 1';
                $vals[] = $this->encryption->encrypt($passkey);
            }
        }

        $vals[] = $configId;
        $this->db->executeStatement("UPDATE mpesa_configs SET {$set} WHERE id = ?", $vals);

        return $this->success('M-Pesa configuration updated.');
    }

    #[Route('/create/mpesa', name: 'admin_settings_create_mpesa', methods: ['POST'])]
    public function createMpesa(Request $request): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }

        $type      = $request->request->get('type', 'paybill');
        $shortcode = trim((string) $request->request->get('shortcode', ''));
        $tillNum   = trim((string) $request->request->get('till_number', ''));
        $accName   = trim((string) $request->request->get('account_name', ''));
        $env       = $request->request->get('environment', 'sandbox');

        if ($shortcode === '') {
            return $this->error('Shortcode is required.', 422);
        }

        $branchSlug = $request->attributes->get('branch', '');
        $branchId   = $this->db->fetchOne(
            'SELECT id FROM branches WHERE slug = ? AND company_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchSlug, $session->company->id],
        );
        if (!$branchId) {
            return $this->error('Branch not found.', 404);
        }

        // Auto-generate callback URLs — all routed through the API domain
        $apiDomain       = rtrim((string) (getenv('APP_API_DOMAIN') ?: 'https://api.patronr.com'), '/');
        $subdomain       = $session->company->subdomain;
        $callbackUrl     = $apiDomain . '/' . $subdomain . '/stk/callback';
        $confirmationUrl = $apiDomain . '/' . $shortcode . '/c2b/confirmation';

        $validType = in_array($type, ['paybill', 'buygoods'], true) ? $type : 'paybill';

        $this->db->executeStatement(
            'INSERT INTO mpesa_configs
                (company_id, branch_id, payment_method_id, account_name, type,
                 integration_mode, shortcode, till_number, environment,
                 callback_url, confirmation_url,
                 is_active, integration_enabled, created_at)
             VALUES (?, ?, 1, ?, ?, \'manual\', ?, ?, ?, ?, ?, 0, 0, NOW())',
            [
                $session->company->id,
                (int) $branchId,
                $accName ?: null,
                $validType,
                $shortcode,
                ($type === 'buygoods' && $tillNum !== '') ? $tillNum : null,
                in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox',
                $callbackUrl,
                $confirmationUrl,
            ],
        );

        return $this->success('M-Pesa configuration created. Add credentials and enable when ready.');
    }

    #[Route('/create/bank', name: 'admin_settings_create_bank', methods: ['POST'])]
    public function createBank(Request $request): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }

        $accName  = trim((string) $request->request->get('account_name', ''));
        $bankName = trim((string) $request->request->get('bank_name', ''));
        $accNum   = trim((string) $request->request->get('account_number', ''));
        $currency = strtoupper(trim((string) $request->request->get('currency', 'KES')));

        if ($bankName === '' && $accName === '') {
            return $this->error('Bank name or account name is required.', 422);
        }

        $this->db->executeStatement(
            'INSERT INTO bank_transfer_configs
                  (company_id, branch_id, account_name, bank_name, account_number, currency, is_active, created_at)
               VALUES (?, ?, ?, ?, ?, ?, 0, NOW())',
              [
                  $session->company->id,
                  $branchId,
                  $accName ?: null,
                  $bankName ?: null,
                  $accNum ?: null,
                $currency,
            ],
        );

        return $this->success('Bank configuration created. Complete the details before enabling.');
    }

    #[Route('/create/pesapal', name: 'admin_settings_create_pesapal', methods: ['POST'])]
    public function createPesapal(Request $request): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }

        // Only one Pesapal config allowed per company
        $existing = $this->db->fetchOne(
            'SELECT id FROM pesapal_configs WHERE company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$session->company->id, $branchId],
        );
        if ($existing) {
            return $this->error('A Pesapal configuration already exists for this company.', 409);
        }

        $env = $request->request->get('environment', 'sandbox');

        $this->db->executeStatement(
            'INSERT INTO pesapal_configs
                  (company_id, branch_id, environment, is_active, integration_enabled,
                   accepts_cards, accepts_mpesa, accepts_airtel, accepts_bank, created_at)
               VALUES (?, ?, ?, 0, 0, 1, 1, 0, 0, NOW())',
              [
                  $session->company->id,
                  $branchId,
                  in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox',
              ],
          );

        return $this->success('Pesapal configuration created. Add credentials before enabling.');
    }

    #[Route('/create/card', name: 'admin_settings_create_card', methods: ['POST'])]
    public function createCard(Request $request): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }

        $accName  = trim((string) $request->request->get('account_name', ''));
        $bank     = trim((string) $request->request->get('acquiring_bank', ''));
        $validTypes = ['pdq', 'mpos', 'tap', 'virtual'];
        $type     = $request->request->get('type', 'pdq');
        $type     = in_array($type, $validTypes, true) ? $type : 'pdq';

        $this->db->executeStatement(
            'INSERT INTO card_configs
                  (company_id, branch_id, account_name, acquiring_bank, type, currency, is_active, created_at)
               VALUES (?, ?, ?, ?, ?, \'KES\', 0, NOW())',
              [
                  $session->company->id,
                  $branchId,
                  $accName ?: null,
                  $bank    ?: null,
                  $type,
            ],
        );

        return $this->success('Card configuration created. Complete the details before enabling.');
    }

    #[Route('/save/cash/{configId}', name: 'admin_settings_save_cash', methods: ['POST'])]
    public function saveCash(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission to edit company settings.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $owned = $this->db->fetchOne(
            'SELECT id FROM cash_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$owned) {
            return $this->error('Cash configuration not found.', 404);
        }

        $this->db->executeStatement(
            'UPDATE cash_configs SET
                account_name = ?, currency = ?, min_amount = ?, max_amount = ?,
                requires_receipt = ?, requires_approval = ?, approval_threshold = ?,
                float_amount = ?, reconciliation_email = ?, notes = ?, is_active = ?
             WHERE id = ?',
            [
                trim((string) $request->request->get('account_name', '')) ?: null,
                strtoupper(trim((string) $request->request->get('currency', 'KES'))),
                ($v = $request->request->get('min_amount', '')) !== '' ? (float) $v : null,
                ($v = $request->request->get('max_amount', '')) !== '' ? (float) $v : null,
                $request->request->get('requires_receipt') === '1' ? 1 : 0,
                $request->request->get('requires_approval') === '1' ? 1 : 0,
                ($v = $request->request->get('approval_threshold', '')) !== '' ? (float) $v : null,
                ($v = $request->request->get('float_amount', '')) !== '' ? (float) $v : null,
                trim((string) $request->request->get('reconciliation_email', '')) ?: null,
                trim((string) $request->request->get('notes', '')) ?: null,
                $request->request->get('is_active') === '1' ? 1 : 0,
                $configId,
            ],
        );

        return $this->success('Cash configuration updated.');
    }

    #[Route('/save/bank/{configId}', name: 'admin_settings_save_bank', methods: ['POST'])]
    public function saveBank(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission to edit company settings.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $owned = $this->db->fetchOne(
            'SELECT id FROM bank_transfer_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$owned) {
            return $this->error('Bank configuration not found.', 404);
        }

        $this->db->executeStatement(
            'UPDATE bank_transfer_configs SET
                account_name = ?, bank_name = ?, bank_branch = ?, bank_code = ?,
                bank_swift_code = ?, account_number = ?, account_holder_name = ?,
                currency = ?, payment_instructions = ?, reference_format = ?,
                reconciliation_email = ?, auto_confirm = ?, is_active = ?
             WHERE id = ?',
            [
                trim((string) $request->request->get('account_name', '')) ?: null,
                trim((string) $request->request->get('bank_name', '')) ?: null,
                trim((string) $request->request->get('bank_branch', '')) ?: null,
                trim((string) $request->request->get('bank_code', '')) ?: null,
                trim((string) $request->request->get('bank_swift_code', '')) ?: null,
                trim((string) $request->request->get('account_number', '')) ?: null,
                trim((string) $request->request->get('account_holder_name', '')) ?: null,
                strtoupper(trim((string) $request->request->get('currency', 'KES'))),
                trim((string) $request->request->get('payment_instructions', '')) ?: null,
                trim((string) $request->request->get('reference_format', '')) ?: null,
                trim((string) $request->request->get('reconciliation_email', '')) ?: null,
                $request->request->get('auto_confirm') === '1' ? 1 : 0,
                $request->request->get('is_active') === '1' ? 1 : 0,
                $configId,
            ],
        );

        return $this->success('Bank configuration updated.');
    }

    #[Route('/save/pesapal/{configId}', name: 'admin_settings_save_pesapal', methods: ['POST'])]
    public function savePesapal(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission to edit company settings.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $owned = $this->db->fetchOne(
            'SELECT id FROM pesapal_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$owned) {
            return $this->error('Pesapal configuration not found.', 404);
        }

        $set  = 'environment = ?, is_active = ?, integration_enabled = ?,
                 accepts_cards = ?, accepts_mpesa = ?, accepts_airtel = ?, accepts_bank = ?,
                 ipn_url = ?, callback_url = ?, cancellation_url = ?';
        $vals = [
            in_array($request->request->get('environment'), ['sandbox', 'production'], true)
                ? $request->request->get('environment') : 'sandbox',
            $request->request->get('is_active') === '1' ? 1 : 0,
            $request->request->get('integration_enabled') === '1' ? 1 : 0,
            $request->request->get('accepts_cards') === '1' ? 1 : 0,
            $request->request->get('accepts_mpesa') === '1' ? 1 : 0,
            $request->request->get('accepts_airtel') === '1' ? 1 : 0,
            $request->request->get('accepts_bank') === '1' ? 1 : 0,
            trim((string) $request->request->get('ipn_url', '')) ?: null,
            trim((string) $request->request->get('callback_url', '')) ?: null,
            trim((string) $request->request->get('cancellation_url', '')) ?: null,
        ];

        $consumerKey    = trim((string) $request->request->get('consumer_key', ''));
        $consumerSecret = trim((string) $request->request->get('consumer_secret', ''));
        if ($consumerKey !== '') { $set .= ', consumer_key = ?';    $vals[] = $consumerKey; }
        if ($consumerSecret !== '') { $set .= ', consumer_secret = ?'; $vals[] = $consumerSecret; }

        $vals[] = $configId;
        $this->db->executeStatement("UPDATE pesapal_configs SET {$set} WHERE id = ?", $vals);

        return $this->success('Pesapal configuration updated.');
    }

    #[Route('/save/card/{configId}', name: 'admin_settings_save_card', methods: ['POST'])]
    public function saveCard(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission to edit company settings.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $owned = $this->db->fetchOne(
            'SELECT id FROM card_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$owned) {
            return $this->error('Card configuration not found.', 404);
        }

        $validTypes     = ['pdq', 'mpos', 'tap', 'virtual'];
        $type           = $request->request->get('type', 'pdq');
        $type           = in_array($type, $validTypes, true) ? $type : 'pdq';

        $validCards     = ['visa', 'mastercard', 'amex', 'unionpay', 'discover'];
        $submittedCards = array_values(array_filter(
            (array) $request->request->all('accepted_cards'),
            fn($c) => in_array($c, $validCards, true),
        ));

        $this->db->executeStatement(
            'UPDATE card_configs SET
                type = ?, account_name = ?, acquiring_bank = ?, merchant_id = ?, terminal_id = ?,
                accepted_cards = ?, currency = ?, is_active = ?, requires_receipt = ?,
                transaction_code_required = ?, notes = ?
             WHERE id = ?',
            [
                $type,
                trim((string) $request->request->get('account_name', '')) ?: null,
                trim((string) $request->request->get('acquiring_bank', '')) ?: null,
                trim((string) $request->request->get('merchant_id', '')) ?: null,
                trim((string) $request->request->get('terminal_id', '')) ?: null,
                !empty($submittedCards) ? json_encode($submittedCards) : null,
                strtoupper(trim((string) $request->request->get('currency', 'KES'))),
                $request->request->get('is_active') === '1' ? 1 : 0,
                $request->request->get('requires_receipt') === '1' ? 1 : 0,
                $request->request->get('transaction_code_required') === '1' ? 1 : 0,
                trim((string) $request->request->get('notes', '')) ?: null,
                $configId,
            ],
        );

        return $this->success('Card configuration updated.');
    }

    // =========================================================================
    // TAB: LOYALTY
    // =========================================================================

    #[Route('/loyalty', name: 'admin_settings_loyalty', methods: ['GET'])]
    public function loyalty(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request);
        if ($session instanceof Response) return $session;
        $branchId = $this->activeBranchId($session);

        $company = [
            'loyalty_module_enabled' => $this->moduleHasAccess($session->company->id, 'loyalty'),
        ];

        $loyaltyProgram = $this->db->fetchAssociative(
            'SELECT * FROM loyalty_programs WHERE company_id = ? AND branch_id = ? ORDER BY id ASC LIMIT 1',
            [$session->company->id, $branchId],
        ) ?: null;

        return $this->render('admin/settings/loyalty.html.twig', [
            'session'         => $session,
            'company'         => $company,
            'loyalty_program' => $loyaltyProgram,
        ]);
    }

    #[Route('/save/loyalty', name: 'admin_settings_save_loyalty', methods: ['POST'])]
    public function saveLoyalty(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $session;

        $companyId        = $session->company->id;
        $branchId         = $this->activeBranchId($session);
        $programName      = trim((string) $request->request->get('program_name', '')) ?: 'Loyalty Program';
        $pointsName       = trim((string) $request->request->get('points_name', '')) ?: 'Points';
        $pointsSymbol     = trim((string) $request->request->get('points_symbol', '')) ?: null;
        $pointsPerUnit    = max(1, (int) $request->request->get('points_per_unit', 1));
        $unitAmount       = max(1.0, (float) $request->request->get('unit_amount', 100));
        $enrollBonus      = max(0, (int) $request->request->get('enroll_bonus_points', 0));
        $isActive         = $request->request->get('is_active') === '1' ? 1 : 0;
        $redemptionEnabled = $request->request->get('redemption_enabled') === '1' ? 1 : 0;
        $autoAwardEnabled = $request->request->get('auto_award_enabled') === '1' ? 1 : 0;
        $autoEnrollOnPayment = $request->request->get('auto_enroll_on_payment') === '1' ? 1 : 0;
        $kesPerPoint      = max(0.01, (float) $request->request->get('kes_per_point', 1.0));
        $maxRedemptionPct = min(100, max(1, (int) $request->request->get('max_redemption_pct', 100)));

        $existing = $this->db->fetchOne(
            'SELECT id FROM loyalty_programs WHERE company_id = ? AND branch_id = ? LIMIT 1',
            [$companyId, $branchId],
        );

        if ($existing) {
            $this->db->executeStatement(
                'UPDATE loyalty_programs SET
                    program_name = ?, points_name = ?, points_symbol = ?,
                    points_per_unit = ?, unit_amount = ?, enroll_bonus_points = ?,
                    is_active = ?, redemption_enabled = ?, auto_award_enabled = ?,
                    auto_enroll_on_payment = ?, kes_per_point = ?,
                    max_redemption_pct = ?
                 WHERE company_id = ? AND branch_id = ?',
                [
                    $programName, $pointsName, $pointsSymbol,
                    $pointsPerUnit, $unitAmount, $enrollBonus,
                    $isActive, $redemptionEnabled, $autoAwardEnabled,
                    $autoEnrollOnPayment, $kesPerPoint,
                    $maxRedemptionPct, $companyId, $branchId,
                ],
            );
        } else {
            $this->db->executeStatement(
                'INSERT INTO loyalty_programs
                    (company_id, branch_id, program_name, points_name, points_symbol,
                     points_per_unit, unit_amount, enroll_bonus_points, is_active,
                     redemption_enabled, auto_award_enabled, auto_enroll_on_payment,
                     kes_per_point, max_redemption_pct)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $companyId, $branchId, $programName, $pointsName, $pointsSymbol,
                    $pointsPerUnit, $unitAmount, $enrollBonus,
                    $isActive, $redemptionEnabled, $autoAwardEnabled,
                    $autoEnrollOnPayment, $kesPerPoint, $maxRedemptionPct,
                ],
            );
        }

        $this->addFlash('success', 'Loyalty programme settings saved.');
        return $this->redirectToRoute('admin_settings_loyalty', $this->rp($request));
    }

    // =========================================================================
    // TAB: TERMINAL
    // =========================================================================

    #[Route('/terminal', name: 'admin_settings_terminal', methods: ['GET'])]
    public function terminal(Request $request): Response
    {
        $session  = $this->requireSettingsAccess($request);
        if ($session instanceof Response) return $session;

        $branchId = $this->activeBranchId($session);

        $settings = $this->db->fetchAssociative(
            'SELECT show_mpesa_feed, mpesa_feed_refresh_seconds, mpesa_feed_max_hours,
                    mpesa_feed_max_visible, show_quick_stk, enable_pos_pricing, show_events_at_terminal
               FROM pos_terminal_settings
              WHERE company_id = :company_id
                AND branch_id  = :branch_id
              LIMIT 1',
            ['company_id' => $session->company->id, 'branch_id' => $branchId],
        ) ?: [
            'show_mpesa_feed'            => 0,
            'mpesa_feed_refresh_seconds' => 5,
            'mpesa_feed_max_hours'       => 24,
            'mpesa_feed_max_visible'     => 50,
            'show_quick_stk'             => 0,
            'enable_pos_pricing'         => 0,
            'show_events_at_terminal'    => 1,
        ];

        return $this->render('admin/settings/terminal.html.twig', [
            'session'  => $session,
            'settings' => $settings,
        ]);
    }

    #[Route('/save/terminal', name: 'admin_settings_save_terminal', methods: ['POST'])]
    public function saveTerminal(Request $request): Response
    {
        $session  = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $session;

        $branchId = $this->activeBranchId($session);

        $showMpesaFeed         = (int) (bool) $request->request->get('show_mpesa_feed');
        $refreshSeconds        = in_array((int) $request->request->get('mpesa_feed_refresh_seconds'), [5, 10], true)
            ? (int) $request->request->get('mpesa_feed_refresh_seconds')
            : 5;
        $maxHours              = max(1, min(168, (int) $request->request->get('mpesa_feed_max_hours', 24)));
        $maxVisible            = max(1, min(200, (int) $request->request->get('mpesa_feed_max_visible', 50)));
        $showQuickStk          = (int) (bool) $request->request->get('show_quick_stk');
        $enablePosPricing      = (int) (bool) $request->request->get('enable_pos_pricing');
        $showEventsAtTerminal  = (int) (bool) $request->request->get('show_events_at_terminal');

        $existing = $this->db->fetchOne(
            'SELECT id FROM pos_terminal_settings WHERE company_id = :company_id AND branch_id = :branch_id LIMIT 1',
            ['company_id' => $session->company->id, 'branch_id' => $branchId],
        );

        if ($existing) {
            $this->db->executeStatement(
                'UPDATE pos_terminal_settings
                    SET show_mpesa_feed            = ?,
                        mpesa_feed_refresh_seconds = ?,
                        mpesa_feed_max_hours       = ?,
                        mpesa_feed_max_visible     = ?,
                        show_quick_stk             = ?,
                        enable_pos_pricing         = ?,
                        show_events_at_terminal    = ?
                  WHERE company_id = ? AND branch_id = ?',
                [$showMpesaFeed, $refreshSeconds, $maxHours, $maxVisible, $showQuickStk,
                 $enablePosPricing, $showEventsAtTerminal, $session->company->id, $branchId],
            );
        } else {
            $this->db->executeStatement(
                'INSERT INTO pos_terminal_settings
                    (company_id, branch_id, show_mpesa_feed, mpesa_feed_refresh_seconds,
                     mpesa_feed_max_hours, mpesa_feed_max_visible, show_quick_stk,
                     enable_pos_pricing, show_events_at_terminal)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$session->company->id, $branchId, $showMpesaFeed, $refreshSeconds,
                 $maxHours, $maxVisible, $showQuickStk, $enablePosPricing, $showEventsAtTerminal],
            );
        }

        $this->addFlash('success', 'Terminal settings saved.');
        return $this->redirectToRoute('admin_settings_terminal', $this->rp($request));
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function rp(Request $request): array
    {
        return [
            'subdomain' => (string) $request->attributes->get('subdomain', ''),
            'domain'    => (string) $request->attributes->get('domain', ''),
            'branch'    => (string) $request->attributes->get('branch', ''),
        ];
    }

    private function requireSettingsAccess(Request $request, bool $edit = false): \App\Services\Auth\DTO\AuthResult|Response
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
                    ? 'You do not have permission to edit company settings.'
                    : 'You do not have permission to view company settings.',
                403,
                $session,
            );
        }

        return $session;
    }

    private function requireModuleOverrideAccess(Request $request): \App\Services\Auth\DTO\AuthResult|Response
    {
        $session = $this->requireAdmin($request);

        if ($session instanceof Response) {
            return $session;
        }

        if (!$session->user->isSuperAdmin) {
            return $this->denyAccess($request, 'Module overrides are managed by platform admins only.', 403, $session);
        }

        if (!$this->platformCan->check($session, 'manage_tenant_overrides')) {
            return $this->denyAccess($request, 'You do not have permission to manage tenant feature overrides.', 403, $session);
        }

        return $session;
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

    private function moduleHasAccess(int $companyId, string $moduleSlug): bool
    {
        $features = $this->db->fetchFirstColumn(
            'SELECT mf.slug
               FROM module_features mf
               JOIN module_submodules ms ON ms.id = mf.submodule_id
               JOIN modules m ON m.id = ms.module_id
              WHERE m.slug = :module_slug
                AND m.deleted_at IS NULL
                AND m.is_active = 1
                AND m.platform_released = 1
                AND ms.deleted_at IS NULL
                AND ms.is_active = 1
                AND mf.deleted_at IS NULL
                AND mf.is_active = 1
                AND mf.platform_released = 1',
            ['module_slug' => $moduleSlug],
        );

        foreach ($features as $featureSlug) {
            if ($this->features->can($companyId, (string) $featureSlug)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // DELETE — Payment configs (soft delete)
    // =========================================================================

    #[Route('/payment/{configId}/delete', name: 'admin_settings_delete_payment', methods: ['POST'])]
    public function deletePaymentConfig(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $row = $this->db->fetchAssociative(
            'SELECT id, shortcode FROM mpesa_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$row) {
            return $this->error('Configuration not found.', 404);
        }

        // Mangle shortcode so the real one can be re-registered later
        $mangledShortcode = '_deleted_' . $configId . '_' . $row['shortcode'];
        $this->db->executeStatement(
            'UPDATE mpesa_configs SET deleted_at = NOW(), shortcode = ? WHERE id = ?',
            [$mangledShortcode, $configId],
        );

        return $this->success('M-Pesa configuration deleted.');
    }

    #[Route('/bank/{configId}/delete', name: 'admin_settings_delete_bank', methods: ['POST'])]
    public function deleteBank(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $owned = $this->db->fetchOne(
            'SELECT id FROM bank_transfer_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$owned) {
            return $this->error('Bank account not found.', 404);
        }

        $this->db->executeStatement(
            'UPDATE bank_transfer_configs SET deleted_at = NOW() WHERE id = ?',
            [$configId],
        );

        return $this->success('Bank account deleted.');
    }

    #[Route('/pesapal/{configId}/delete', name: 'admin_settings_delete_pesapal', methods: ['POST'])]
    public function deletePesapal(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $owned = $this->db->fetchOne(
            'SELECT id FROM pesapal_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$owned) {
            return $this->error('Pesapal configuration not found.', 404);
        }

        $this->db->executeStatement(
            'UPDATE pesapal_configs SET deleted_at = NOW() WHERE id = ?',
            [$configId],
        );

        return $this->success('Pesapal configuration deleted.');
    }

    #[Route('/card/{configId}/delete', name: 'admin_settings_delete_card', methods: ['POST'])]
    public function deleteCard(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }
        $branchId = $this->activeBranchId($session);

        $owned = $this->db->fetchOne(
            'SELECT id FROM card_configs WHERE id = ? AND company_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $session->company->id, $branchId],
        );
        if (!$owned) {
            return $this->error('Card configuration not found.', 404);
        }

        $this->db->executeStatement(
            'UPDATE card_configs SET deleted_at = NOW() WHERE id = ?',
            [$configId],
        );

        return $this->success('Card configuration deleted.');
    }

    #[Route('/toggle-other', name: 'admin_settings_toggle_other', methods: ['POST'])]
    public function toggleOther(Request $request): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) {
            return $this->error(
                $session->getStatusCode() === 403 ? 'You do not have permission.' : 'Unauthenticated.',
                $session->getStatusCode(),
            );
        }

        $enabled   = $request->request->get('enabled') === '1';
        $companyId = $session->company->id;
        $branchId  = $session->branch->id;

        $existing = $this->db->fetchOne(
            'SELECT id FROM branch_payment_methods
              WHERE company_id = ? AND branch_id = ? AND method_key = \'other\' LIMIT 1',
            [$companyId, $branchId],
        );

        if ($existing) {
            $this->db->executeStatement(
                'UPDATE branch_payment_methods SET branch_enabled = ?, updated_at = NOW()
                  WHERE company_id = ? AND branch_id = ? AND method_key = \'other\'',
                [$enabled ? 1 : 0, $companyId, $branchId],
            );
        } else {
            $this->db->executeStatement(
                'INSERT INTO branch_payment_methods
                    (company_id, branch_id, method_key, patronr_approved, branch_enabled)
                 VALUES (?, ?, \'other\', 1, ?)',
                [$companyId, $branchId, $enabled ? 1 : 0],
            );
        }

        return $this->success($enabled ? 'Other payments enabled for this branch.' : 'Other payments disabled for this branch.');
    }

    private function syncLegacyModuleFlags(int $companyId, string $moduleSlug, bool $enabled): void
    {
        if ($moduleSlug !== 'loyalty') {
            return;
        }

        $this->db->executeStatement(
            'UPDATE companies SET loyalty_module_enabled = :enabled WHERE id = :company_id',
            [
                'enabled' => $enabled ? 1 : 0,
                'company_id' => $companyId,
            ],
        );
    }

    private function activeBranchId(\App\Services\Auth\DTO\AuthResult $session): int
    {
        return (int) ($session->branch?->id ?? 30);
    }
}
