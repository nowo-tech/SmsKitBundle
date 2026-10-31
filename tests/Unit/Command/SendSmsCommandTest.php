<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Command;

use Nowo\SmsKitBundle\Command\SendSmsCommand;
use Nowo\SmsKitBundle\Provider\LogSmsProvider;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Sender\ConfiguredSmsSender;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SendSmsCommandTest extends TestCase
{
    private function tester(StubProvider $provider): CommandTester
    {
        $sender = new ConfiguredSmsSender(
            new SmsProviderRegistry([new NullSmsProvider(), $provider]),
            new SmsConfigResolver(new FakeInstanceSettings(['sms.provider' => $provider->getName()]), new SmsFallbacks()),
        );

        return new CommandTester(new SendSmsCommand($sender));
    }

    public function testFailsWithoutAConfiguredProvider(): void
    {
        $tester = $this->tester(new StubProvider(configured: false));

        self::assertSame(Command::FAILURE, $tester->execute(['to' => Numbers::TO, 'body' => 'hi']));
        self::assertStringContainsString('configured: no', $tester->getDisplay());
        self::assertStringContainsString('not configured', $tester->getDisplay());
    }

    public function testSendsWithOptions(): void
    {
        $provider = new StubProvider();
        $tester   = $this->tester($provider);

        self::assertSame(Command::SUCCESS, $tester->execute(['to' => Numbers::TO, 'body' => 'hello', '--from' => ' Acme ', '--device-id' => 'device-1', '--client-message-id' => 'client-1']));
        self::assertSame(Command::SUCCESS, $tester->execute(['to' => Numbers::TO, 'body' => 'again', '--from' => '']));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Sent via stub to +15*******23: id=id-2 status=queued', $display);
        self::assertStringNotContainsString(Numbers::TO, $display);
        self::assertSame(['Acme', 'device-1', 'client-1'], [$provider->sent[0]->from, $provider->sent[0]->deviceId, $provider->sent[0]->clientMessageId]);
        self::assertNull($provider->sent[1]->from);
    }

    public function testReportsSendFailures(): void
    {
        $tester = $this->tester(new StubProvider(failWith: 'Provider down'));

        self::assertSame(Command::FAILURE, $tester->execute(['to' => Numbers::TO, 'body' => 'hi']));
        self::assertStringContainsString('Provider down', $tester->getDisplay());
    }

    public function testPrintsADashWithoutMessageId(): void
    {
        $sender = new ConfiguredSmsSender(
            new SmsProviderRegistry([new LogSmsProvider()]),
            new SmsConfigResolver(new FakeInstanceSettings(['sms.provider' => 'log']), new SmsFallbacks()),
        );
        $tester = new CommandTester(new SendSmsCommand($sender));

        self::assertSame(Command::SUCCESS, $tester->execute(['to' => Numbers::TO, 'body' => 'hi']));
        self::assertStringContainsString('status=logged', $tester->getDisplay());
    }
}
