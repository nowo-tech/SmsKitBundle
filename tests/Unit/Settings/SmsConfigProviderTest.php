<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Settings;

use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Sender\ConfiguredSmsSender;
use Nowo\SmsKitBundle\Settings\FallbackSmsConfigProvider;
use Nowo\SmsKitBundle\Settings\SmsConfig;
use Nowo\SmsKitBundle\Settings\SmsConfigProviderInterface;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsConfigStatus;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.4 (DDD P2, pattern 6): the configuration of a send comes from a port.
 */
final class SmsConfigProviderTest extends TestCase
{
    public function testTheFallbackProviderReadsTheBundleConfigurationOnly(): void
    {
        $provider = new FallbackSmsConfigProvider(new SmsFallbacks('Disabled', 'Acme', 'AC1', 'tok', 'MG1', 'https://twilio.test', 'https://bridge.test', SmsConfig::BRIDGE_MODE_NATIVE, 'AC2', 'cli', 'dev-1'));

        self::assertEquals(new SmsConfig('null', 'Acme', null, 'AC1', 'tok', 'MG1', 'https://twilio.test', 'https://bridge.test', SmsConfig::BRIDGE_MODE_NATIVE, 'AC2', 'cli', 'dev-1'), $provider->current());
        self::assertSame('twilio', (new FallbackSmsConfigProvider(new SmsFallbacks('Twilio')))->current()->provider);
    }

    public function testTheSettingsResolverStaysTheDefaultProvider(): void
    {
        $resolver = $this->port(new SmsConfigResolver(new FakeInstanceSettings(['sms.provider' => 'log']), new SmsFallbacks()));

        self::assertSame('log', $resolver->current()->provider);
    }

    public function testAHostProviderDrivesTheSenderAndTheStatus(): void
    {
        $host = new class implements SmsConfigProviderInterface {
            public int $calls = 0;

            public function current(): SmsConfig
            {
                ++$this->calls;

                return new SmsConfig('stub', 'Tenant');
            }
        };
        $stub     = new StubProvider();
        $registry = new SmsProviderRegistry([$stub]);

        $result = (new ConfiguredSmsSender($registry, $host))->send(new SmsMessage(Numbers::TO, 'hello'));

        self::assertSame('queued', $result->status);
        self::assertCount(1, $stub->sent);
        self::assertSame('Tenant', $stub->sent[0]->from);
        $status = new SmsConfigStatus($host, $registry);
        self::assertSame('stub', $status->provider());
        self::assertFalse($status->isProviderUnset(), 'no settings store: never "unset"');
        self::assertGreaterThanOrEqual(2, $host->calls, 'read on every call');
    }

    private function port(SmsConfigProviderInterface $provider): SmsConfigProviderInterface
    {
        return $provider;
    }
}
