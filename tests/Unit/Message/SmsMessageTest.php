<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Message;

use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use PHPUnit\Framework\TestCase;

final class SmsMessageTest extends TestCase
{
    public function testWithNormalizedKeepsTheOverrides(): void
    {
        $message = new SmsMessage(' ' . Numbers::TO, ' hi ', null, 'device-1', 'client-1', 'https://example.test/cb');

        $normalized = $message->withNormalized(Numbers::TO, 'hi', 'Acme');

        self::assertSame([Numbers::TO, 'hi', 'Acme', 'device-1', 'client-1', 'https://example.test/cb'], [
            $normalized->to, $normalized->body, $normalized->from, $normalized->deviceId, $normalized->clientMessageId, $normalized->statusCallbackUrl,
        ]);
    }

    public function testResultDefaults(): void
    {
        $result = new SmsSendResult('log', 'id', 'logged');

        self::assertSame([], $result->raw);
    }
}
