<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Ddd;

use LogicException;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Nowo\SmsKitBundle\Settings\FallbackSmsConfigProvider;
use Nowo\SmsKitBundle\Settings\SmsConfigProviderInterface;
use Nowo\SmsKitBundle\Settings\SmsSettingsSection;
use Nowo\SmsKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SmsKitBundle\Tests\Integration\Kernel\SmsKitTestKernel;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function array_keys;
use function getenv;
use function interface_exists;
use function str_starts_with;

/**
 * 2.0 (REQ-ARCH-001 rule 8): nowo-tech/instance-settings-bundle is only suggested. A host on the default setup that
 * loses it (it came only through this package; Flex removes its bundle line) keeps its SmsKit configuration:
 *
 * - with nothing that names a provider source, the container build fails and says what to install (Q7), instead of
 *   silently dropping every SMS (AuthKit reset codes included);
 * - with `default_provider`, SMS is sent through the bundle configuration, and there is no Instance Settings service,
 *   no `sms` section and no send-test route;
 * - an unconfigured provider fails the send loudly.
 *
 * The CI job "Without Instance Settings" removes the package and sets SMS_KIT_EXPECT_NO_INSTANCE_SETTINGS=1, so this
 * test then also proves the package is really absent.
 */
final class DefaultConfigWithoutInstanceSettingsTest extends KernelTestCase
{
    private const INSTANCE_SETTINGS_INTERFACE = 'Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface';

    protected function setUp(): void
    {
        if (getenv('SMS_KIT_EXPECT_NO_INSTANCE_SETTINGS') === '1') {
            self::assertFalse(interface_exists(self::INSTANCE_SETTINGS_INTERFACE), 'nowo-tech/instance-settings-bundle must not be installed in the "Without Instance Settings" job.');
        }
    }

    public function testTheDefaultConfigurationWithoutAProviderSourceFailsTheBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('composer require nowo-tech/instance-settings-bundle');

        $this->bootWithout([]);
    }

    public function testTheDefaultConfigurationWithADefaultProviderSendsThroughTheBundleConfiguration(): void
    {
        $kernel    = $this->bootWithout(['default_provider' => 'log', 'sender' => 'Acme']);
        $container = $this->container($kernel);

        self::assertFalse($container->has(self::INSTANCE_SETTINGS_INTERFACE));
        self::assertFalse($container->has(SmsSettingsSection::class));
        self::assertInstanceOf(FallbackSmsConfigProvider::class, $container->get(SmsConfigProviderInterface::class));

        $sender = $container->get(SmsSenderInterface::class);
        self::assertInstanceOf(SmsSenderInterface::class, $sender);
        self::assertSame('logged', $sender->send(new SmsMessage(Numbers::TO, 'hello'))->status);

        $router = $container->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach (array_keys($router->getRouteCollection()->all()) as $name) {
            self::assertFalse(str_starts_with($name, 'nowo_sms_kit'), $name);
        }
    }

    public function testAnUnconfiguredProviderFailsTheSendLoudly(): void
    {
        $kernel = $this->bootWithout(['default_provider' => 'twilio']);
        $sender = $this->container($kernel)->get(SmsSenderInterface::class);
        self::assertInstanceOf(SmsSenderInterface::class, $sender);
        self::assertFalse($sender->isConfigured());

        $this->expectException(SmsSendException::class);
        $this->expectExceptionMessage('SMS provider "twilio" is selected but not fully configured.');
        $sender->send(new SmsMessage(Numbers::TO, 'hello'));
    }

    /**
     * Default configuration (the recipe's routes import), no Instance Settings, no Doctrine.
     *
     * @param array<string, mixed> $config
     */
    private function bootWithout(array $config): SmsKitTestKernel
    {
        return $this->bootKernel(
            $config,
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_sms_kit');
            },
            without: ['instance_settings', 'doctrine'],
        );
    }
}
