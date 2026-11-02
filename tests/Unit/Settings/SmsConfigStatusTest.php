<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Settings;

use Nowo\SmsKitBundle\Provider\LogSmsProvider;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsConfigStatus;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use PHPUnit\Framework\TestCase;

final class SmsConfigStatusTest extends TestCase
{
    public function testNullProviderNeedsNothing(): void
    {
        $status = $this->configStatus(new SmsFallbacks());

        self::assertSame('null', $status->provider());
        self::assertSame([], $status->missingFields());
        self::assertFalse($status->hasMissingFields());
    }

    public function testTwilioRequiredFields(): void
    {
        self::assertSame(['twilio_account_sid', 'twilio_auth_token', 'sender'], $this->configStatus(new SmsFallbacks('twilio'))->missingFields());
        self::assertSame([], $this->configStatus(new SmsFallbacks('twilio', null, Numbers::accountSid(), Numbers::token(), Numbers::messagingServiceSid()))->missingFields());
        self::assertSame([], $this->configStatus(new SmsFallbacks('twilio', 'Acme', Numbers::accountSid(), Numbers::token()))->missingFields());
        self::assertTrue($this->configStatus(new SmsFallbacks('twilio', 'Acme', Numbers::accountSid()))->hasMissingFields());
    }

    public function testBridgeRequiredFieldsDependOnTheApiMode(): void
    {
        self::assertSame(['bridge_base_url', 'bridge_auth_token', 'bridge_account_sid'], $this->configStatus(new SmsFallbacks('sms_bridge'))->missingFields());
        self::assertSame(['bridge_base_url', 'bridge_auth_token'], $this->configStatus(new SmsFallbacks('sms_bridge', bridgeApiMode: 'native'))->missingFields());
        self::assertSame([], $this->configStatus(new SmsFallbacks('sms_bridge', bridgeBaseUrl: 'https://bridge.example.test', bridgeAccountSid: Numbers::accountSid(), bridgeAuthToken: Numbers::bridgeKey()))->missingFields());
    }

    public function testOtherProvidersUseIsConfigured(): void
    {
        self::assertSame([], $this->configStatus(new SmsFallbacks('log'))->missingFields());
        self::assertSame(['provider'], $this->configStatus(new SmsFallbacks('stub'))->missingFields());
        self::assertSame(['provider'], $this->configStatus(new SmsFallbacks('unknown'))->missingFields());
    }

    private function configStatus(SmsFallbacks $fallbacks): SmsConfigStatus
    {
        $registry = new SmsProviderRegistry([new NullSmsProvider(), new LogSmsProvider(), new StubProvider('stub', false)]);

        return new SmsConfigStatus(new SmsConfigResolver(new FakeInstanceSettings(), $fallbacks), $registry);
    }
}
