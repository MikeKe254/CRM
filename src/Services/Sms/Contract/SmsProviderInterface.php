<?php

declare(strict_types=1);

namespace App\Services\Sms\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract every SMS provider adapter must implement.
 *
 * Tagged with app.sms_provider so Symfony auto-discovers adapters and
 * SmsProviderRegistry picks them up via !tagged_iterator — adding a new
 * provider means implementing this interface and nothing else.
 */
#[AutoconfigureTag('app.sms_provider')]
interface SmsProviderInterface
{
    /**
     * Unique machine-readable key, e.g. 'hostpinnacle', 'africastalking', 'patronr'.
     * Must be stable — stored in sms_configs.provider_key.
     */
    public function getProviderKey(): string;

    /**
     * Full description of this provider: credentials fields, sender ID policy,
     * balance support, and UI notes.  Drives the dynamic setup form.
     */
    public function describeConfiguration(): SmsProviderConfigurationDefinition;

    /**
     * Send a single SMS.
     *
     * - Return SmsProviderResult::failure() for provider-level rejections.
     * - Let network/HTTP exceptions propagate so Messenger retries them.
     */
    public function send(SmsOutboundRequest $request, SmsCredentials $credentials): SmsProviderResult;

    /**
     * Whether this provider exposes a balance query endpoint.
     * Only true for system-owned providers (Patronr).
     */
    public function supportsBalance(): bool;

    /**
     * Return the credit balance, or null if unsupported / unavailable.
     * Only called when supportsBalance() returns true.
     */
    public function getBalance(SmsCredentials $credentials): ?float;
}
