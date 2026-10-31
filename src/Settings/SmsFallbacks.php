<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

use SensitiveParameter;

/**
 * Bundle configuration used when the `sms` section has no stored value for a key (`nowo_sms_kit.default_provider`,
 * `nowo_sms_kit.sender` and `nowo_sms_kit.providers.*`, typically `%env(...)%` placeholders).
 */
final readonly class SmsFallbacks
{
    /**
     * @param string $defaultProvider provider name used when none is stored
     * @param string|null $sender default sender
     * @param string|null $twilioAccountSid Twilio Account SID
     * @param string|null $twilioAuthToken Twilio Auth Token
     * @param string|null $twilioMessagingServiceSid Twilio Messaging Service SID
     * @param string $twilioApiBaseUrl Twilio API base URL
     * @param string|null $bridgeBaseUrl SMS Bridge base URL
     * @param string $bridgeApiMode SMS Bridge API mode (`twilio` or `native`)
     * @param string|null $bridgeAccountSid SMS Bridge Account SID
     * @param string|null $bridgeAuthToken SMS Bridge client key
     * @param string|null $bridgeDeviceId SMS Bridge device id
     */
    public function __construct(
        public string $defaultProvider = 'null',
        public ?string $sender = null,
        public ?string $twilioAccountSid = null,
        #[SensitiveParameter]
        public ?string $twilioAuthToken = null,
        public ?string $twilioMessagingServiceSid = null,
        public string $twilioApiBaseUrl = SmsConfig::TWILIO_API_BASE_URL,
        public ?string $bridgeBaseUrl = null,
        public string $bridgeApiMode = SmsConfig::BRIDGE_MODE_TWILIO,
        public ?string $bridgeAccountSid = null,
        #[SensitiveParameter]
        public ?string $bridgeAuthToken = null,
        public ?string $bridgeDeviceId = null,
    ) {
    }

    /**
     * Debug representation with the secrets masked.
     *
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'defaultProvider'           => $this->defaultProvider,
            'sender'                    => $this->sender,
            'twilioAccountSid'          => $this->twilioAccountSid,
            'twilioAuthToken'           => $this->twilioAuthToken === null ? null : '***',
            'twilioMessagingServiceSid' => $this->twilioMessagingServiceSid,
            'twilioApiBaseUrl'          => $this->twilioApiBaseUrl,
            'bridgeBaseUrl'             => $this->bridgeBaseUrl,
            'bridgeApiMode'             => $this->bridgeApiMode,
            'bridgeAccountSid'          => $this->bridgeAccountSid,
            'bridgeAuthToken'           => $this->bridgeAuthToken === null ? null : '***',
            'bridgeDeviceId'            => $this->bridgeDeviceId,
        ];
    }
}
