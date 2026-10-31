<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Routing;

use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\Routing\SmsKitRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmsKitRouteLoaderTest extends TestCase
{
    public function testMountsTheActionAtThePath(): void
    {
        $loader = new SmsKitRouteLoader(true, '/ops/sms/test/');

        self::assertTrue($loader->supports('.', 'nowo_sms_kit'));
        self::assertFalse($loader->supports('.', 'attribute'));

        $route = $loader->load('.', 'nowo_sms_kit')->get(SendTestSmsController::ROUTE);
        self::assertNotNull($route);
        self::assertSame('/ops/sms/test', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame(SendTestSmsController::class, $route->getDefault('_controller'));
    }

    public function testDisabledGivesNoRoute(): void
    {
        self::assertCount(0, (new SmsKitRouteLoader(false, '/x'))->load('.'));
    }

    public function testRefusesASecondLoad(): void
    {
        $loader = new SmsKitRouteLoader(true, '/x');
        $loader->load('.');

        $this->expectException(RuntimeException::class);
        $loader->load('.');
    }
}
