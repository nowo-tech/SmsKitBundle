<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SmsConfigResolverTest extends TestCase
{
    public function testStoredValuesWinOverFallbacks(): void
    {
        $settings = new FakeInstanceSettings([
            'sms.provider'                     => 'Twilio',
            'sms.sender'                       => ' Acme ',
            'sms.test_recipient'               => Numbers::TO,
            'sms.twilio_account_sid'           => Numbers::accountSid(),
            'sms.twilio_messaging_service_sid' => Numbers::messagingServiceSid(),
            'sms.bridge_base_url'              => 'https://bridge.example.test',
            'sms.bridge_api_mode'              => 'native',
            'sms.bridge_account_sid'           => Numbers::accountSid(),
            'sms.bridge_device_id'             => 'device-1',
        ], [
            'sms.twilio_auth_token' => ' ' . Numbers::token() . ' ',
            'sms.bridge_auth_token' => Numbers::bridgeKey(),
        ]);
        $resolver = new SmsConfigResolver($settings, new SmsFallbacks('log', 'Fallback', twilioAuthToken: 'unused'));

        $config = $resolver->current();

        self::assertSame('twilio', $config->provider);
        self::assertSame('Acme', $config->sender);
        self::assertSame(Numbers::TO, $config->testRecipient);
        self::assertSame(Numbers::token(), $config->twilioAuthToken);
        self::assertSame(Numbers::messagingServiceSid(), $config->twilioMessagingServiceSid);
        self::assertSame('https://bridge.example.test', $config->bridgeBaseUrl);
        self::assertSame('native', $config->bridgeApiMode);
        self::assertSame(Numbers::bridgeKey(), $config->bridgeAuthToken);
        self::assertSame('device-1', $config->bridgeDeviceId);
    }

    public function testFallbacksApplyWhenNothingIsStored(): void
    {
        $fallbacks = new SmsFallbacks('log', 'Acme', Numbers::accountSid(), Numbers::token(), Numbers::messagingServiceSid(), 'https://api.example.test', 'https://bridge.example.test', 'native', Numbers::accountSid(), Numbers::bridgeKey(), 'device-9');
        $resolver  = new SmsConfigResolver(new FakeInstanceSettings(['sms.sender' => '  '], ['sms.twilio_auth_token' => ' ']), $fallbacks);

        $config = $resolver->current();

        self::assertSame('log', $config->provider);
        self::assertSame('Acme', $config->sender);
        self::assertNull($config->testRecipient);
        self::assertSame(Numbers::token(), $config->twilioAuthToken);
        self::assertSame('https://api.example.test', $config->twilioApiBaseUrl);
        self::assertSame('native', $config->bridgeApiMode);
        self::assertSame('device-9', $config->bridgeDeviceId);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function providerNames(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'empty' => ['  ', 'null'];
        yield 'none' => ['None', 'null'];
        yield 'disabled' => ['disabled', 'null'];
        yield 'off' => ['off', 'null'];
        yield 'name' => [' SMS_Bridge ', 'sms_bridge'];
    }

    #[DataProvider('providerNames')]
    public function testNormalizeProvider(?string $name, string $expected): void
    {
        self::assertSame($expected, SmsConfigResolver::normalizeProvider($name));
    }

    public function testMemoIsBoundToTheMainRequest(): void
    {
        $settings = new FakeInstanceSettings(['sms.provider' => 'log']);
        $stack    = new RequestStack();
        $resolver = new SmsConfigResolver($settings, new SmsFallbacks(), $stack);

        $stack->push(new Request());
        $first = $resolver->current();
        self::assertSame($first, $resolver->current());

        $stack->pop();
        $stack->push(new Request());
        self::assertNotSame($first, $resolver->current(), 'a new main request rebuilds the configuration');

        $stack->pop();
        $outside = $resolver->current();
        self::assertSame($outside, $resolver->current(), 'outside HTTP the memo holds until reset');
    }

    public function testResetAndSettingsChangedDropTheMemo(): void
    {
        $settings = new FakeInstanceSettings(['sms.provider' => 'log']);
        $resolver = new SmsConfigResolver($settings, new SmsFallbacks());

        $first = $resolver->current();
        $resolver->onSettingsChanged(new SettingsChangedEvent('mailer', ['dsn']));
        self::assertSame($first, $resolver->current(), 'another section does not reset');

        $settings->values['sms.provider'] = 'twilio';
        $resolver->onSettingsChanged(new SettingsChangedEvent('sms', ['provider']));
        self::assertSame('twilio', $resolver->current()->provider);

        $settings->values['sms.provider'] = 'sms_bridge';
        $resolver->reset();
        self::assertSame('sms_bridge', $resolver->current()->provider);
    }
}
