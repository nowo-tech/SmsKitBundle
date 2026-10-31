<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Provider;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Http\ProviderHttpClient;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Settings\SmsConfig;

use function preg_match;
use function trim;

/**
 * Twilio Programmable Messaging (https://www.twilio.com/docs/messaging/api/message-resource).
 *
 * Needs the Account SID (`AC` + 32 hex), the Auth Token and a sender (E.164 number or alphanumeric id) or a Messaging
 * Service SID (`MG` + 32 hex).
 */
final readonly class TwilioProvider implements SmsProviderInterface
{
    /** Provider name. */
    public const NAME = 'twilio';

    /** Account SID pattern. */
    public const ACCOUNT_SID_PATTERN = '/^AC[0-9a-fA-F]{32}$/D';

    /** Messaging Service SID pattern. */
    public const MESSAGING_SERVICE_SID_PATTERN = '/^MG[0-9a-fA-F]{32}$/D';

    /**
     * @param ProviderHttpClient $http guarded HTTP client
     */
    public function __construct(
        private ProviderHttpClient $http,
    ) {
    }

    /**
     * `twilio`.
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
        return 'settings.sms.provider_twilio';
    }

    /**
     * Account SID, Auth Token and a sender or a Messaging Service SID are set.
     *
     * @param SmsConfig $config effective configuration
     */
    public function isConfigured(SmsConfig $config): bool
    {
        return preg_match(self::ACCOUNT_SID_PATTERN, trim((string) $config->twilioAccountSid)) === 1
            && trim((string) $config->twilioAuthToken) !== ''
            && ProviderHttpClient::isHeaderSafe((string) $config->twilioAuthToken)
            && ($config->sender !== null || $this->hasMessagingService($config));
    }

    /**
     * Creates a Message resource.
     *
     * @param SmsMessage $message normalized message
     * @param SmsConfig $config effective configuration
     *
     * @throws SmsSendException when not configured, refused or unreachable
     */
    public function send(SmsMessage $message, SmsConfig $config): SmsSendResult
    {
        $accountSid = trim((string) $config->twilioAccountSid);
        $authToken  = trim((string) $config->twilioAuthToken);
        if (preg_match(self::ACCOUNT_SID_PATTERN, $accountSid) !== 1 || $authToken === '' || !ProviderHttpClient::isHeaderSafe($authToken)) {
            throw new SmsSendException('Twilio is not configured (Account SID and Auth Token).');
        }
        if ($message->from === null && !$this->hasMessagingService($config)) {
            throw new SmsSendException('Twilio needs a sender (E.164 number or alphanumeric id) or a Messaging Service SID.');
        }

        $form = ['To' => $message->to, 'Body' => $message->body];
        if ($message->from !== null) {
            $form['From'] = $message->from;
        } else {
            $form['MessagingServiceSid'] = trim((string) $config->twilioMessagingServiceSid);
        }
        if ($message->statusCallbackUrl !== null && $message->statusCallbackUrl !== '') {
            $form['StatusCallback'] = $message->statusCallbackUrl;
        }

        return $this->http->twilioMessages(
            $config->twilioApiBaseUrl,
            $accountSid,
            $authToken,
            $form,
            self::NAME,
            'Twilio',
            'error_message',
        );
    }

    private function hasMessagingService(SmsConfig $config): bool
    {
        return preg_match(self::MESSAGING_SERVICE_SID_PATTERN, trim((string) $config->twilioMessagingServiceSid)) === 1;
    }
}
