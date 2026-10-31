<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Kernel;

use LogicException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Fail-closed container checks (REQ-SF-006 / REQ-UI-002 / REQ-SEC-005).
 */
final class InvalidConfigurationTest extends KernelTestCase
{
    public function testTestActionNeedsTheSettingsAdminUi(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs the instance settings admin UI');
        $this->bootKernel(settingsConfig: ['web_ui' => ['enabled' => false]], routes: static function (): void {});
    }

    public function testDisabledTestActionBootsWithoutTheAdminUi(): void
    {
        $kernel = $this->bootKernel(['test_action' => ['enabled' => false]], ['web_ui' => ['enabled' => false]], static function (): void {});

        self::assertFalse($this->container($kernel)->has('Nowo\SmsKitBundle\Controller\SendTestSmsController'));
    }

    public function testEnvPlaceholderSwitchIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a literal boolean');
        $this->bootKernel(['http' => ['allow_private_endpoints' => '%env(bool:SMS_PRIVATE)%']]);
    }
}
