<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Policy;

use Nowo\SmsKitBundle\Exception\DestinationNotAllowedException;
use Nowo\SmsKitBundle\Policy\DestinationPolicy;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class DestinationPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedByDefault(): iterable
    {
        yield 'international networks +882' => ['+88234567890'];
        yield 'international networks +883' => ['+88312345678'];
        yield 'satellite +881' => ['+88161234567'];
        yield 'Inmarsat +870' => ['+870123456789'];
        yield 'premium IPRS +979' => ['+97912345678'];
    }

    #[DataProvider('deniedByDefault')]
    public function testPremiumAndSatelliteRangesAreRefusedByDefault(string $to): void
    {
        $this->expectException(DestinationNotAllowedException::class);
        $this->expectExceptionMessage('denied range');
        (new DestinationPolicy())->assertAllowed($to);
    }

    public function testEveryOtherCountryIsAllowedByDefault(): void
    {
        (new DestinationPolicy())->assertAllowed(Numbers::TO);
        (new DestinationPolicy())->assertAllowed('+34600000000');
        $this->addToAssertionCount(1);
    }

    public function testAllowedCountryCodes(): void
    {
        $policy = new DestinationPolicy(['34', '1']);
        $policy->assertAllowed(Numbers::TO);

        $this->expectExceptionMessage('outside nowo_sms_kit.destinations.allowed_country_codes');
        $policy->assertAllowed('+447700900000');
    }

    public function testOverridingTheDeniedPrefixes(): void
    {
        (new DestinationPolicy([], []))->assertAllowed('+88234567890');

        $this->expectException(DestinationNotAllowedException::class);
        (new DestinationPolicy([], ['1555']))->assertAllowed(Numbers::TO);
    }

    public function testPerDestinationBudget(): void
    {
        $policy = new DestinationPolicy(perDestination: new RateLimiterFactory(['id' => 'd', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage()));
        $policy->assertAllowed(Numbers::TO);
        $policy->assertAllowed(Numbers::OTHER);

        $this->expectExceptionMessage('Send budget for +15*******23 exhausted');
        $policy->assertAllowed(Numbers::TO);
    }

    public function testGlobalBudget(): void
    {
        $policy = new DestinationPolicy(global: new RateLimiterFactory(['id' => 'g', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage()));
        $policy->assertAllowed(Numbers::TO);

        $this->expectExceptionMessage('Global SMS send budget exhausted');
        $policy->assertAllowed(Numbers::OTHER);
    }

    public function testIsDigits(): void
    {
        self::assertTrue(DestinationPolicy::isDigits('882'));
        self::assertFalse(DestinationPolicy::isDigits('+882'));
        self::assertFalse(DestinationPolicy::isDigits("34\n"));
    }
}
