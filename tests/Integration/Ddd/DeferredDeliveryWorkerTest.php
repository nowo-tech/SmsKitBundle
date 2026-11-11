<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Ddd;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\SmsKitBundle\AuthKit\SmsPasswordResetNotifier;
use Nowo\SmsKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SmsKitBundle\Tests\Integration\Kernel\SmsKitTestKernel;
use Nowo\SmsKitBundle\Tests\Support\NoopPasswordResetNotifier;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;
use function str_contains;

/**
 * `delivery: deferred` (default) in a Messenger consumer with the kernel kept alive and no services resetter
 * (FrankenPHP worker / `messenger:consume`, kernel reset false): an AuthKit password-reset SMS queued while a message
 * is handled must be sent when that message ends (`WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`), not
 * when the consumer process exits, and must never be sent with the next message. N = 600 messages for two users in
 * alternation (one in five failing), memory margin 2 MiB.
 */
final class DeferredDeliveryWorkerTest extends KernelTestCase
{
    private const ITERATIONS    = 600;
    private const WARMUP        = 100;
    private const MEMORY_MARGIN = 2 * 1024 * 1024;

    public function testMessengerConsumerSendsEachMessagesSmsWhenTheMessageEnds(): void
    {
        $kernel    = $this->bootDeferred();
        $container = $this->container($kernel);
        $notifier  = $container->get(PasswordResetNotifierInterface::class);
        self::assertInstanceOf(SmsPasswordResetNotifier::class, $notifier);
        $stub = $container->get(StubProvider::class);
        self::assertInstanceOf(StubProvider::class, $stub);
        $logger = $this->logger($kernel);

        $users = ['A' => $this->user(Numbers::TO), 'B' => $this->user(Numbers::OTHER)];

        $before = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            if ($i === self::WARMUP) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }

            $key      = $i % 2 === 0 ? 'A' : 'B';
            $code     = sprintf('%06d', $i);
            $envelope = new Envelope(new stdClass());

            $this->dispatch($kernel, new WorkerMessageReceivedEvent($envelope, 'async'), WorkerMessageReceivedEvent::class);
            $notifier->notify($this->token($users[$key], $code), $this->context($users[$key]));
            self::assertSame([], $stub->sent, sprintf('Message %d: deferred, nothing is sent while the message is handled', $i));

            if ($i % 5 === 0) {
                $this->dispatch($kernel, new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('handler failed')), WorkerMessageFailedEvent::class);
            } else {
                $this->dispatch($kernel, new WorkerMessageHandledEvent($envelope, 'async'), WorkerMessageHandledEvent::class);
            }

            self::assertCount(1, $stub->sent, sprintf('Message %d: its SMS is sent when the message ends, exactly once', $i));
            self::assertSame($key === 'A' ? Numbers::TO : Numbers::OTHER, $stub->sent[0]->to, sprintf('Message %d: sent to its own user', $i));
            self::assertTrue(str_contains($stub->sent[0]->body, $code), sprintf('Message %d: carries its own code', $i));
            $stub->sent = [];
            // The test logger keeps every record; a real logger writes them out.
            $logger->records = [];
        }

        gc_collect_cycles();
        self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $before, 'Memory must stay flat over 500 messages.');

        // The next message boundary has nothing left to send (no SMS carried over).
        $this->dispatch($kernel, new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'), WorkerMessageHandledEvent::class);
        self::assertSame([], $stub->sent);
    }

    public function testConsoleTerminateSendsTheQueue(): void
    {
        $kernel   = $this->bootDeferred();
        $notifier = $this->container($kernel)->get(PasswordResetNotifierInterface::class);
        self::assertInstanceOf(SmsPasswordResetNotifier::class, $notifier);
        $stub = $this->container($kernel)->get(StubProvider::class);
        self::assertInstanceOf(StubProvider::class, $stub);

        $user = $this->user(Numbers::TO);
        $notifier->notify($this->token($user, '424242'), $this->context($user));
        self::assertSame([], $stub->sent);

        $this->dispatch(
            $kernel,
            new ConsoleTerminateEvent(new Command('app:reset'), new ArrayInput([]), new NullOutput(), Command::SUCCESS),
            ConsoleEvents::TERMINATE,
        );

        self::assertCount(1, $stub->sent);
        self::assertSame(Numbers::TO, $stub->sent[0]->to);
    }

    private function bootDeferred(): SmsKitTestKernel
    {
        return $this->bootKernel(
            [
                'default_provider' => 'stub',
                'sender'           => 'Acme',
                'test_action'      => ['enabled' => false],
                'auth_kit'         => ['password_reset' => ['enabled' => true]],
            ],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_sms_kit');
            },
            without: ['instance_settings', 'doctrine'],
            extra: static function (ContainerConfigurator $container): void {
                $services = $container->services();
                $services->set(StubProvider::class)->public()->tag('nowo_sms_kit.provider');
                // AuthKit's own notifier (e-mail); SmsKit decorates it.
                $services->set(PasswordResetNotifierInterface::class, NoopPasswordResetNotifier::class)->public();
            },
        );
    }

    private function dispatch(SmsKitTestKernel $kernel, object $event, string $name): void
    {
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event, $name);
    }

    private function user(string $phone): stdClass
    {
        $user        = new stdClass();
        $user->phone = $phone;

        return $user;
    }

    private function token(object $user, string $code): PasswordResetTokenResult
    {
        return new PasswordResetTokenResult($user, $code, new DateTimeImmutable('+1 hour'), PasswordResetDeliveryMode::Code);
    }

    private function context(object $user): PasswordResetNotificationContext
    {
        return new PasswordResetNotificationContext('user@example.test', 'https://app.example.test/reset', PasswordResetDeliveryMode::Code, null, null, $user);
    }
}
