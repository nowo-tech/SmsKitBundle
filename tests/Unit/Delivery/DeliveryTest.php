<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Delivery;

use ArrayObject;
use Nowo\SmsKitBundle\Delivery\DeferredSmsDelivery;
use Nowo\SmsKitBundle\Delivery\MessengerSmsDelivery;
use Nowo\SmsKitBundle\Delivery\SafeSender;
use Nowo\SmsKitBundle\Delivery\SendSmsMessage;
use Nowo\SmsKitBundle\Delivery\SendSmsMessageHandler;
use Nowo\SmsKitBundle\Delivery\SyncSmsDelivery;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final class DeliveryTest extends TestCase
{
    private RecordingLogger $logger;

    /** @var ArrayObject<int, SmsMessage> */
    private ArrayObject $sent;

    private function safe(bool $configured = true, ?Throwable $failure = null): SafeSender
    {
        $this->logger = new RecordingLogger();
        $this->sent   = new ArrayObject();
        $sender       = new class($configured, $failure, $this->sent) implements SmsSenderInterface {
            /**
             * @param ArrayObject<int, SmsMessage> $sent
             */
            public function __construct(private readonly bool $configured, private readonly ?Throwable $failure, private readonly ArrayObject $sent)
            {
            }

            public function send(SmsMessage $message): SmsSendResult
            {
                if ($this->failure instanceof Throwable) {
                    throw $this->failure;
                }
                $this->sent[] = $message;

                return new SmsSendResult('stub', 'id', 'queued');
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function getProviderId(): string
            {
                return 'stub';
            }
        };

        return new SafeSender($sender, $this->logger);
    }

    public function testDeferredSendsOnlyOnFlushOrReset(): void
    {
        $delivery = new DeferredSmsDelivery($this->safe());
        $delivery->deliver(new SmsMessage(Numbers::TO, 'one'));
        self::assertCount(0, $this->sent, 'nothing is sent inside the request');

        $delivery->flush();
        self::assertCount(1, $this->sent);

        $delivery->deliver(new SmsMessage(Numbers::TO, 'two'));
        $delivery->reset();
        $delivery->flush();
        self::assertCount(2, $this->sent, 'reset sends what is left, once');
    }

    public function testSyncSendsNow(): void
    {
        (new SyncSmsDelivery($this->safe()))->deliver(new SmsMessage(Numbers::TO, 'now'));

        self::assertCount(1, $this->sent);
    }

    public function testSafeSenderSkipsUnconfiguredAndSwallowsAnyThrowable(): void
    {
        $this->safe(false)->send(new SmsMessage(Numbers::TO, 'x'));
        self::assertCount(0, $this->sent);
        self::assertSame([], $this->logger->records);

        $this->safe(true, new RuntimeException('Bad ' . Numbers::TO))->send(new SmsMessage(Numbers::TO, 'x'));
        self::assertCount(1, $this->logger->records);
        self::assertSame(RuntimeException::class, $this->logger->records[0]['context']['exception_class']);
        self::assertSame('Bad +15*******23', $this->logger->records[0]['context']['error']);
    }

    public function testMessengerDispatchesAndHandles(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public bool $fail = false;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->fail) {
                    throw new RuntimeException('transport down');
                }
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
        $logger = new RecordingLogger();
        (new MessengerSmsDelivery($bus, $logger))->deliver(new SmsMessage(Numbers::TO, 'queued'));

        $message = $bus->messages[0];
        self::assertInstanceOf(SendSmsMessage::class, $message);
        self::assertSame([Numbers::TO, 'queued'], [$message->to, $message->body]);

        (new SendSmsMessageHandler($this->safe()))($message);
        self::assertSame('queued', $this->sent->getArrayCopy()[0]->body);

        $bus->fail = true;
        (new MessengerSmsDelivery($bus, $logger))->deliver(new SmsMessage(Numbers::TO, 'lost'));
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception_class']);
    }
}
