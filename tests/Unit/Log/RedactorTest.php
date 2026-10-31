<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Log;

use Nowo\SmsKitBundle\Log\Redactor;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class RedactorTest extends TestCase
{
    public function testMasksNumbersSidsAndClientKeys(): void
    {
        $text = 'The To number ' . Numbers::TO . ' (or +1 555 0100 199) is invalid for ' . Numbers::accountSid() . ' with key ' . Numbers::bridgeKey() . '.';

        $redacted = Redactor::redact($text);

        self::assertStringNotContainsString(Numbers::TO, $redacted);
        self::assertStringNotContainsString('0100 199', $redacted);
        self::assertStringNotContainsString(Numbers::accountSid(), $redacted);
        self::assertStringNotContainsString(Numbers::bridgeKey(), $redacted);
        self::assertStringContainsString('+15*******23', $redacted);
        self::assertStringContainsString('AC' . str_repeat('*', 28) . '0000', $redacted);
        self::assertStringContainsString('smscli_' . str_repeat('*', 16), $redacted);
    }

    public function testLeavesOtherTextAlone(): void
    {
        self::assertSame('HTTP 400: missing Body', Redactor::redact('HTTP 400: missing Body'));
    }
}
