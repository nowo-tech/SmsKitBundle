<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

use SensitiveParameter;

/**
 * Effective SMS configuration at the time of a send: values stored in the `sms` instance settings section, else the
 * bundle configuration fallbacks (`nowo_sms_kit.providers.*`, usually environment variables).
 *
 * Holds decrypted secrets: never log, dump or serialize it ({@see self::__debugInfo()} masks them).
 */
final readonly class SmsConfig
{
    /**
     * @param string $provider active provider name
     * @param string|null $sender default sender (E.164 or alphanumeric sender id)
     * @param string|null $testRecipient recipient of the send-test action (E.164)
     * @param string|null $twilioAccountSid Twilio Account SID (`AC…`)
     * @param string|null $twilioAuthToken Twilio Auth Token (secret)
     * @param string|null $twilioMessagingServiceSid Twilio Messaging Service SID (`MG…`), used when there is no sender
     * @param string $twilioApiBaseUrl Twilio API base URL
     * @param string|null $bridgeBaseUrl SMS Bridge base URL
     * @param string $bridgeApiMode SMS Bridge API surface: `twilio` (Messages API) or `native` (`/api/v1/sms`)
     * @param string|null $bridgeAccountSid SMS Bridge Account SID (`AC…`, Twilio-compatible mode)
     * @param string|null $bridgeAuthToken SMS Bridge client key (`smscli_…`, secret)
     * @param string|null $bridgeDeviceId SMS Bridge device id, or null for the server default
     */
    public function __construct(
        public string $provider,
        public ?string $sender = null,
        public ?string $testRecipient = null,
        public ?string $twilioAccountSid = null,
        #[SensitiveParameter]
        public ?string $twilioAuthToken = null,
        public ?string $twilioMessagingServiceSid = null,
        public string $twilioApiBaseUrl = self::TWILIO_API_BASE_URL,
        public ?string $bridgeBaseUrl = null,
        public string $bridgeApiMode = self::BRIDGE_MODE_TWILIO,
        public ?string $bridgeAccountSid = null,
        #[SensitiveParameter]
        public ?string $bridgeAuthToken = null,
        public ?string $bridgeDeviceId = null,
    ) {
    }

    /** Default Twilio API base URL. */
    public const TWILIO_API_BASE_URL = 'https://api.twilio.com';

    /** SMS Bridge Twilio-compatible Messages API. */
    public const BRIDGE_MODE_TWILIO = 'twilio';

    /** SMS Bridge native client API (`POST /api/v1/sms`, Bearer client key). */
    public const BRIDGE_MODE_NATIVE = 'native';

    /**
     * Debug representation with the secrets masked (var_dump / dump / profiler).
     *
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'provider'                  => $this->provider,
            'sender'                    => $this->sender,
            'testRecipient'             => $this->testRecipient,
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
