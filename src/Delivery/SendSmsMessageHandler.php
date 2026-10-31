<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Message\SmsMessage;

/**
 * Handles {@see SendSmsMessage}: sends through the active provider when configured; failures are logged (no retry
 * of a one-time code that may already be stale).
 */
final readonly class SendSmsMessageHandler
{
    /**
     * @param SafeSender $sender failure-safe sender
     */
    public function __construct(
        private SafeSender $sender,
    ) {
    }

    /**
     * Sends the message.
     *
     * @param SendSmsMessage $message queued message
     */
    public function __invoke(SendSmsMessage $message): void
    {
        $this->sender->send(new SmsMessage($message->to, $message->body));
    }
}
