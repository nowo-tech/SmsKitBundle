<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

/**
 * Messenger message of {@see MessengerSmsDelivery}: destination and body only (the body may carry a one-time code:
 * keep the transport private and short-lived).
 */
final readonly class SendSmsMessage
{
    /**
     * @param string $to E.164 destination
     * @param string $body message text
     */
    public function __construct(
        public string $to,
        public string $body,
    ) {
    }
}
