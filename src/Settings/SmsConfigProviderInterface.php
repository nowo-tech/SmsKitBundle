<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

/**
 * Source of the effective SMS configuration (provider, sender, credentials) of a send (1.4, DDD P2).
 *
 * The default implementation is {@see SmsConfigResolver} (the `sms` Instance Settings section over the bundle
 * configuration). {@see FallbackSmsConfigProvider} reads only the bundle configuration (`%env()%` / YAML). A host that
 * keeps the provider settings in its own store (secret manager, per-tenant table) implements this interface and sets
 * `nowo_sms_kit.config_provider` to its service id. Called on every send: do not memoize across requests (worker
 * mode), or reset the memo with `kernel.reset`.
 */
interface SmsConfigProviderInterface
{
    /**
     * Effective configuration for a send happening now.
     */
    public function current(): SmsConfig;
}
