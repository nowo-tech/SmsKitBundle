<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Provider;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Provider\LogSmsProvider;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Settings\SmsConfig;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class NullAndLogProviderTest extends TestCase
{
    public function testNullProviderNeverSends(): void
    {
        $provider = new NullSmsProvider();

        self::assertSame('null', $provider->getName());
        self::assertSame('settings.sms.provider_null', $provider->getLabel());
        self::assertFalse($provider->isConfigured(new SmsConfig('null')));

        $this->expectException(SmsSendException::class);
        $provider->send(new SmsMessage(Numbers::TO, 'hi'), new SmsConfig('null'));
    }

    public function testLogProviderLogsMaskedMetadataOnly(): void
    {
        $logger   = new RecordingLogger();
        $provider = new LogSmsProvider($logger);

        self::assertSame('log', $provider->getName());
        self::assertSame('settings.sms.provider_log', $provider->getLabel());
        self::assertTrue($provider->isConfigured(new SmsConfig('log')));

        $result = $provider->send(new SmsMessage(Numbers::TO, 'Your code is 123456', Numbers::OTHER), new SmsConfig('log'));
        $provider->send(new SmsMessage(Numbers::TO, 'hi', 'Acme'), new SmsConfig('log'));
        $provider->send(new SmsMessage(Numbers::TO, 'hi'), new SmsConfig('log'));

        self::assertSame('log', $result->providerId);
        self::assertSame('logged', $result->status);
        self::assertStringStartsWith('log-', $result->providerMessageId);
        self::assertCount(3, $logger->records);
        $context = $logger->records[0]['context'];
        self::assertSame('+15*******23', $context['to']);
        self::assertSame('+15*******99', $context['from']);
        self::assertSame(19, $context['body_length']);
        self::assertSame('Acme', $logger->records[1]['context']['from']);
        self::assertNull($logger->records[2]['context']['from']);
        self::assertStringNotContainsString('123456', (string) json_encode($logger->records));
        self::assertTrue((new LogSmsProvider())->isConfigured(new SmsConfig('log')));
    }
}
