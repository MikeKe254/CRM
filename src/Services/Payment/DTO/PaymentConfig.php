<?php

declare(strict_types=1);

namespace App\Services\Payment\DTO;

/**
 * A resolved, decrypted payment method configuration ready for use.
 * Returned by PaymentConfigService — never constructed outside the service.
 */
final class PaymentConfig
{
    public function __construct(
        /** Row id from the specific config table (mpesa_configs, cash_configs, etc.) */
        public readonly int    $configId,
        public readonly int    $paymentMethodId,

        /** Canonical method key: mpesa | cash | bank | pesapal */
        public readonly string $methodKey,

        /** Display label e.g. "M-Pesa Paybill", "M-Pesa Till", "Cash", "Bank Transfer" */
        public readonly string $label,

        /** manual = staff confirms; api = programmatic (STK push, Pesapal iframe, etc.) */
        public readonly string $mode,

        /** Whether the API integration is enabled (false = fallback to manual confirm) */
        public readonly bool   $integrationEnabled,

        /** Mpesa-specific sub-type: paybill | till | buygoods — null for non-mpesa */
        public readonly ?string $mpesaType,

        /** M-Pesa enabled integration modes: ['stk_push','callback','manual'] subsets — empty for non-mpesa */
        public readonly array $integrationModes,

        /** Decrypted API credentials — keyed by field name. Empty for cash/manual. */
        public readonly array  $credentials,

        /** Non-sensitive config: shortcode, bank name, callback URLs, etc. */
        public readonly array  $config,

        public readonly ?int   $branchId,
    ) {}

    public function isApi(): bool
    {
        return $this->mode === 'api' && $this->integrationEnabled;
    }

    public function credential(string $key, string $default = ''): string
    {
        return (string) ($this->credentials[$key] ?? $default);
    }

    public function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
