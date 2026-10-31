<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Phone;

use Nowo\SmsKitBundle\Exception\InvalidPhoneNumberException;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class E164Test extends TestCase
{
    public function testNormalizeRemovesWhitespace(): void
    {
        self::assertSame(Numbers::TO, E164::normalize(" +1 555 0100 123\t"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'no plus' => ['15550100123'];
        yield 'leading zero country code' => ['+05550100123'];
        yield 'too short' => ['+123456'];
        yield 'too long' => ['+1234567890123456'];
        yield 'dashes' => ['+1-555-0100-123'];
        yield 'letters' => ['+1555CALLNOW'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidNumbers')]
    public function testNormalizeRejectsInvalidNumbers(string $phone): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        E164::normalize($phone);
    }

    public function testIsValid(): void
    {
        self::assertTrue(E164::isValid('+1 555 0100 123'));
        self::assertFalse(E164::isValid('5550100123'));
        self::assertFalse(E164::isValid(null));
    }

    public function testMask(): void
    {
        self::assertSame('+15*******23', E164::mask(Numbers::TO));
        self::assertSame('+15*******23', E164::mask('+1 555 0100 123'));
        self::assertSame('*****', E164::mask('hello'));
        self::assertSame('', E164::mask(null));
        self::assertSame('', E164::mask('  '));
    }
}
