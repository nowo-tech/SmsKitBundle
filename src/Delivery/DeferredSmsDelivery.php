<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Message\SmsMessage;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Queues messages and sends them after the response (`delivery: deferred`, default): `kernel.terminate` (after
 * `Response::send()` / `fastcgi_finish_request()` on PHP-FPM and FrankenPHP) and `console.terminate`. The response
 * time of the triggering request no longer depends on the provider. `reset()` (kernel.reset) sends anything left.
 */
final class DeferredSmsDelivery implements SmsDeliveryInterface, ResetInterface
{
    /** @var list<SmsMessage> */
    private array $queue = [];

    /**
     * @param SafeSender $sender failure-safe sender
     */
    public function __construct(
        private readonly SafeSender $sender,
    ) {
    }

    /**
     * Queues the message.
     *
     * @param SmsMessage $message message to deliver
     */
    public function deliver(SmsMessage $message): void
    {
        $this->queue[] = $message;
    }

    /**
     * Sends the queued messages (kernel.terminate / console.terminate).
     */
    public function flush(): void
    {
        $queue = $this->queue;
        // Reset every queued property here so the worker-state audit can see it.
        $this->queue = [];
        foreach ($queue as $message) {
            $this->sender->send($message);
        }
    }

    /**
     * Sends anything still queued (kernel.reset) so nothing crosses into the next request.
     */
    public function reset(): void
    {
        $queue = $this->queue;
        // Reset every queued property here (not only through flush()) so the worker-state audit can see it.
        $this->queue = [];
        foreach ($queue as $message) {
            $this->sender->send($message);
        }
    }
}
