<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SmsKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function count;

/**
 * Boots a fresh {@see SmsKitTestKernel} per call (own cache dir, schema created on the in-memory database).
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<SmsKitTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(SmsKitTestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config nowo_sms_kit configuration
     * @param array<string, mixed> $settingsConfig nowo_instance_settings configuration
     * @param Closure(RoutingConfigurator): void|null $routes routes (default: both bundle loaders)
     * @param list<string> $without bundles to leave out
     * @param array<string, string> $parameters container parameters
     */
    protected function bootKernel(array $config = [], array $settingsConfig = [], ?Closure $routes = null, array $without = [], array $parameters = []): SmsKitTestKernel
    {
        $id     = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel = new SmsKitTestKernel(
            $id,
            static function (ContainerConfigurator $container) use ($config, $settingsConfig, $parameters): void {
                foreach ($parameters as $name => $value) {
                    $container->parameters()->set($name, $value);
                }
                $container->extension('nowo_instance_settings', $settingsConfig);
                $container->extension('nowo_sms_kit', $config);
                $container->services()->set('logger', RecordingLogger::class)->public();
            },
            $routes ?? static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->import('.', 'nowo_sms_kit');
            },
            $without,
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $kernel;
    }

    protected function container(SmsKitTestKernel $kernel): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    /**
     * Browser that keeps the session (CSRF tokens) and the in-memory database between requests.
     */
    protected function client(SmsKitTestKernel $kernel, ?string $user = 'admin'): KernelBrowser
    {
        /** @var KernelBrowser $client */
        $client = $kernel->getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(true);
        $client->setServerParameters($user === null ? ['REMOTE_ADDR' => '203.0.113.9'] : ['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);

        return $client;
    }

    protected function settings(SmsKitTestKernel $kernel): InstanceSettingsInterface
    {
        $settings = $this->container($kernel)->get(InstanceSettingsInterface::class);
        self::assertInstanceOf(InstanceSettingsInterface::class, $settings);

        return $settings;
    }

    protected function logger(SmsKitTestKernel $kernel): RecordingLogger
    {
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }
}
