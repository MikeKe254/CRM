<?php

declare(strict_types=1);

namespace App\Services\Sms\Contract;

/**
 * Complete description of how a provider is configured and what it supports.
 *
 * Returned by SmsProviderInterface::describeConfiguration().
 * Used to:
 *  - render the dynamic setup form (credentialFields)
 *  - enforce sender ID policy at send time (strictSenderIdEnforcement, systemOwnedSenderIds)
 *  - show/hide the balance widget (supportsBalance)
 *  - display help notes to the tenant during setup (notes)
 */
final class SmsProviderConfigurationDefinition
{
    /**
     * @param SmsCredentialField[] $credentialFields
     */
    public function __construct(
        public readonly string  $providerKey,
        public readonly string  $displayName,
        public readonly array   $credentialFields,
        /**
         * True = transactional and promotional sender IDs must never be swapped.
         * False = advisory only; tenant's responsibility with their provider.
         */
        public readonly bool    $strictSenderIdEnforcement,
        /**
         * True = sender IDs are system-owned (e.g. Patronr) and cannot be
         * configured by the tenant.  sms_sender_ids rows are ignored.
         */
        public readonly bool    $systemOwnedSenderIds,
        public readonly bool    $supportsBalance,
        public readonly ?string $notes = null,
    ) {
    }
}
