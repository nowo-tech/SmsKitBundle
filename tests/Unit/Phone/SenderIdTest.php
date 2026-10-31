<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Phone;

use Nowo\SmsKitBundle\Exception\InvalidPhoneNumberException;
use Nowo\SmsKitBundle\Phone\SenderId;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function preg_match;

final class SenderIdTest extends TestCase
{
    public function testNormalizesNumbersAndAlphanumericIds(): void
    {
        self::assertSame(Numbers::TO, SenderId::normalize(' +1 555 0100 123 '));
        self::assertSame('Acme Ops', SenderId::normalize(' Acme Ops '));
        self::assertTrue(SenderId::isNumber(Numbers::TO));
        self::assertFalse(SenderId::isNumber('Acme'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSenders(): iterable
    {
        yield 'digits only' => ['12345'];
        yield 'too long' => ['AcmeOperations'];
        yield 'symbols' => ['Acme!'];
        yield 'bad number' => ['+0123'];
    }

    #[DataProvider('invalidSenders')]
    public function testRejectsInvalidSenders(string $sender): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        SenderId::normalize($sender);
    }

    public function testSettingPatternAcceptsBothShapes(): void
    {
        self::assertSame(1, preg_match(SenderId::PATTERN, Numbers::TO));
        self::assertSame(1, preg_match(SenderId::PATTERN, 'Acme'));
        self::assertSame(0, preg_match(SenderId::PATTERN, '12345'));
    }
}
