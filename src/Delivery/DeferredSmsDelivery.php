<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Message\SmsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

use function count;

/**
 * Queues messages and sends them after the unit of work (`delivery: deferred`, default): `kernel.terminate` (after
 * `Response::send()` / `fastcgi_finish_request()` on PHP-FPM and FrankenPHP), the end of every Messenger message
 * (`WorkerMessageHandledEvent`) and `console.terminate`. The response time of the triggering request no longer
 * depends on the provider, and a Messenger consumer does not hold SMS until it exits. A Messenger message that fails
 * (`WorkerMessageFailedEvent`) discards what it queued: its work was rolled back (e.g. the reset token) and a retry
 * queues it again, so the user never gets a code that does not exist or one SMS per attempt. `reset()` (kernel.reset)
 * sends anything left.
 */
final class DeferredSmsDelivery implements SmsDeliveryInterface, ResetInterface
{
    /** Messenger event names (class names), listened to without requiring symfony/messenger. */
    public const WORKER_MESSAGE_RECEIVED = 'Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent';
    public const WORKER_MESSAGE_HANDLED  = 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent';
    public const WORKER_MESSAGE_FAILED   = 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent';

    /** @var list<SmsMessage> */
    private array $queue = [];

    /**
     * @param SafeSender $sender failure-safe sender
     * @param LoggerInterface|null $logger logs how many SMS a failed message discarded (never their content)
     */
    public function __construct(
        private readonly SafeSender $sender,
        private readonly ?LoggerInterface $logger = null,
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
     * Sends the queued messages (kernel.terminate, Messenger worker message handled, console.terminate).
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
     * Drops the queued messages without sending them (Messenger message failed; since 1.5.1).
     */
    public function discard(): void
    {
        $discarded = count($this->queue);
        // Reset every queued property here so the worker-state audit can see it.
        $this->queue = [];
        if ($discarded > 0) {
            $this->logger?->notice('Deferred SMS discarded: the Messenger message that queued them failed.', ['count' => $discarded]);
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
