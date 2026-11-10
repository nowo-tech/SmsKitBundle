<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SmsKitBundle\Provider\SmsBridgeProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Provider\TwilioProvider;

use function trim;

/**
 * Configuration status of the active SMS provider, for the settings page notice and host health / status pages:
 * the `sms` field names that are required by the active provider but empty (stored value and fallback). Never
 * returns a value. Stateless (reads the memoized {@see SmsConfigResolver}). The fields it names are always among the
 * fields visible for the active provider in the settings form (1.3 `visible_when`).
 */
final readonly class SmsConfigStatus
{
    /**
     * @param SmsConfigProviderInterface $resolver effective configuration
     * @param SmsProviderRegistry $providers registered providers
     * @param InstanceSettingsInterface|null $settings store (detects "no provider chosen yet")
     */
    public function __construct(
        private SmsConfigProviderInterface $resolver,
        private SmsProviderRegistry $providers,
        private ?InstanceSettingsInterface $settings = null,
    ) {
    }

    /**
     * True when sending is off only because nothing chose a provider yet: the effective provider is `null` and the
     * `sms.provider` setting is not stored (typically recipe 1.2 / DB-only before the seed or the first admin save).
     * Storing "Disabled" explicitly makes it false.
     */
    public function isProviderUnset(): bool
    {
        return $this->provider() === 'null'
            && $this->settings instanceof InstanceSettingsInterface
            && !$this->settings->isOverridden(SmsSettingsSection::NAME . '.provider');
    }

    /**
     * Active provider name (`null` = sending disabled).
     */
    public function provider(): string
    {
        return $this->resolver->current()->provider;
    }

    /**
     * Field names of the `sms` section the active provider needs but that are empty; `[]` for the null provider or a
     * complete configuration. For a host-registered provider that reports itself unconfigured (or an unknown
     * provider), `['provider']`.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $config = $this->resolver->current();

        return match ($config->provider) {
            'null'               => [],
            TwilioProvider::NAME => $this->empty([
                'twilio_account_sid' => $config->twilioAccountSid,
                'twilio_auth_token'  => $config->twilioAuthToken,
                'sender'             => trim((string) $config->sender) !== '' ? $config->sender : $config->twilioMessagingServiceSid,
            ]),
            SmsBridgeProvider::NAME => $this->empty([
                'bridge_base_url'    => $config->bridgeBaseUrl,
                'bridge_auth_token'  => $config->bridgeAuthToken,
                'bridge_account_sid' => $config->bridgeApiMode === SmsConfig::BRIDGE_MODE_TWILIO ? $config->bridgeAccountSid : 'n/a',
            ]),
            default => $this->providers->get($config->provider)?->isConfigured($config) === true ? [] : ['provider'],
        };
    }

    /**
     * Whether a provider other than `null` is active with required fields missing.
     */
    public function hasMissingFields(): bool
    {
        return $this->missingFields() !== [];
    }

    /**
     * @param array<string, string|null> $values
     *
     * @return list<string>
     */
    private function empty(array $values): array
    {
        $missing = [];
        foreach ($values as $field => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
