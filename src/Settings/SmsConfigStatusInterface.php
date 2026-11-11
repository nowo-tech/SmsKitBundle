<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

/**
 * Port of the SMS configuration status for host health / status pages (1.5, rule 1): the active provider and the
 * required fields it misses, never a value. The default implementation is {@see SmsConfigStatus}; inject this
 * interface (aliased to it) instead of the final class. Answers per call (worker mode).
 */
interface SmsConfigStatusInterface
{
    /**
     * True when sending is off only because nothing chose a provider yet (always false without Instance Settings or
     * with a host `config_provider`).
     */
    public function isProviderUnset(): bool;

    /**
     * Active provider name (`null` = sending disabled).
     */
    public function provider(): string;

    /**
     * Names of the required fields the active provider misses; `[]` when complete or disabled.
     *
     * @return list<string>
     */
    public function missingFields(): array;

    /**
     * Whether a provider other than `null` is active with required fields missing.
     */
    public function hasMissingFields(): bool;
}
