<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Sender;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Log\Redactor;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Phone\SenderId;
use Nowo\SmsKitBundle\Policy\DestinationPolicy;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderInterface;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Settings\SmsConfig;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * Default {@see SmsSenderInterface}: resolves the effective configuration and the provider on every call, validates
 * the message (E.164 destination, destination policy and send budgets, sender, non-empty body up to
 * {@see self::MAX_BODY_LENGTH} characters, device / client ids, public https status callback), delegates to
 * the provider and logs the outcome with the destination masked (never the body or credentials).
 *
 * An unknown provider name (for example a host provider that was removed) falls back to the null provider: nothing is
 * sent and a warning is logged.
 */
final readonly class ConfiguredSmsSender implements SmsSenderInterface
{
    /** Longest accepted body (Twilio concatenated-message limit). */
    public const MAX_BODY_LENGTH = 1600;

    /**
     * @param SmsProviderRegistry $providers registered providers
     * @param SmsConfigResolver $resolver effective configuration
     * @param LoggerInterface $logger PSR-3 logger
     * @param DestinationPolicy $destinations destination / cost policy (`nowo_sms_kit.destinations`)
     * @param OutboundUrlGuard $callbackGuard checks caller-supplied status callback URLs (public https only)
     */
    public function __construct(
        private SmsProviderRegistry $providers,
        private SmsConfigResolver $resolver,
        private LoggerInterface $logger = new NullLogger(),
        private DestinationPolicy $destinations = new DestinationPolicy(),
        private OutboundUrlGuard $callbackGuard = new OutboundUrlGuard(resolveDns: false),
    ) {
    }

    /**
     * Sends the message through the active provider.
     *
     * @param SmsMessage $message message to send
     *
     * @throws SmsSendException when it cannot be sent
     */
    public function send(SmsMessage $message): SmsSendResult
    {
        $config   = $this->resolver->current();
        $provider = $this->provider($config);
        $masked   = E164::mask($message->to);

        try {
            if (!$provider->isConfigured($config)) {
                throw new SmsSendException(sprintf('SMS provider "%s" is selected but not fully configured.', $provider->getName()));
            }

            $result = $provider->send($this->normalize($message, $config), $config);
        } catch (SmsSendException $e) {
            $this->logger->warning('SMS not sent.', [
                'provider' => $provider->getName(),
                'to'       => $masked,
                'error'    => Redactor::redact($e->getMessage()),
                'detail'   => $e->getDetail(),
            ]);

            throw $e;
        }

        $this->logger->info('SMS sent.', [
            'provider'   => $result->providerId,
            'to'         => $masked,
            'message_id' => $result->providerMessageId,
            'status'     => $result->status,
        ]);

        return $result;
    }

    /**
     * Whether the active provider is configured.
     */
    public function isConfigured(): bool
    {
        $config = $this->resolver->current();

        return $this->provider($config)->isConfigured($config);
    }

    /**
     * Name of the active provider (`null` when the stored name is unknown).
     */
    public function getProviderId(): string
    {
        return $this->provider($this->resolver->current())->getName();
    }

    private function provider(SmsConfig $config): SmsProviderInterface
    {
        $provider = $this->providers->get($config->provider);
        if ($provider instanceof SmsProviderInterface) {
            return $provider;
        }

        $this->logger->warning('Unknown SMS provider, falling back to the null provider.', ['provider' => $config->provider]);

        return $this->providers->get(NullSmsProvider::NAME) ?? new NullSmsProvider();
    }

    private function normalize(SmsMessage $message, SmsConfig $config): SmsMessage
    {
        $body = trim($message->body);
        if ($body === '') {
            throw new SmsSendException('SMS body is required.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new SmsSendException(sprintf('SMS body is longer than %d characters.', self::MAX_BODY_LENGTH));
        }

        $from = $message->from ?? $config->sender;
        $from = $from === null || trim($from) === '' ? null : SenderId::normalize($from);

        if ($message->deviceId !== null && $message->deviceId !== '' && preg_match(SmsMessage::DEVICE_ID_PATTERN, $message->deviceId) !== 1) {
            throw new SmsSendException('SMS device id must be 1-64 letters, digits or dashes.');
        }
        if ($message->clientMessageId !== null && $message->clientMessageId !== '' && preg_match(SmsMessage::CLIENT_MESSAGE_ID_PATTERN, $message->clientMessageId) !== 1) {
            throw new SmsSendException('SMS client message id must be 1-128 letters, digits, dots, dashes, underscores or colons.');
        }
        if ($message->statusCallbackUrl !== null && $message->statusCallbackUrl !== ''
            && (!str_starts_with($message->statusCallbackUrl, 'https://') || !$this->callbackGuard->inspect($message->statusCallbackUrl, false, false)->isValid())) {
            throw new SmsSendException('SMS status callback URL must be a public https URL.');
        }

        $to = E164::normalize($message->to);
        $this->destinations->assertAllowed($to);

        return $message->withNormalized($to, $body, $from);
    }
}
