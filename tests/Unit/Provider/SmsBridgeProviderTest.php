<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Provider;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Provider\SmsBridgeProvider;
use Nowo\SmsKitBundle\Settings\SmsConfig;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\TestCase;

use function json_decode;

final class SmsBridgeProviderTest extends TestCase
{
    use HttpTestTrait;

    private function config(string $mode = 'twilio', ?string $sid = null, ?string $key = null, ?string $url = 'https://bridge.example.test/', ?string $device = null): SmsConfig
    {
        return new SmsConfig('sms_bridge', bridgeBaseUrl: $url, bridgeApiMode: $mode, bridgeAccountSid: $sid ?? Numbers::accountSid(), bridgeAuthToken: $key ?? Numbers::bridgeKey(), bridgeDeviceId: $device);
    }

    public function testIsConfigured(): void
    {
        $provider = new SmsBridgeProvider($this->http([]));

        self::assertSame('sms_bridge', $provider->getName());
        self::assertSame('settings.sms.provider_sms_bridge', $provider->getLabel());
        self::assertTrue($provider->isConfigured($this->config()));
        self::assertFalse($provider->isConfigured($this->config(sid: 'AC1')), 'twilio mode needs the Account SID');
        self::assertTrue($provider->isConfigured($this->config('native', sid: '')));
        self::assertTrue($provider->isConfigured($this->config(' NATIVE ', sid: '')));
        self::assertFalse($provider->isConfigured($this->config('native', key: 'not-a-client-key')));
        self::assertFalse($provider->isConfigured($this->config(url: null)));
        self::assertFalse($provider->isConfigured($this->config(key: ' ')));
    }

    public function testTwilioCompatibleMode(): void
    {
        $provider = new SmsBridgeProvider($this->http([self::json(['sid' => 'SM2', 'status' => 'accepted'])]));

        $result = $provider->send(new SmsMessage(Numbers::TO, 'hello', Numbers::OTHER, 'device-2', 'client-2', 'https://app.example.test/cb'), $this->config(device: 'device-1'));

        self::assertSame(['sms_bridge', 'SM2', 'accepted'], [$result->providerId, $result->providerMessageId, $result->status]);
        self::assertSame('https://bridge.example.test/2010-04-01/Accounts/' . Numbers::accountSid() . '/Messages.json', $this->requests[0]['url']);
        self::assertSame('To=%2B15550100123&Body=hello&From=%2B15550100199&DeviceId=device-2&ClientMessageId=client-2&StatusCallback=https%3A%2F%2Fapp.example.test%2Fcb', $this->requests[0]['options']['body']);
    }

    public function testTwilioCompatibleModeUsesTheConfiguredDevice(): void
    {
        $provider = new SmsBridgeProvider($this->http([self::json([])]));

        $provider->send(new SmsMessage(Numbers::TO, 'hello', clientMessageId: '', statusCallbackUrl: ''), $this->config(device: 'device-1'));
        $provider->send(new SmsMessage(Numbers::TO, 'hello', deviceId: ''), $this->config());

        self::assertSame('To=%2B15550100123&Body=hello&DeviceId=device-1', $this->requests[0]['options']['body']);
        self::assertSame('To=%2B15550100123&Body=hello', $this->requests[1]['options']['body']);
    }

    public function testNativeMode(): void
    {
        $provider = new SmsBridgeProvider($this->http([self::json(['id' => 'uuid-1', 'status' => 'pending'], 202), self::json([], 202)]));

        $result = $provider->send(new SmsMessage(Numbers::TO, 'hello', Numbers::OTHER, null, 'client-3'), $this->config('native', device: 'device-1'));
        $empty  = $provider->send(new SmsMessage(Numbers::TO, 'hello', deviceId: '', clientMessageId: ''), $this->config('native'));

        self::assertSame(['uuid-1', 'pending'], [$result->providerMessageId, $result->status]);
        self::assertSame(['', 'queued'], [$empty->providerMessageId, $empty->status]);
        self::assertSame('https://bridge.example.test/api/v1/sms', $this->requests[0]['url']);
        self::assertContains('Authorization: Bearer ' . Numbers::bridgeKey(), $this->requests[0]['options']['headers']);
        self::assertSame(['to' => Numbers::TO, 'body' => 'hello', 'from' => Numbers::OTHER, 'deviceId' => 'device-1', 'clientMessageId' => 'client-3'], json_decode((string) $this->requests[0]['options']['body'], true));
        self::assertSame(['to' => Numbers::TO, 'body' => 'hello'], json_decode((string) $this->requests[1]['options']['body'], true));
    }

    public function testNativeErrors(): void
    {
        $provider = new SmsBridgeProvider($this->http([self::json(['error' => 'No online device'], 503)]));

        $this->expectException(SmsSendException::class);
        $this->expectExceptionMessage('SMS Bridge rejected the message (HTTP 503): No online device');
        $provider->send(new SmsMessage(Numbers::TO, 'hello'), $this->config('native'));
    }

    public function testRefusesWhenNotConfigured(): void
    {
        $this->expectException(SmsSendException::class);
        $this->expectExceptionMessage('SMS Bridge is not configured');
        (new SmsBridgeProvider($this->http([])))->send(new SmsMessage(Numbers::TO, 'hello'), $this->config(url: ''));
    }
}
