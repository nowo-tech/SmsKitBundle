<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Kernel;

use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Fail-closed container checks (REQ-SF-006 / REQ-UI-002 / REQ-SEC-005).
 */
final class InvalidConfigurationTest extends KernelTestCase
{
    public function testTestActionWorksWithoutTheSettingsAdminUi(): void
    {
        $kernel = $this->bootKernel(settingsConfig: ['web_ui' => ['enabled' => false]], routes: static function (RoutingConfigurator $routes): void {
            $routes->import('.', 'nowo_sms_kit');
        });

        self::assertTrue($this->container($kernel)->has(SendTestSmsController::class));
    }

    public function testDisabledTestActionBootsWithoutTheAdminUi(): void
    {
        $kernel = $this->bootKernel(['test_action' => ['enabled' => false]], ['web_ui' => ['enabled' => false]], static function (): void {});

        self::assertFalse($this->container($kernel)->has(SendTestSmsController::class));
    }

    public function testEnvPlaceholderSwitchIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a literal boolean');
        $this->bootKernel(['http' => ['allow_private_endpoints' => '%env(bool:SMS_PRIVATE)%']]);
    }
}
