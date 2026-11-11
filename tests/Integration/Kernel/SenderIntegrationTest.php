<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Kernel;

use Doctrine\DBAL\Connection;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\SmsKitBundle\Command\SendSmsCommand;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Nowo\SmsKitBundle\Settings\FallbackSmsConfigProvider;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use ReflectionProperty;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * The sender wired in a real kernel: providers from the registry, configuration from instance settings, the memo
 * reset by SettingsChangedEvent, encrypted secrets, the CLI.
 */
final class SenderIntegrationTest extends KernelTestCase
{
    private function sender(SmsKitTestKernel $kernel): SmsSenderInterface
    {
        $sender = $this->container($kernel)->get(SmsSenderInterface::class);
        self::assertInstanceOf(SmsSenderInterface::class, $sender);

        return $sender;
    }

    public function testFreshInstallSendsNothing(): void
    {
        $sender = $this->sender($this->bootKernel());

        self::assertSame('null', $sender->getProviderId());
        self::assertFalse($sender->isConfigured());
    }

    public function testASavedProviderAppliesToTheNextSend(): void
    {
        $kernel = $this->bootKernel();
        $sender = $this->sender($kernel);
        self::assertSame('null', $sender->getProviderId(), 'memoized');

        $this->settings($kernel)->save('sms', ['provider' => 'log']);

        self::assertSame('log', $sender->getProviderId(), 'SettingsChangedEvent dropped the memo');
        self::assertSame('logged', $sender->send(new SmsMessage(Numbers::TO, 'hello'))->status);
    }

    /**
     * 1.5.1 (kernel reset false): outside HTTP the memo has no request to bind to. A consumer or a command must not keep
     * the configuration it read for its first message forever: settings saved by another process (no
     * SettingsChangedEvent here) apply from the next message / command.
     */
    public function testTheMemoIsRebuiltPerMessengerMessageAndConsoleCommand(): void
    {
        $kernel   = $this->bootKernel();
        $resolver = $this->container($kernel)->get(SmsConfigResolver::class);
        self::assertInstanceOf(SmsConfigResolver::class, $resolver);
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $memo    = new ReflectionProperty(SmsConfigResolver::class, 'config');
        $command = new Command('app:digest');

        $boundaries = [
            'WorkerMessageReceivedEvent' => static fn (): object => $dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'), WorkerMessageReceivedEvent::class),
            'console.command'            => static fn (): object => $dispatcher->dispatch(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND),
            'console.terminate'          => static fn (): object => $dispatcher->dispatch(new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), Command::SUCCESS), ConsoleEvents::TERMINATE),
        ];
        foreach ($boundaries as $name => $boundary) {
            $resolver->current();
            self::assertNotNull($memo->getValue($resolver), 'memoized outside HTTP');
            $boundary();
            self::assertNull($memo->getValue($resolver), $name . ' drops the memo');
        }
    }

    public function testSecretsAreStoredEncryptedAndResolved(): void
    {
        $kernel = $this->bootKernel();
        $this->settings($kernel)->save('sms', [
            'provider'           => 'twilio',
            'sender'             => 'Acme',
            'twilio_account_sid' => Numbers::accountSid(),
            'twilio_auth_token'  => Numbers::token(),
        ]);

        $resolver = $this->container($kernel)->get(SmsConfigResolver::class);
        self::assertInstanceOf(SmsConfigResolver::class, $resolver);
        self::assertSame(Numbers::token(), $resolver->current()->twilioAuthToken);
        self::assertTrue($this->sender($kernel)->isConfigured());

        $connection = $this->container($kernel)->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $stored = (string) $connection->fetchOne("SELECT secret_value FROM instance_setting_value WHERE section = 'sms' AND name = 'twilio_auth_token'");
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString(Numbers::token(), $stored);
    }

    public function testFallbacksFromTheBundleConfiguration(): void
    {
        $sender = $this->sender($this->bootKernel(['default_provider' => '%env(default:sms_kit_test_provider:SMS_KIT_TEST_UNDEFINED)%'], parameters: ['sms_kit_test_provider' => 'log']));

        self::assertSame('log', $sender->getProviderId());
    }

    public function testTheFallbackConfigProviderIgnoresTheSettings(): void
    {
        $kernel = $this->bootKernel(['config_provider' => FallbackSmsConfigProvider::class, 'default_provider' => 'log']);
        $this->settings($kernel)->save('sms', ['provider' => 'twilio']);

        $sender = $this->sender($kernel);
        self::assertSame('log', $sender->getProviderId(), '1.4: the stored provider is not read');
        self::assertSame('logged', $sender->send(new SmsMessage(Numbers::TO, 'hello'))->status);
    }

    public function testTheCommandIsRegistered(): void
    {
        $kernel = $this->bootKernel(['default_provider' => 'log']);
        $tester = new CommandTester((new Application($kernel))->find(SendSmsCommand::NAME));

        self::assertSame(0, $tester->execute(['to' => Numbers::TO, 'body' => 'hello']));
        self::assertStringContainsString('Sent via log to +15*******23', $tester->getDisplay());
    }

    public function testWorksWithoutTheOutboundUrlGuardBundle(): void
    {
        $kernel = $this->bootKernel(without: ['outbound_url_guard']);

        self::assertInstanceOf(OutboundUrlGuard::class, $this->container($kernel)->get(OutboundUrlGuard::class));
    }
}
