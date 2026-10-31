<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\AuthKit;

use Nowo\SmsKitBundle\AuthKit\PropertyPhoneNumberResolver;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PropertyPhoneNumberResolverTest extends TestCase
{
    public function testReadsGetterMethodOrPublicProperty(): void
    {
        $getter = new class {
            public function getPhone(): string
            {
                return ' +1 555 0100 123 ';
            }
        };
        $method = new class {
            public function mobile(): string
            {
                return Numbers::OTHER;
            }
        };
        $property        = new stdClass();
        $property->phone = Numbers::TO;

        self::assertSame(Numbers::TO, (new PropertyPhoneNumberResolver())->resolve($getter));
        self::assertSame(Numbers::OTHER, (new PropertyPhoneNumberResolver('mobile'))->resolve($method));
        self::assertSame(Numbers::TO, (new PropertyPhoneNumberResolver())->resolve($property));
    }

    public function testIgnoresMissingOrInvalidNumbers(): void
    {
        $invalid          = new stdClass();
        $invalid->phone   = '555-0100';
        $nonString        = new stdClass();
        $nonString->phone = 5550100;
        $unset            = new class {
            public ?string $phone = null;
        };

        self::assertNull((new PropertyPhoneNumberResolver())->resolve(new stdClass()));
        self::assertNull((new PropertyPhoneNumberResolver())->resolve($invalid));
        self::assertNull((new PropertyPhoneNumberResolver())->resolve($nonString));
        self::assertNull((new PropertyPhoneNumberResolver())->resolve($unset));
    }
}
