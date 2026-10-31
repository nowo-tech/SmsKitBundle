<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Sender;

use Nowo\SmsKitBundle\Exception\InvalidPhoneNumberException;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderInterface;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Sender\ConfiguredSmsSender;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\RecordingLogger;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_repeat;

final class ConfiguredSmsSenderTest extends TestCase
{
    private RecordingLogger $logger;

    /**
     * @param array<string, string> $values
     * @param list<SmsProviderInterface> $providers
     */
    private function sender(array $values, array $providers, ?string $fallbackSender = null): ConfiguredSmsSender
    {
        $this->logger = new RecordingLogger();

        return new ConfiguredSmsSender(
            new SmsProviderRegistry($providers),
            new SmsConfigResolver(new FakeInstanceSettings($values), new SmsFallbacks(sender: $fallbackSender)),
            $this->logger,
        );
    }

    public function testSendsThroughTheActiveProviderAndLogsMasked(): void
    {
        $stub   = new StubProvider();
        $sender = $this->sender(['sms.provider' => 'stub', 'sms.sender' => 'Acme'], [new NullSmsProvider(), $stub]);

        $result = $sender->send(new SmsMessage(' +1 555 0100 123 ', '  Your code is 424242  '));

        self::assertSame('id-1', $result->providerMessageId);
        self::assertSame([Numbers::TO, 'Your code is 424242', 'Acme'], [$stub->sent[0]->to, $stub->sent[0]->body, $stub->sent[0]->from]);
        self::assertTrue($sender->isConfigured());
        self::assertSame('stub', $sender->getProviderId());
        self::assertSame('SMS sent.', $this->logger->records[0]['message']);
        self::assertSame('+15*******23', $this->logger->records[0]['context']['to']);
        self::assertStringNotContainsString('424242', (string) json_encode($this->logger->records));
    }

    public function testMessageSenderOverridesTheConfiguredOne(): void
    {
        $stub   = new StubProvider();
        $sender = $this->sender(['sms.provider' => 'stub'], [$stub], 'Fallback');

        $sender->send(new SmsMessage(Numbers::TO, 'hi', ' +1 555 0100 199 '));
        $sender->send(new SmsMessage(Numbers::TO, 'hi'));
        $sender->send(new SmsMessage(Numbers::TO, 'hi', ' '));

        self::assertSame(Numbers::OTHER, $stub->sent[0]->from);
        self::assertSame('Fallback', $stub->sent[1]->from);
        self::assertNull($stub->sent[2]->from);
    }

    public function testUnknownProviderFallsBackToNull(): void
    {
        $sender = $this->sender(['sms.provider' => 'removed_gateway'], [new NullSmsProvider()]);

        self::assertFalse($sender->isConfigured());
        self::assertSame('null', $sender->getProviderId());
        self::assertSame('Unknown SMS provider, falling back to the null provider.', $this->logger->records[0]['message']);

        $standalone = $this->sender(['sms.provider' => 'removed_gateway'], [new StubProvider()]);
        self::assertSame('null', $standalone->getProviderId(), 'works without a registered null provider');
    }

    public function testRefusesAnUnconfiguredProvider(): void
    {
        $sender = $this->sender(['sms.provider' => 'stub'], [new StubProvider(configured: false)]);

        $this->expectException(SmsSendException::class);
        $this->expectExceptionMessage('SMS provider "stub" is selected but not fully configured.');
        $sender->send(new SmsMessage(Numbers::TO, 'hi'));
    }

    public function testValidatesTheMessage(): void
    {
        $sender = $this->sender(['sms.provider' => 'stub'], [new StubProvider()]);

        foreach ([
            [new SmsMessage(Numbers::TO, '   '), 'SMS body is required.'],
            [new SmsMessage(Numbers::TO, str_repeat('a', 1601)), 'longer than 1600'],
            [new SmsMessage('555-0100', 'hi'), 'E.164'],
            [new SmsMessage(Numbers::TO, 'hi', 'Bad sender!'), 'alphanumeric sender id'],
            [new SmsMessage('+88234567890', 'hi'), 'denied range'],
            [new SmsMessage(Numbers::TO, 'hi', deviceId: "dev\n1"), 'device id'],
            [new SmsMessage(Numbers::TO, 'hi', clientMessageId: 'a b'), 'client message id'],
            [new SmsMessage(Numbers::TO, 'hi', statusCallbackUrl: 'http://app.example.test/cb'), 'public https'],
            [new SmsMessage(Numbers::TO, 'hi', statusCallbackUrl: 'https://127.0.0.1/cb'), 'public https'],
        ] as [$message, $error]) {
            try {
                $sender->send($message);
                self::fail('Expected a refusal.');
            } catch (SmsSendException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }
        self::assertCount(9, $this->logger->records);
        self::assertSame('SMS not sent.', $this->logger->records[0]['message']);
    }

    public function testProviderFailureIsLoggedRedactedAndRethrown(): void
    {
        $sender = $this->sender(['sms.provider' => 'stub'], [new StubProvider(failWith: 'Invalid To ' . Numbers::TO)]);

        try {
            $sender->send(new SmsMessage(Numbers::TO, 'hi'));
            self::fail('Expected a failure.');
        } catch (SmsSendException $e) {
            self::assertNotInstanceOf(InvalidPhoneNumberException::class, $e);
        }
        self::assertSame('Invalid To +15*******23', $this->logger->records[0]['context']['error']);
    }

    public function testValidOverridesPass(): void
    {
        $stub   = new StubProvider();
        $sender = $this->sender(['sms.provider' => 'stub'], [$stub]);

        $sender->send(new SmsMessage(Numbers::TO, 'hi', null, 'device-1', 'ticket:42', 'https://app.example.test/sms/status'));
        $sender->send(new SmsMessage(Numbers::TO, 'hi', null, '', '', ''));

        self::assertSame(['device-1', 'ticket:42'], [$stub->sent[0]->deviceId, $stub->sent[0]->clientMessageId]);
        self::assertCount(2, $stub->sent);
    }

    public function testTheProviderDetailIsLoggedNotShown(): void
    {
        $this->sender(['sms.provider' => 'stub'], [new StubProvider()]);
        $e = new SmsSendException('Acme rejected the message (HTTP 400).', 'internal detail');

        self::assertSame('internal detail', $e->getDetail());
        self::assertNull((new SmsSendException('x'))->getDetail());
    }
}
