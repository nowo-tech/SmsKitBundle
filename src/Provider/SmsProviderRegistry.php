<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Provider;

use InvalidArgumentException;

use function array_key_exists;
use function preg_match;
use function sprintf;

/**
 * The registered providers by name (tagged {@see SmsProviderInterface} services). Immutable after construction.
 */
final class SmsProviderRegistry
{
    /** @var array<string, SmsProviderInterface> */
    private readonly array $providers;

    /**
     * @param iterable<SmsProviderInterface> $providers tagged providers
     *
     * @throws InvalidArgumentException on an invalid or duplicate provider name
     */
    public function __construct(iterable $providers)
    {
        $byName = [];
        foreach ($providers as $provider) {
            $name = $provider->getName();
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf('SMS provider name "%s" must be lower snake case (^[a-z][a-z0-9_]{0,31}$).', $name));
            }
            if (array_key_exists($name, $byName)) {
                throw new InvalidArgumentException(sprintf('Two SMS providers are named "%s".', $name));
            }
            $byName[$name] = $provider;
        }
        $this->providers = $byName;
    }

    /**
     * Whether a provider is registered under that name.
     *
     * @param string $name provider name
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->providers);
    }

    /**
     * Provider by name, or null.
     *
     * @param string $name provider name
     */
    public function get(string $name): ?SmsProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Every provider, by name, in registration order.
     *
     * @return array<string, SmsProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
