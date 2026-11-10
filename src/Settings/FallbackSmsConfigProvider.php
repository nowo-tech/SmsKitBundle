<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

/**
 * {@see SmsConfigProviderInterface} that reads only the bundle configuration (`default_provider`, `sender`,
 * `providers.*`, typically `%env(...)%` placeholders) and never the `sms` Instance Settings section (1.4). Select it
 * with `nowo_sms_kit.config_provider: Nowo\SmsKitBundle\Settings\FallbackSmsConfigProvider`. There is no test
 * recipient. Stateless (worker-safe).
 */
final readonly class FallbackSmsConfigProvider implements SmsConfigProviderInterface
{
    /**
     * @param SmsFallbacks $fallbacks bundle configuration
     */
    public function __construct(
        private SmsFallbacks $fallbacks,
    ) {
    }

    /**
     * The configured values, provider name normalized like the stored one.
     */
    public function current(): SmsConfig
    {
        $f = $this->fallbacks;

        return new SmsConfig(
            provider: SmsConfigResolver::normalizeProvider($f->defaultProvider),
            sender: $f->sender,
            twilioAccountSid: $f->twilioAccountSid,
            twilioAuthToken: $f->twilioAuthToken,
            twilioMessagingServiceSid: $f->twilioMessagingServiceSid,
            twilioApiBaseUrl: $f->twilioApiBaseUrl,
            bridgeBaseUrl: $f->bridgeBaseUrl,
            bridgeApiMode: $f->bridgeApiMode,
            bridgeAccountSid: $f->bridgeAccountSid,
            bridgeAuthToken: $f->bridgeAuthToken,
            bridgeDeviceId: $f->bridgeDeviceId,
        );
    }
}
