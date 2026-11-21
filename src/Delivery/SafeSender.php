<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Log\Redactor;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared "send if configured, never throw" step of the deliveries: any \Throwable (provider, settings store, …) is
 * logged with its class and a masked message only.
 */
final readonly class SafeSender
{
    /**
     * @param SmsSenderInterface $sender SMS sender
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private SmsSenderInterface $sender,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Sends the message when the provider is configured; logs and swallows every failure.
     *
     * Since 2.0 a message skipped because no provider is configured is logged too (warning, once per skipped message,
     * no process-wide state): a host whose provider resolves to "null" (for example `%env(SMS_PROVIDER)%` = null
     * after Instance Settings was removed) sees in its log why AuthKit reset codes are not sent.
     *
     * @param SmsMessage $message message to send
     */
    public function send(SmsMessage $message): void
    {
        try {
            if (!$this->sender->isConfigured()) {
                $this->logger->warning('SMS not sent: no SMS provider is configured.', [
                    'provider' => $this->sender->getProviderId(),
                    'to'       => E164::mask($message->to),
                ]);

                return;
            }
            $this->sender->send($message);
        } catch (Throwable $e) {
            $this->logger->warning('Deferred SMS not sent.', ['exception_class' => $e::class, 'error' => Redactor::redact($e->getMessage())]);
        }
    }
}
