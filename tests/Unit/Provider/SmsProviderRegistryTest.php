<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Provider;

use InvalidArgumentException;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use PHPUnit\Framework\TestCase;

final class SmsProviderRegistryTest extends TestCase
{
    public function testLooksUpByName(): void
    {
        $null     = new NullSmsProvider();
        $stub     = new StubProvider('acme_gateway');
        $registry = new SmsProviderRegistry([$null, $stub]);

        self::assertTrue($registry->has('acme_gateway'));
        self::assertFalse($registry->has('missing'));
        self::assertSame($stub, $registry->get('acme_gateway'));
        self::assertNull($registry->get('missing'));
        self::assertSame(['null' => $null, 'acme_gateway' => $stub], $registry->all());
    }

    public function testRejectsInvalidNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lower snake case');
        new SmsProviderRegistry([new StubProvider('Acme-Gateway')]);
    }

    public function testRejectsDuplicateNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Two SMS providers are named "stub"');
        new SmsProviderRegistry([new StubProvider(), new StubProvider()]);
    }
}
