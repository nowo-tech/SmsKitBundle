<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Provider;

use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Phone\SenderId;
use Nowo\SmsKitBundle\Settings\SmsConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function bin2hex;
use function mb_strlen;
use function random_bytes;

/**
 * Development / demo provider: writes one `info` log line per message (masked destination, sender, body length —
 * never the body, which may carry a one-time code) and reports the message as `logged`. Always configured.
 */
final readonly class LogSmsProvider implements SmsProviderInterface
{
    /** Provider name. */
    public const NAME = 'log';

    /**
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * `log`.
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
        return 'settings.sms.provider_log';
    }

    /**
     * Always configured.
     *
     * @param SmsConfig $config effective configuration (unused)
     */
    public function isConfigured(SmsConfig $config): bool
    {
        return true;
    }

    /**
     * Logs the message metadata and returns a random message id.
     *
     * @param SmsMessage $message normalized message
     * @param SmsConfig $config effective configuration (unused)
     */
    public function send(SmsMessage $message, SmsConfig $config): SmsSendResult
    {
        $id = 'log-' . bin2hex(random_bytes(8));
        $this->logger->info('SMS written to the log (log provider).', [
            'provider'    => self::NAME,
            'message_id'  => $id,
            'to'          => E164::mask($message->to),
            'from'        => $message->from === null ? null : (SenderId::isNumber($message->from) ? E164::mask($message->from) : $message->from),
            'body_length' => mb_strlen($message->body),
        ]);

        return new SmsSendResult(self::NAME, $id, 'logged');
    }
}
