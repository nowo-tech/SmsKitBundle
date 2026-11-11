<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Message\SmsMessage;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Queues messages and sends them after the unit of work (`delivery: deferred`, default): `kernel.terminate` (after
 * `Response::send()` / `fastcgi_finish_request()` on PHP-FPM and FrankenPHP), the end of every Messenger message
 * (`WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`) and `console.terminate`. The response time of the
 * triggering request no longer depends on the provider, and a Messenger consumer does not hold SMS until it exits.
 * `reset()` (kernel.reset) sends anything left.
 */
final class DeferredSmsDelivery implements SmsDeliveryInterface, ResetInterface
{
    /** Messenger event names (class names), listened to without requiring symfony/messenger. */
    public const WORKER_MESSAGE_HANDLED = 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent';
    public const WORKER_MESSAGE_FAILED  = 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent';

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
     * Sends the queued messages (kernel.terminate, Messenger worker message handled / failed, console.terminate).
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
