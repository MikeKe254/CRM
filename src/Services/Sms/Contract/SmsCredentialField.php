<?php

declare(strict_types=1);

namespace App\Services\Sms\Contract;

/**
 * Describes a single credential input field for a provider's setup form.
 *
 * Returned by SmsProviderConfigurationDefinition::credentialFields.
 * The controller renders these dynamically — no hardcoded provider forms.
 */
final class SmsCredentialField
{
    public function __construct(
        /** Storage key used in the credentials JSON blob. */
        public readonly string $key,
        /** Human-readable label for the form. */
        public readonly string $label,
        /** Input type: 'text' | 'password' | 'select'. */
        public readonly string $type,
        public readonly bool $required,
        public readonly string $placeholder = '',
        public readonly string $helpText = '',
    ) {
    }
}
