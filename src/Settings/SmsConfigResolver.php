<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

use function in_array;
use function is_string;
use function strtolower;
use function trim;

/**
 * Builds the effective {@see SmsConfig} from the `sms` instance settings section, falling back to the bundle
 * configuration (`nowo_sms_kit.default_provider`, `sender`, `providers.*`) for every value that is not stored.
 *
 * Worker-safe: the result is memoized for the current main request only (a new main request always rebuilds it, even
 * without `kernel.reset`), cleared by `kernel.reset` (Messenger workers) and by {@see SettingsChangedEvent} for the
 * `sms` section, so a provider switch or a new token applies to the very next send.
 */
final class SmsConfigResolver implements ResetInterface
{
    /** Values that select the null provider (as the Beacon `SMS_PROVIDER` variable accepted). */
    private const DISABLED = ['', 'none', 'disabled', 'off', 'null'];

    private ?SmsConfig $config = null;

    /** @var WeakReference<Request>|null main request the memo belongs to (null outside HTTP) */
    private ?WeakReference $configRequest = null;

    /**
     * @param InstanceSettingsInterface $settings instance settings store
     * @param SmsFallbacks $fallbacks bundle configuration fallbacks
     * @param RequestStack|null $requestStack binds the memo to the main request
     */
    public function __construct(
        private readonly InstanceSettingsInterface $settings,
        private readonly SmsFallbacks $fallbacks,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Effective configuration for a send happening now.
     */
    public function current(): SmsConfig
    {
        $request = $this->requestStack?->getMainRequest();
        if ($this->config instanceof SmsConfig && $this->isMemoizedFor($request)) {
            return $this->config;
        }

        $this->configRequest = $request instanceof Request ? WeakReference::create($request) : null;

        return $this->config = $this->build();
    }

    /**
     * Normalizes a provider name: lower case, and the disabled aliases (`none`, `disabled`, `off`, empty) become `null`.
     *
     * @param string|null $provider stored or configured provider name
     */
    public static function normalizeProvider(?string $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        return in_array($provider, self::DISABLED, true) ? 'null' : $provider;
    }

    /**
     * Drops the memo when the `sms` section changes (admin form, import or code).
     *
     * @param SettingsChangedEvent $event change notification (field names only)
     */
    public function onSettingsChanged(SettingsChangedEvent $event): void
    {
        if ($event->section === SmsSettingsSection::NAME) {
            $this->reset();
        }
    }

    /**
     * Clears the memo (kernel.reset and settings changes).
     */
    public function reset(): void
    {
        // Reset every memoized property here so the worker-state audit can see it.
        $this->config        = null;
        $this->configRequest = null;
    }

    private function build(): SmsConfig
    {
        $f = $this->fallbacks;

        return new SmsConfig(
            provider: self::normalizeProvider($this->plain('provider') ?? $f->defaultProvider),
            sender: $this->plain('sender') ?? $f->sender,
            testRecipient: $this->plain('test_recipient'),
            twilioAccountSid: $this->plain('twilio_account_sid') ?? $f->twilioAccountSid,
            twilioAuthToken: $this->secret('twilio_auth_token') ?? $f->twilioAuthToken,
            twilioMessagingServiceSid: $this->plain('twilio_messaging_service_sid') ?? $f->twilioMessagingServiceSid,
            twilioApiBaseUrl: $f->twilioApiBaseUrl,
            bridgeBaseUrl: $this->plain('bridge_base_url') ?? $f->bridgeBaseUrl,
            bridgeApiMode: $this->plain('bridge_api_mode') ?? $f->bridgeApiMode,
            bridgeAccountSid: $this->plain('bridge_account_sid') ?? $f->bridgeAccountSid,
            bridgeAuthToken: $this->secret('bridge_auth_token') ?? $f->bridgeAuthToken,
            bridgeDeviceId: $this->plain('bridge_device_id') ?? $f->bridgeDeviceId,
        );
    }

    /** Stored plain value of `sms.<field>` when it is a non-empty string (defaults of the section are null). */
    private function plain(string $field): ?string
    {
        $value = $this->settings->get(SmsSettingsSection::NAME . '.' . $field);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Stored secret `sms.<field>`, or null. */
    private function secret(string $field): ?string
    {
        $value = $this->settings->getSecret(SmsSettingsSection::NAME . '.' . $field);

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private function isMemoizedFor(?Request $request): bool
    {
        if (!$request instanceof Request) {
            return !$this->configRequest instanceof WeakReference;
        }

        return $this->configRequest?->get() === $request;
    }
}
