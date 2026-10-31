<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Settings;

use Nowo\SmsKitBundle\Settings\SmsConfig;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\TestCase;

use function print_r;

final class SmsConfigTest extends TestCase
{
    public function testDebugInfoMasksSecrets(): void
    {
        $config = new SmsConfig('twilio', twilioAuthToken: Numbers::token(), bridgeAuthToken: Numbers::bridgeKey());

        $dump = print_r($config, true);

        self::assertStringNotContainsString(Numbers::token(), $dump);
        self::assertStringNotContainsString(Numbers::bridgeKey(), $dump);
        self::assertSame('***', $config->__debugInfo()['twilioAuthToken']);
        self::assertNull((new SmsConfig('null'))->__debugInfo()['bridgeAuthToken']);
        self::assertSame(SmsConfig::TWILIO_API_BASE_URL, $config->twilioApiBaseUrl);
        self::assertSame(SmsConfig::BRIDGE_MODE_TWILIO, $config->bridgeApiMode);
    }

    public function testFallbacksDebugInfoMasksSecrets(): void
    {
        $fallbacks = new SmsFallbacks(twilioAuthToken: Numbers::token(), bridgeAuthToken: Numbers::bridgeKey());

        self::assertStringNotContainsString(Numbers::token(), print_r($fallbacks, true));
        self::assertSame('***', $fallbacks->__debugInfo()['bridgeAuthToken']);
        self::assertNull((new SmsFallbacks())->__debugInfo()['twilioAuthToken']);
        self::assertSame('null', (new SmsFallbacks())->defaultProvider);
    }
}
