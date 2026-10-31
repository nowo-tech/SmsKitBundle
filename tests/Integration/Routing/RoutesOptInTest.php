<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Routing;

use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function is_string;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default config/routes.yaml) gets
 * no bundle route; the send-test route exists only through the `nowo_sms_kit` loader import.
 */
final class RoutesOptInTest extends KernelTestCase
{
    public function testRoutingControllersDoesNotMountTheBundleRoute(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }

        $kernel = $this->bootKernel(routes: static function (RoutingConfigurator $routes): void {
            $routes->import('.', 'nowo_instance_settings');
            $routes->import('routing.controllers');
        });
        self::assertTrue($this->container($kernel)->has(SendTestSmsController::class));

        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertStringStartsNotWith('nowo_sms_kit', $name);
            $controller = $route->getDefault('_controller');
            self::assertFalse(is_string($controller) && str_starts_with($controller, SendTestSmsController::class), $name);
        }
    }

    public function testTheLoaderImportMountsItAtThePath(): void
    {
        $kernel = $this->bootKernel(['test_action' => ['path' => '/admin/settings/sms/send-test']]);
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');

        self::assertSame('/admin/settings/sms/send-test', $router->getRouteCollection()->get(SendTestSmsController::ROUTE)?->getPath());
    }
}
