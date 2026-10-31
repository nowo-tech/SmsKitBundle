<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Provider;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Provider\TwilioProvider;
use Nowo\SmsKitBundle\Settings\SmsConfig;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\TestCase;

final class TwilioProviderTest extends TestCase
{
    use HttpTestTrait;

    private function config(?string $sender = 'Acme', ?string $service = null, ?string $sid = null, ?string $token = null): SmsConfig
    {
        return new SmsConfig('twilio', $sender, null, $sid ?? Numbers::accountSid(), $token ?? Numbers::token(), $service, 'https://api.twilio.test/');
    }

    public function testIsConfigured(): void
    {
        $provider = new TwilioProvider($this->http([]));

        self::assertSame('twilio', $provider->getName());
        self::assertSame('settings.sms.provider_twilio', $provider->getLabel());
        self::assertTrue($provider->isConfigured($this->config()));
        self::assertTrue($provider->isConfigured($this->config(null, Numbers::messagingServiceSid())));
        self::assertFalse($provider->isConfigured($this->config(null)), 'no sender and no messaging service');
        self::assertFalse($provider->isConfigured($this->config(null, 'MG-invalid')));
        self::assertFalse($provider->isConfigured($this->config(sid: 'AC123')));
        self::assertFalse($provider->isConfigured($this->config(token: ' ')));
    }

    public function testSendsAMessagesApiRequest(): void
    {
        $provider = new TwilioProvider($this->http([self::json(['sid' => 'SM1', 'status' => 'queued', 'to' => Numbers::TO])]));

        $result = $provider->send(new SmsMessage(Numbers::TO, 'hello', 'Acme', statusCallbackUrl: 'https://app.example.test/sms/status'), $this->config());

        self::assertSame(['twilio', 'SM1', 'queued'], [$result->providerId, $result->providerMessageId, $result->status]);
        self::assertSame(Numbers::TO, $result->raw['to']);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.twilio.test/2010-04-01/Accounts/' . Numbers::accountSid() . '/Messages.json', $request['url']);
        self::assertSame('To=%2B15550100123&Body=hello&From=Acme&StatusCallback=https%3A%2F%2Fapp.example.test%2Fsms%2Fstatus', $request['options']['body']);
        self::assertContains('Authorization: Basic ' . base64_encode(Numbers::accountSid() . ':' . Numbers::token()), $request['options']['headers']);
    }

    public function testUsesTheMessagingServiceWithoutSender(): void
    {
        $provider = new TwilioProvider($this->http([self::json([])]));

        $result = $provider->send(new SmsMessage(Numbers::TO, 'hello', statusCallbackUrl: ''), $this->config(null, Numbers::messagingServiceSid()));

        self::assertSame('', $result->providerMessageId);
        self::assertSame('queued', $result->status);
        self::assertStringContainsString('MessagingServiceSid=' . Numbers::messagingServiceSid(), $this->requests[0]['options']['body']);
        self::assertStringNotContainsString('StatusCallback', $this->requests[0]['options']['body']);
    }

    public function testRefusesWithoutCredentials(): void
    {
        $this->expectException(SmsSendException::class);
        $this->expectExceptionMessage('Twilio is not configured');
        (new TwilioProvider($this->http([])))->send(new SmsMessage(Numbers::TO, 'hello', 'Acme'), $this->config(token: ''));
    }

    public function testRefusesWithoutSenderOrService(): void
    {
        $this->expectException(SmsSendException::class);
        $this->expectExceptionMessage('sender');
        (new TwilioProvider($this->http([])))->send(new SmsMessage(Numbers::TO, 'hello'), $this->config(null));
    }

    public function testMapsProviderErrors(): void
    {
        $provider = new TwilioProvider($this->http([self::json(['code' => 21211, 'message' => "The 'To' number " . Numbers::TO . ' is not valid.'], 400)]));

        try {
            $provider->send(new SmsMessage(Numbers::TO, 'hello', 'Acme'), $this->config());
            self::fail('Expected a rejection.');
        } catch (SmsSendException $e) {
            self::assertStringContainsString('Twilio rejected the message (HTTP 400)', $e->getMessage());
            self::assertStringContainsString('+15*******23', $e->getMessage());
            self::assertStringNotContainsString(Numbers::TO, $e->getMessage());
        }
    }

    public function testUsesTheErrorMessageFieldAsSecondChoice(): void
    {
        $provider = new TwilioProvider($this->http([self::json(['error_message' => 'Queue overflow'], 429)]));

        $this->expectExceptionMessage('Queue overflow');
        $provider->send(new SmsMessage(Numbers::TO, 'hello', 'Acme'), $this->config());
    }
}
