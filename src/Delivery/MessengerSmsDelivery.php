<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Message\SmsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Dispatches a {@see SendSmsMessage} (`delivery: messenger`); route it to an async transport so the send happens in a
 * worker. Dispatch failures are logged, never thrown.
 */
final readonly class MessengerSmsDelivery implements SmsDeliveryInterface
{
    /**
     * @param MessageBusInterface $bus message bus
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatches the message.
     *
     * @param SmsMessage $message message to deliver
     */
    public function deliver(SmsMessage $message): void
    {
        try {
            $this->bus->dispatch(new SendSmsMessage($message->to, $message->body));
        } catch (Throwable $e) {
            $this->logger->warning('SMS message not dispatched.', ['exception_class' => $e::class]);
        }
    }
}
