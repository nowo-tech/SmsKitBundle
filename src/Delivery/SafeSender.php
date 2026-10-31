<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Log\Redactor;
use Nowo\SmsKitBundle\Message\SmsMessage;
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
     * @param SmsMessage $message message to send
     */
    public function send(SmsMessage $message): void
    {
        try {
            if ($this->sender->isConfigured()) {
                $this->sender->send($message);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Deferred SMS not sent.', ['exception_class' => $e::class, 'error' => Redactor::redact($e->getMessage())]);
        }
    }
}
