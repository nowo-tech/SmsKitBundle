<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Message\SmsMessage;

/**
 * Sends inside the current request (`delivery: sync`). The provider latency then shows in the response time of the
 * triggering request: use it only for authenticated flows or tests.
 */
final readonly class SyncSmsDelivery implements SmsDeliveryInterface
{
    /**
     * @param SafeSender $sender failure-safe sender
     */
    public function __construct(
        private SafeSender $sender,
    ) {
    }

    /**
     * Sends now.
     *
     * @param SmsMessage $message message to deliver
     */
    public function deliver(SmsMessage $message): void
    {
        $this->sender->send($message);
    }
}
