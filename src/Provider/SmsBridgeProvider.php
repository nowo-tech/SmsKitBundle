<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Provider;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Http\ProviderHttpClient;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Settings\SmsConfig;

use function preg_match;
use function rtrim;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Nowo SMS Bridge (self-hosted gateway that sends through paired Android / iOS phones). Two API surfaces:
 *
 * - `twilio` (default): Twilio-compatible `POST <base>/2010-04-01/Accounts/<AC…>/Messages.json`, HTTP Basic with the
 *   Account SID and the client key; bridge extensions `DeviceId` and `ClientMessageId`.
 * - `native`: `POST <base>/api/v1/sms` with `Authorization: Bearer smscli_…` and a JSON body.
 */
final readonly class SmsBridgeProvider implements SmsProviderInterface
{
    /** Provider name. */
    public const NAME = 'sms_bridge';

    /**
     * @param ProviderHttpClient $http guarded HTTP client
     */
    public function __construct(
        private ProviderHttpClient $http,
    ) {
    }

    /**
     * `sms_bridge`.
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * Choice label key.
     */
    public function getLabel(): string
    {
        return 'settings.sms.provider_sms_bridge';
    }

    /**
     * Base URL and client key are set; the Twilio-compatible mode also needs the Account SID, the native mode a
     * `smscli_` client key.
     *
     * @param SmsConfig $config effective configuration
     */
    public function isConfigured(SmsConfig $config): bool
    {
        $token = trim((string) $config->bridgeAuthToken);
        if (trim((string) $config->bridgeBaseUrl) === '' || $token === '' || !ProviderHttpClient::isHeaderSafe($token)) {
            return false;
        }

        return $this->isNative($config)
            ? str_starts_with($token, 'smscli_')
            : preg_match(TwilioProvider::ACCOUNT_SID_PATTERN, trim((string) $config->bridgeAccountSid)) === 1;
    }

    /**
     * Enqueues the message on the bridge.
     *
     * @param SmsMessage $message normalized message
     * @param SmsConfig $config effective configuration
     *
     * @throws SmsSendException when not configured, refused or unreachable
     */
    public function send(SmsMessage $message, SmsConfig $config): SmsSendResult
    {
        if (!$this->isConfigured($config)) {
            throw new SmsSendException('SMS Bridge is not configured (base URL, client key, and the Account SID in Twilio-compatible mode).');
        }

        $deviceId = $message->deviceId ?? $config->bridgeDeviceId;

        return $this->isNative($config)
            ? $this->sendNative($message, $config, $deviceId)
            : $this->sendTwilioCompatible($message, $config, $deviceId);
    }

    /** Native API unless the mode is `twilio` (the default). */
    private function isNative(SmsConfig $config): bool
    {
        return strtolower(trim($config->bridgeApiMode)) === SmsConfig::BRIDGE_MODE_NATIVE;
    }

    private function sendTwilioCompatible(SmsMessage $message, SmsConfig $config, ?string $deviceId): SmsSendResult
    {
        $form = ['To' => $message->to, 'Body' => $message->body];
        if ($message->from !== null) {
            $form['From'] = $message->from;
        }
        if ($deviceId !== null && $deviceId !== '') {
            $form['DeviceId'] = $deviceId;
        }
        if ($message->clientMessageId !== null && $message->clientMessageId !== '') {
            $form['ClientMessageId'] = $message->clientMessageId;
        }
        if ($message->statusCallbackUrl !== null && $message->statusCallbackUrl !== '') {
            $form['StatusCallback'] = $message->statusCallbackUrl;
        }

        return $this->http->twilioMessages(
            trim((string) $config->bridgeBaseUrl),
            trim((string) $config->bridgeAccountSid),
            trim((string) $config->bridgeAuthToken),
            $form,
            self::NAME,
            'SMS Bridge',
            'error',
        );
    }

    private function sendNative(SmsMessage $message, SmsConfig $config, ?string $deviceId): SmsSendResult
    {
        $json = ['to' => $message->to, 'body' => $message->body];
        if ($message->from !== null) {
            $json['from'] = $message->from;
        }
        if ($deviceId !== null && $deviceId !== '') {
            $json['deviceId'] = $deviceId;
        }
        if ($message->clientMessageId !== null && $message->clientMessageId !== '') {
            $json['clientMessageId'] = $message->clientMessageId;
        }

        [$status, $payload] = $this->http->post(rtrim(trim((string) $config->bridgeBaseUrl), '/') . '/api/v1/sms', [
            'headers' => [
                'Authorization' => 'Bearer ' . trim((string) $config->bridgeAuthToken),
                'Accept'        => 'application/json',
            ],
            'json' => $json,
        ], 'SMS Bridge');

        $this->http->assertAccepted($status, $payload, 'SMS Bridge', ['error', 'message']);

        return new SmsSendResult(
            self::NAME,
            ProviderHttpClient::text($payload['id'] ?? null),
            ProviderHttpClient::text($payload['status'] ?? null, 'queued'),
            $payload,
        );
    }
}
