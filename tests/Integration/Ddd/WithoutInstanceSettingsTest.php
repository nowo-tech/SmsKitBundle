<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Ddd;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\DependencyInjection\NowoSmsKitExtension;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Nowo\SmsKitBundle\Settings\FallbackSmsConfigProvider;
use Nowo\SmsKitBundle\Settings\SmsConfigProviderInterface;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsConfigStatus;
use Nowo\SmsKitBundle\Settings\SmsConfigStatusInterface;
use Nowo\SmsKitBundle\Settings\SmsSettingsSection;
use Nowo\SmsKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SmsKitBundle\Tests\Integration\Kernel\SmsKitTestKernel;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\RequestTenantSmsConfigProvider;
use Nowo\SmsKitBundle\Tests\Support\SendSmsController;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function array_keys;
use function gc_collect_cycles;
use function memory_get_usage;
use function str_starts_with;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.5 (architecture bar rule 8): SmsKit boots without Instance Settings and without Doctrine — configuration from the
 * bundle configuration by default, or from a host `config_provider` — and serves many requests in one kept-alive
 * kernel (FrankenPHP worker mode, kernel reset off: `http_kernel` handle / terminate, no services resetter).
 */
final class WithoutInstanceSettingsTest extends KernelTestCase
{
    public function testBootsWithoutInstanceSettingsOnTheBundleConfiguration(): void
    {
        $kernel    = $this->bootWithout(['default_provider' => 'log', 'sender' => 'Acme']);
        $container = $this->container($kernel);

        self::assertFalse($container->has('doctrine'));
        self::assertFalse($container->has(InstanceSettingsInterface::class));
        self::assertFalse($container->has(SmsConfigResolver::class));
        self::assertFalse($container->has(SmsSettingsSection::class));
        self::assertFalse($container->has(SendTestSmsController::class));
        self::assertFalse($container->getParameter(NowoSmsKitExtension::INSTANCE_SETTINGS_PARAMETER));
        self::assertFalse($container->getParameter('nowo_sms_kit.test_action.enabled'));
        self::assertInstanceOf(FallbackSmsConfigProvider::class, $container->get(SmsConfigProviderInterface::class));

        $sender = $container->get(SmsSenderInterface::class);
        self::assertInstanceOf(SmsSenderInterface::class, $sender);
        self::assertSame('log', $sender->getProviderId());
        self::assertSame('logged', $sender->send(new SmsMessage(Numbers::TO, 'hello'))->status);

        $status = $container->get(SmsConfigStatusInterface::class);
        self::assertInstanceOf(SmsConfigStatus::class, $status);
        self::assertSame('log', $status->provider());
        self::assertFalse($status->isProviderUnset());
        self::assertFalse($status->hasMissingFields());

        // test_action.enabled defaults to true, but the action lives on the Instance Settings page: no route.
        $router = $container->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach (array_keys($router->getRouteCollection()->all()) as $name) {
            self::assertFalse(str_starts_with($name, 'nowo_sms_kit'), $name);
        }
    }

    public function testKeptAliveKernelServesManyTenantsWithoutLeakingState(): void
    {
        $kernel = $this->bootWithout(['config_provider' => RequestTenantSmsConfigProvider::class]);
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $stub = $this->container($kernel)->get(StubProvider::class);
        self::assertInstanceOf(StubProvider::class, $stub);
        $logger = $this->logger($kernel);
        $before = 0;

        for ($i = 0; $i < 600; ++$i) {
            $tenant   = $i % 2 === 0 ? 'acme' : 'globex';
            $request  = Request::create('/sms/' . $tenant);
            $response = $http->handle($request);
            self::assertSame(200, $response->getStatusCode());
            $http->terminate($request, $response);

            self::assertCount(1, $stub->sent);
            self::assertSame($tenant === 'acme' ? 'Acme' : 'Globex', $stub->sent[0]->from);
            $stub->sent = [];
            // The test logger keeps every record; a real logger writes them out.
            $logger->records = [];

            if ($i === 99) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 500 requests.');

        // Outside a request again: nothing of the last tenant is left in a service.
        $sender = $this->container($kernel)->get(SmsSenderInterface::class);
        self::assertInstanceOf(SmsSenderInterface::class, $sender);
        $sender->send(new SmsMessage(Numbers::TO, 'hello'));
        self::assertCount(1, $stub->sent);
        self::assertSame('Cli', $stub->sent[0]->from);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function bootWithout(array $config): SmsKitTestKernel
    {
        return $this->bootKernel(
            $config,
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_sms_kit');
                $routes->add('host_sms', '/sms/{tenant}')->controller(SendSmsController::class);
            },
            without: ['instance_settings', 'doctrine'],
            extra: static function (ContainerConfigurator $container): void {
                $services = $container->services();
                $services->set(StubProvider::class)->public()->tag('nowo_sms_kit.provider');
                $services->set(RequestTenantSmsConfigProvider::class)->args([service('request_stack')]);
                $services->set(SendSmsController::class)->args([service(SmsSenderInterface::class)])->public()->tag('controller.service_arguments');
            },
        );
    }
}
