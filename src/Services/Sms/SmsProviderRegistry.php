<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\Sms\Contract\SmsProviderInterface;

/**
 * Indexes all tagged SMS provider adapters by their provider key.
 *
 * Wired in services.yaml via !tagged_iterator app.sms_provider.
 * Fails fast at construction if two adapters claim the same key.
 */
final class SmsProviderRegistry
{
    /** @var array<string, SmsProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<SmsProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $key = $provider->getProviderKey();

            if (isset($this->providers[$key])) {
                throw new \LogicException(
                    sprintf('Duplicate SMS provider key "%s" — two adapters cannot share the same key.', $key),
                );
            }

            $this->providers[$key] = $provider;
        }
    }

    /**
     * Resolve an adapter by provider key.
     *
     * @throws \InvalidArgumentException when no adapter is registered for the key.
     */
    public function get(string $key): SmsProviderInterface
    {
        return $this->providers[$key]
            ?? throw new \InvalidArgumentException(
                sprintf('No SMS provider adapter registered for key "%s".', $key),
            );
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * All registered adapters, indexed by provider key.
     *
     * @return array<string, SmsProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
