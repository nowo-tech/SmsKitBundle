<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Message\SmsMessage;

/**
 * How a message triggered by an anonymous flow (AuthKit password reset) leaves the request: right away, after the
 * response (`kernel.terminate`), or through Messenger. Implementations never throw: failures are logged.
 */
interface SmsDeliveryInterface
{
    /**
     * Sends or schedules the message.
     *
     * @param SmsMessage $message message to deliver
     */
    public function deliver(SmsMessage $message): void;
}
