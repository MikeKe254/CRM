<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Encryption\CredentialEncryptionService;
use App\Services\Payment\DTO\PaymentConfig;
use Doctrine\DBAL\Connection;

/**
 * Loads and decrypts payment method configurations for a company/branch.
 *
 * Sources:
 *   mpesa_configs         → methodKey 'mpesa'
 *   cash_configs          → methodKey 'cash'
 *   card_configs          → methodKey 'card'
 *   bank_transfer_configs → methodKey 'bank'
 *   pesapal_configs       → methodKey 'pesapal'
 *   other_configs         → methodKey 'other'  (singleton per company, always released)
 *
 * Every config row is always scoped to a specific branch (branch_id NOT NULL).
 */
final class PaymentConfigService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CredentialEncryptionService $encryption,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Get all active payment configs for a company/branch, ordered for display.
     * Used by checkout step 2 to show available payment methods.
     *
     * @return PaymentConfig[]
     */
    public function getActiveConfigs(int $companyId, int $branchId): array
    {
        $allowed = $this->loadAllowedMethodKeys($companyId, $branchId);
        $configs = [];

        if (in_array('mpesa', $allowed, true)) {
            foreach ($this->loadMpesaConfigs($companyId, $branchId) as $cfg) {
                $configs[] = $cfg;
            }
        }

        if (in_array('cash', $allowed, true)) {
            $cash = $this->loadCashConfig($companyId, $branchId);
            if ($cash !== null) {
                $configs[] = $cash;
            }
        }

        if (in_array('card', $allowed, true)) {
            foreach ($this->loadCardConfigs($companyId, $branchId) as $cfg) {
                $configs[] = $cfg;
            }
        }

        if (in_array('bank', $allowed, true)) {
            $bank = $this->loadBankConfig($companyId, $branchId);
            if ($bank !== null) {
                $configs[] = $bank;
            }
        }

        if (in_array('pesapal', $allowed, true)) {
            $pesapal = $this->loadPesapalConfig($companyId, $branchId);
            if ($pesapal !== null) {
                $configs[] = $pesapal;
            }
        }

        if (in_array('loyalty', $allowed, true)) {
            $loyalty = $this->loadLoyaltyConfig($companyId);
            if ($loyalty !== null) {
                $configs[] = $loyalty;
            }
        }

        // Other is appended last only if the branch has not disabled it
        if (in_array('other', $allowed, true)) {
            $configs[] = $this->loadOtherConfig();
        }

        return $configs;
    }

    /**
     * Returns method keys that are available for a branch.
     *
     * Resolution order:
     *  1. Platform-released methods (released_to_tenants = 1 on payment_methods)
     *     are available by default.
     *  2. A branch_payment_methods row with branch_enabled = 0 explicitly disables
     *     a released method for that branch.
     *  3. A branch_payment_methods row with patronr_approved = 1 AND branch_enabled = 1
     *     enables a non-released method for that branch.
     *  4. Loyalty is always checked regardless (controlled by loyalty_programs).
     *
     * @return string[]
     */
    private function loadAllowedMethodKeys(int $companyId, int $branchId): array
    {
        // All platform-released method keys
        $releasedRows = $this->db->fetchAllAssociative(
            'SELECT method_key FROM payment_methods WHERE released_to_tenants = 1',
        );
        $released = array_flip(array_column($releasedRows, 'method_key'));

        // All branch_payment_methods rows for this branch
        $branchRows = $this->db->fetchAllAssociative(
            'SELECT method_key, patronr_approved, branch_enabled
               FROM branch_payment_methods
              WHERE company_id = :cid AND branch_id = :bid',
            ['cid' => $companyId, 'bid' => $branchId],
        );

        // Apply branch overrides
        foreach ($branchRows as $row) {
            $key = $row['method_key'];
            if ((bool) $row['branch_enabled'] === false) {
                // Explicit branch disable — remove from released set
                unset($released[$key]);
            } elseif ((bool) $row['patronr_approved'] && (bool) $row['branch_enabled']) {
                // Branch-approved non-released method — add it
                $released[$key] = true;
            }
        }

        // Loyalty always checked (controlled by loyalty_programs, not this table)
        $released['loyalty'] = true;

        return array_keys($released);
    }

    /**
     * Load a single PaymentConfig by its config table id and method key.
     * Credentials are decrypted. Used during payment processing.
     */
    public function getConfig(int $configId, string $methodKey): ?PaymentConfig
    {
        if ($methodKey === 'loyalty') {
            return $this->loadLoyaltyConfig($configId); // configId = loyalty_programs.id
        }

        return match ($methodKey) {
            'mpesa'   => $this->loadMpesaConfigById($configId),
            'cash'    => $this->loadCashConfigById($configId),
            'card'    => $this->loadCardConfigById($configId),
            'bank'    => $this->loadBankConfigById($configId),
            'pesapal' => $this->loadPesapalConfigById($configId),
            'other'   => $this->loadOtherConfig(),
            default   => null,
        };
    }

    // =========================================================================
    // MPESA
    // =========================================================================

    /** @return PaymentConfig[] — one per active mpesa_config row (paybill + till can both be active) */
    private function loadMpesaConfigs(int $companyId, int $branchId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT mc.*, pm.method_key, pm.id AS pm_id
               FROM mpesa_configs mc
               JOIN payment_methods pm ON pm.method_key = \'mpesa\'
              WHERE mc.company_id = :company_id
                AND mc.is_active = 1
                AND mc.branch_id = :branch_id
              ORDER BY mc.id ASC',
            ['company_id' => $companyId, 'branch_id' => $branchId],
        );

        return array_map(fn(array $row) => $this->hydrateMpesa($row), $rows);
    }

    private function loadMpesaConfigById(int $id): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT mc.*, pm.method_key, pm.id AS pm_id
               FROM mpesa_configs mc
               JOIN payment_methods pm ON pm.method_key = \'mpesa\'
              WHERE mc.id = :id LIMIT 1',
            ['id' => $id],
        );

        return $row ? $this->hydrateMpesa($row) : null;
    }

    private function hydrateMpesa(array $row): PaymentConfig
    {
        $encrypted = (bool) $row['credentials_encrypted'];
        $mode      = (bool) $row['integration_enabled'] ? 'api' : 'manual';

        $label = match ($row['type']) {
            'till'     => $row['account_name'] ?: 'M-Pesa Till',
            'paybill'  => $row['account_name'] ?: 'M-Pesa Paybill',
            'buygoods' => $row['account_name'] ?: 'M-Pesa Buy Goods',
            default    => $row['account_name'] ?: 'M-Pesa',
        };

        $integrationModes = array_values(array_filter(
            array_map('trim', explode(',', (string) ($row['integration_mode'] ?? 'manual')))
        )) ?: ['manual'];

        return new PaymentConfig(
            configId:            (int) $row['id'],
            paymentMethodId:     (int) $row['pm_id'],
            methodKey:           'mpesa',
            label:               $label,
            mode:                $mode,
            integrationEnabled:  (bool) $row['integration_enabled'],
            mpesaType:           $row['type'],
            integrationModes:    $integrationModes,
            credentials: [
                'consumer_key'    => $this->encryption->read((string) ($row['consumer_key']    ?? ''), $encrypted),
                'consumer_secret' => $this->encryption->read((string) ($row['consumer_secret'] ?? ''), $encrypted),
                'passkey'         => $this->encryption->read((string) ($row['passkey']         ?? ''), $encrypted),
            ],
            config: [
                'shortcode'             => $row['shortcode']    ?? '',
                'till_number'           => $row['till_number']  ?? null,
                'callback_url'          => $row['callback_url'] ?? '',
                'confirmation_url'      => $row['confirmation_url'] ?? '',
                'environment'           => $row['environment']  ?? 'sandbox',
                'type'                  => $row['type']         ?? 'paybill',
                'manual_code_required'  => (bool) ($row['manual_code_required'] ?? false),
            ],
            branchId: (int) $row['branch_id'],
        );
    }

    // =========================================================================
    // CASH
    // =========================================================================

    private function loadCashConfig(int $companyId, int $branchId): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT cc.*, pm.method_key, pm.id AS pm_id
               FROM cash_configs cc
               JOIN payment_methods pm ON pm.method_key = \'cash\'
              WHERE cc.company_id = :company_id
                AND cc.is_active = 1
                AND cc.branch_id = :branch_id
              ORDER BY cc.id ASC
              LIMIT 1',
            ['company_id' => $companyId, 'branch_id' => $branchId],
        );

        return $row ? $this->hydrateCash($row) : null;
    }

    private function loadCashConfigById(int $id): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT cc.*, pm.method_key, pm.id AS pm_id
               FROM cash_configs cc
               JOIN payment_methods pm ON pm.method_key = \'cash\'
              WHERE cc.id = :id LIMIT 1',
            ['id' => $id],
        );

        return $row ? $this->hydrateCash($row) : null;
    }

    private function hydrateCash(array $row): PaymentConfig
    {
        return new PaymentConfig(
            configId:           (int) $row['id'],
            paymentMethodId:    (int) $row['pm_id'],
            methodKey:          'cash',
            label:              $row['account_name'] ?: 'Cash',
            mode:               'manual',
            integrationEnabled: false,
            mpesaType:          null,
            integrationModes:   [],
            credentials:        [],
            config: [
                'currency'           => $row['currency']           ?? 'KES',
                'requires_receipt'   => (bool) ($row['requires_receipt']   ?? false),
                'requires_approval'  => (bool) ($row['requires_approval']  ?? false),
                'approval_threshold' => $row['approval_threshold'] ?? null,
            ],
            branchId: (int) $row['branch_id'],
        );
    }

    // =========================================================================
    // CARD
    // =========================================================================

    /** @return PaymentConfig[] — one per active card_config row */
    private function loadCardConfigs(int $companyId, int $branchId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT cc.*, \'card\' AS method_key, COALESCE(pm.id, 0) AS pm_id
               FROM card_configs cc
               LEFT JOIN payment_methods pm ON pm.method_key = \'card\'
              WHERE cc.company_id = :company_id
                AND cc.is_active = 1
                AND cc.branch_id = :branch_id
              ORDER BY cc.id ASC',
            ['company_id' => $companyId, 'branch_id' => $branchId],
        );

        return array_map(fn(array $row) => $this->hydrateCard($row), $rows);
    }

    private function loadCardConfigById(int $id): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT cc.*, \'card\' AS method_key, COALESCE(pm.id, 0) AS pm_id
               FROM card_configs cc
               LEFT JOIN payment_methods pm ON pm.method_key = \'card\'
              WHERE cc.id = :id LIMIT 1',
            ['id' => $id],
        );

        return $row ? $this->hydrateCard($row) : null;
    }

    private function hydrateCard(array $row): PaymentConfig
    {
        $acceptedCards = [];
        if (!empty($row['accepted_cards'])) {
            $acceptedCards = json_decode((string) $row['accepted_cards'], true) ?? [];
        }

        $type = $row['type'] ?? 'pdq';
        $typeLabels = ['pdq' => 'PDQ Machine', 'mpos' => 'Mobile POS', 'tap' => 'Tap & Pay', 'virtual' => 'Virtual Terminal'];
        $label = $row['account_name'] ?: ($typeLabels[$type] ?? 'Card');

        return new PaymentConfig(
            configId:           (int) $row['id'],
            paymentMethodId:    (int) $row['pm_id'],
            methodKey:          'card',
            label:              $label,
            mode:               'manual',
            integrationEnabled: false,
            mpesaType:          null,
            integrationModes:   [],
            credentials:        [],
            config: [
                'type'                      => $type,
                'acquiring_bank'            => $row['acquiring_bank']            ?? '',
                'merchant_id'               => $row['merchant_id']               ?? '',
                'terminal_id'               => $row['terminal_id']               ?? '',
                'accepted_cards'            => $acceptedCards,
                'currency'                  => $row['currency']                  ?? 'KES',
                'requires_receipt'          => (bool) ($row['requires_receipt']          ?? false),
                'transaction_code_required' => (bool) ($row['transaction_code_required'] ?? false),
                'notes'                     => $row['notes']                     ?? '',
            ],
            branchId: isset($row['branch_id']) ? (int) $row['branch_id'] : null,
        );
    }

    // =========================================================================
    // BANK TRANSFER
    // =========================================================================

    private function loadBankConfig(int $companyId, int $branchId): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT btc.*, pm.method_key, pm.id AS pm_id
               FROM bank_transfer_configs btc
               JOIN payment_methods pm ON pm.method_key = \'bank\'
              WHERE btc.company_id = :company_id
                AND btc.is_active = 1
                AND btc.branch_id = :branch_id
              ORDER BY btc.id ASC
              LIMIT 1',
            ['company_id' => $companyId, 'branch_id' => $branchId],
        );

        return $row ? $this->hydrateBank($row) : null;
    }

    private function loadBankConfigById(int $id): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT btc.*, pm.method_key, pm.id AS pm_id
               FROM bank_transfer_configs btc
               JOIN payment_methods pm ON pm.method_key = \'bank\'
              WHERE btc.id = :id LIMIT 1',
            ['id' => $id],
        );

        return $row ? $this->hydrateBank($row) : null;
    }

    private function hydrateBank(array $row): PaymentConfig
    {
        return new PaymentConfig(
            configId:           (int) $row['id'],
            paymentMethodId:    (int) $row['pm_id'],
            methodKey:          'bank',
            label:              $row['account_name'] ?: 'Bank Transfer',
            mode:               'manual',
            integrationEnabled: (bool) ($row['auto_confirm'] ?? false),
            mpesaType:          null,
            integrationModes:   [],
            credentials:        [],
            config: [
                'bank_name'            => $row['bank_name']            ?? '',
                'bank_branch'          => $row['bank_branch']          ?? '',
                'account_number'       => $row['account_number']       ?? '',
                'account_holder_name'  => $row['account_holder_name']  ?? '',
                'currency'             => $row['currency']             ?? 'KES',
                'payment_instructions' => $row['payment_instructions'] ?? '',
                'reference_format'     => $row['reference_format']     ?? '',
            ],
            branchId: isset($row['branch_id']) ? (int) $row['branch_id'] : null,
        );
    }

    // =========================================================================
    // PESAPAL
    // =========================================================================

    private function loadPesapalConfig(int $companyId, int $branchId): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT pc.*, pm.method_key, pm.id AS pm_id
               FROM pesapal_configs pc
               JOIN payment_methods pm ON pm.method_key = \'pesapal\'
              WHERE pc.company_id = :company_id
                AND pc.is_active = 1
                AND pc.branch_id = :branch_id
              ORDER BY pc.id ASC
              LIMIT 1',
            ['company_id' => $companyId, 'branch_id' => $branchId],
        );

        return $row ? $this->hydratePesapal($row) : null;
    }

    private function loadPesapalConfigById(int $id): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT pc.*, pm.method_key, pm.id AS pm_id
               FROM pesapal_configs pc
               JOIN payment_methods pm ON pm.method_key = \'pesapal\'
              WHERE pc.id = :id LIMIT 1',
            ['id' => $id],
        );

        return $row ? $this->hydratePesapal($row) : null;
    }

    private function hydratePesapal(array $row): PaymentConfig
    {
        $encrypted = (bool) $row['credentials_encrypted'];
        $mode      = (bool) $row['integration_enabled'] ? 'api' : 'manual';

        return new PaymentConfig(
            configId:           (int) $row['id'],
            paymentMethodId:    (int) $row['pm_id'],
            methodKey:          'pesapal',
            label:              $row['account_name'] ?: 'Pesapal',
            mode:               $mode,
            integrationEnabled: (bool) $row['integration_enabled'],
            mpesaType:          null,
            integrationModes:   [],
            credentials: [
                'consumer_key'    => $this->encryption->read((string) ($row['consumer_key']    ?? ''), $encrypted),
                'consumer_secret' => $this->encryption->read((string) ($row['consumer_secret'] ?? ''), $encrypted),
                'api_key'         => $this->encryption->read((string) ($row['api_key']         ?? ''), $encrypted),
                'secret_key'      => $this->encryption->read((string) ($row['secret_key']      ?? ''), $encrypted),
            ],
            config: [
                'environment'       => $row['environment']       ?? 'sandbox',
                'ipn_url'           => $row['ipn_url']           ?? '',
                'callback_url'      => $row['callback_url']      ?? '',
                'cancellation_url'  => $row['cancellation_url']  ?? '',
                'notification_id'   => $row['notification_id']   ?? '',
                'accepts_cards'     => (bool) ($row['accepts_cards']  ?? true),
                'accepts_mpesa'     => (bool) ($row['accepts_mpesa']  ?? true),
            ],
            branchId: isset($row['branch_id']) ? (int) $row['branch_id'] : null,
        );
    }

    // =========================================================================
    // OTHER — synthetic method, always available, no config table
    // =========================================================================

    /**
     * "Other" is a permanent catch-all for ad-hoc payments.
     * It has no config table — a synthetic PaymentConfig is returned directly.
     * configId = 0 signals to the checkout that there is no config row.
     */
    private function loadOtherConfig(): PaymentConfig
    {
        $pmId = (int) ($this->db->fetchOne(
            'SELECT id FROM payment_methods WHERE method_key = :key LIMIT 1',
            ['key' => 'other'],
        ) ?: 0);

        return new PaymentConfig(
            configId:           0,
            paymentMethodId:    $pmId,
            methodKey:          'other',
            label:              'Other',
            mode:               'manual',
            integrationEnabled: false,
            mpesaType:          null,
            integrationModes:   [],
            credentials:        [],
            config:             [],
            branchId:           null,
        );
    }

    // =========================================================================
    // LOYALTY — synthetic method (no config table row, comes from loyalty_programs)
    // =========================================================================

    private function loadLoyaltyConfig(int $companyId): ?PaymentConfig
    {
        $row = $this->db->fetchAssociative(
            'SELECT lp.id, lp.program_name, lp.points_name, lp.points_symbol,
                    lp.redemption_enabled, lp.kes_per_point, lp.max_redemption_pct
               FROM loyalty_programs lp
              WHERE lp.company_id = :cid AND lp.is_active = 1 AND lp.redemption_enabled = 1
              LIMIT 1',
            ['cid' => $companyId],
        );

        if (!$row) {
            return null;
        }

        $pmId = (int) ($this->db->fetchOne(
            'SELECT id FROM payment_methods WHERE method_key = :key LIMIT 1',
            ['key' => 'loyalty'],
        ) ?: 0);

        return new PaymentConfig(
            configId:           (int) $row['id'],
            paymentMethodId:    $pmId,
            methodKey:          'loyalty',
            label:              (string) ($row['program_name'] ?? 'Loyalty Points'),
            mode:               'manual',
            integrationEnabled: false,
            mpesaType:          null,
            integrationModes:   [],
            credentials:        [],
            config: [
                'points_name'        => (string) ($row['points_name']       ?? 'Points'),
                'points_symbol'      => (string) ($row['points_symbol']     ?? 'pts'),
                'kes_per_point'      => (float)  ($row['kes_per_point']     ?? 1.0),
                'max_redemption_pct' => (int)    ($row['max_redemption_pct'] ?? 100),
            ],
            branchId: null,
        );
    }
}
