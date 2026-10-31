<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit;

use Nowo\SmsKitBundle\DependencyInjection\Compiler\OutboundUrlGuardPass;
use Nowo\SmsKitBundle\DependencyInjection\Compiler\TestActionPass;
use Nowo\SmsKitBundle\DependencyInjection\NowoSmsKitExtension;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_map;

final class NowoSmsKitBundleTest extends TestCase
{
    public function testExtensionAndPasses(): void
    {
        $bundle    = new NowoSmsKitBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoSmsKitExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());

        $container = new ContainerBuilder();
        $bundle->build($container);
        $classes = array_map(static fn (object $pass): string => $pass::class, $container->getCompilerPassConfig()->getBeforeOptimizationPasses());
        self::assertContains(OutboundUrlGuardPass::class, $classes);
        self::assertContains(TestActionPass::class, $classes);
    }
}
