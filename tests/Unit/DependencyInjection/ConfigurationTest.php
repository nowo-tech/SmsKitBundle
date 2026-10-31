<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\DependencyInjection;

use Nowo\SmsKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

use function preg_quote;

final class ConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config = []): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    public function testSafeDefaults(): void
    {
        $config = $this->process();

        self::assertSame('null', $config['default_provider']);
        self::assertNull($config['sender']);
        self::assertSame(['timeout' => 5.0, 'max_duration' => 15.0, 'allow_private_endpoints' => false, 'resolve_dns' => true, 'require_https' => true, 'max_response_bytes' => 65536], $config['http']);
        self::assertSame([], $config['destinations']['allowed_country_codes']);
        self::assertContains('882', $config['destinations']['denied_prefixes']);
        self::assertFalse($config['destinations']['rate_limit']['per_destination']['enabled']);
        self::assertFalse($config['destinations']['rate_limit']['global']['enabled']);
        self::assertSame('https://api.twilio.com', $config['providers']['twilio']['api_base_url']);
        self::assertNull($config['providers']['twilio']['auth_token']);
        self::assertSame('twilio', $config['providers']['sms_bridge']['api_mode']);
        self::assertTrue($config['providers']['log']['enabled']);
        self::assertSame(['enabled' => true, 'path' => '/admin/settings/sms/test', 'rate_limit' => ['limit' => 5, 'interval' => '15 minutes']], $config['test_action']);
        self::assertSame(['enabled' => false, 'phone_property' => 'phone', 'phone_resolver' => null, 'delivery' => 'deferred'], $config['auth_kit']['password_reset']);
    }

    public function testAcceptsAnEnvPlaceholderAsDefaultProvider(): void
    {
        self::assertSame('%env(SMS_PROVIDER)%', $this->process(['default_provider' => '%env(SMS_PROVIDER)%'])['default_provider']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'provider name' => [['default_provider' => 'Twilio!'], 'default_provider'];
        yield 'provider type' => [['default_provider' => 5], 'default_provider'];
        yield 'timeout' => [['http' => ['timeout' => 0.0]], 'timeout'];
        yield 'max duration below timeout' => [['http' => ['timeout' => 10.0, 'max_duration' => 5.0]], 'max_duration'];
        yield 'api mode' => [['providers' => ['sms_bridge' => ['api_mode' => 'soap']]], 'api_mode'];
        yield 'twilio base url' => [['providers' => ['twilio' => ['api_base_url' => '']]], 'api_base_url'];
        yield 'test path' => [['test_action' => ['path' => 'relative']], 'test_action.path'];
        yield 'test path type' => [['test_action' => ['path' => 3]], 'test_action.path'];
        yield 'rate limit' => [['test_action' => ['rate_limit' => ['limit' => 0]]], 'limit'];
        yield 'interval' => [['test_action' => ['rate_limit' => ['interval' => 'soon']]], 'interval'];
        yield 'interval zero' => [['test_action' => ['rate_limit' => ['interval' => '0 seconds']]], 'interval'];
        yield 'interval type' => [['test_action' => ['rate_limit' => ['interval' => 5]]], 'interval'];
        yield 'phone property' => [['auth_kit' => ['password_reset' => ['phone_property' => 'phone-number']]], 'phone_property'];
        yield 'phone property type' => [['auth_kit' => ['password_reset' => ['phone_property' => 1]]], 'phone_property'];
        yield 'unknown key' => [['unknown' => true], 'unknown'];
        yield 'response cap' => [['http' => ['max_response_bytes' => 10]], 'max_response_bytes'];
        yield 'country code' => [['destinations' => ['allowed_country_codes' => ['+34']]], 'allowed_country_codes'];
        yield 'denied prefix' => [['destinations' => ['denied_prefixes' => [882]]], 'denied_prefixes'];
        yield 'budget interval' => [['destinations' => ['rate_limit' => ['global' => ['interval' => 'soon']]]], 'rate_limit.global.interval'];
        yield 'budget interval type' => [['destinations' => ['rate_limit' => ['per_destination' => ['interval' => 3]]]], 'rate_limit.per_destination.interval'];
        yield 'delivery' => [['auth_kit' => ['password_reset' => ['delivery' => 'later']]], 'delivery'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidValues(array $config, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($path, '/') . '/');
        $this->process($config);
    }
}
