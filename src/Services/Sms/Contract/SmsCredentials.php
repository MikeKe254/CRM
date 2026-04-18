<?php

declare(strict_types=1);

namespace App\Services\Sms\Contract;

/**
 * Decrypted credential map for a single SMS provider config.
 *
 * Constructed by SmsService after decrypting credentials_json from sms_configs.
 * Each provider adapter calls require() / get() to pull its own keys — no other
 * code ever reads the underlying map.
 */
final class SmsCredentials
{
    public function __construct(
        private readonly array $data,
    ) {
    }

    /**
     * Return a credential value, or null if absent / empty.
     */
    public function get(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return ($value !== null && (string) $value !== '') ? (string) $value : null;
    }

    /**
     * Return a credential value, throwing if absent or empty.
     * Use for fields the adapter cannot function without.
     */
    public function require(string $key): string
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new \RuntimeException(
                sprintf('Required SMS credential key "%s" is missing or empty.', $key),
            );
        }

        return $value;
    }
}
