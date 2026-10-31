<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\SmsKitBundle\AuthKit\PhoneNumberResolverInterface;
use Nowo\SmsKitBundle\AuthKit\PropertyPhoneNumberResolver;
use Nowo\SmsKitBundle\AuthKit\SmsPasswordResetNotifier;
use Nowo\SmsKitBundle\Command\SendSmsCommand;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\DependencyInjection\NowoSmsKitExtension;
use Nowo\SmsKitBundle\Http\HttpPolicy;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use Nowo\SmsKitBundle\Provider\LogSmsProvider;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsBridgeProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderInterface;
use Nowo\SmsKitBundle\Provider\TwilioProvider;
use Nowo\SmsKitBundle\Routing\SmsKitRouteLoader;
use Nowo\SmsKitBundle\Sender\ConfiguredSmsSender;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Settings\SmsSettingsSection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoSmsKitExtensionTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config = [], ?NowoSmsKitExtension $extension = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        ($extension ?? new NowoSmsKitExtension())->load([$config], $container);

        return $container;
    }

    public function testRegistersTheDefaultServices(): void
    {
        $container = $this->load();

        self::assertSame('nowo_sms_kit', (new NowoSmsKitExtension())->getAlias());
        foreach ([NullSmsProvider::class => 100, LogSmsProvider::class => 90, TwilioProvider::class => 80, SmsBridgeProvider::class => 70] as $id => $priority) {
            self::assertSame([['priority' => $priority]], $container->getDefinition($id)->getTag(NowoSmsKitBundle::PROVIDER_TAG));
        }
        self::assertTrue($container->getAlias(SmsSenderInterface::class)->isPublic());
        self::assertSame(ConfiguredSmsSender::class, (string) $container->getAlias(SmsSenderInterface::class));
        self::assertTrue($container->getDefinition(SmsSettingsSection::class)->hasTag(NowoInstanceSettingsBundle::SECTION_TAG));
        self::assertTrue($container->getDefinition(SmsSettingsSection::class)->getArgument(3));
        self::assertSame([['command' => 'nowo:sms:send']], $container->getDefinition(SendSmsCommand::class)->getTag('console.command'));
        self::assertTrue($container->getDefinition(SmsConfigResolver::class)->hasTag('kernel.reset'));
        self::assertTrue($container->getDefinition(SmsKitRouteLoader::class)->hasTag('routing.loader'));
        self::assertTrue($container->hasDefinition(NowoSmsKitExtension::TEST_RATE_LIMITER));
        self::assertFalse($container->getDefinition(SendTestSmsController::class)->isAutoconfigured());
        self::assertTrue($container->getDefinition(SendTestSmsController::class)->hasTag('controller.service_arguments'));
        self::assertSame([5.0, 15.0, false, true, true], $container->getDefinition(HttpPolicy::class)->getArguments());
        self::assertSame('null', $container->getDefinition(SmsFallbacks::class)->getArgument(0));
        self::assertTrue($container->getParameter('nowo_sms_kit.test_action.enabled'));
        self::assertSame('/admin/settings/sms/test', $container->getParameter('nowo_sms_kit.test_action.path'));
        self::assertArrayHasKey(SmsProviderInterface::class, $container->getAutoconfiguredInstanceof());
        self::assertFalse($container->hasDefinition(SmsPasswordResetNotifier::class));
    }

    public function testOptionalProvidersAndTestActionCanBeDisabled(): void
    {
        $container = $this->load([
            'providers'   => ['log' => ['enabled' => false], 'twilio' => ['enabled' => false], 'sms_bridge' => ['enabled' => false]],
            'test_action' => ['enabled' => false],
        ]);

        self::assertTrue($container->hasDefinition(NullSmsProvider::class));
        self::assertFalse($container->hasDefinition(LogSmsProvider::class));
        self::assertFalse($container->hasDefinition(TwilioProvider::class));
        self::assertFalse($container->hasDefinition(SmsBridgeProvider::class));
        self::assertFalse($container->hasDefinition(SendTestSmsController::class));
        self::assertFalse($container->hasDefinition(NowoSmsKitExtension::TEST_RATE_LIMITER));
        self::assertFalse($container->getDefinition(SmsKitRouteLoader::class)->getArgument(0));
        self::assertFalse($container->getDefinition(SmsSettingsSection::class)->getArgument(3));
    }

    public function testAuthKitAdapterDecoratesTheNotifier(): void
    {
        $container = $this->load(['auth_kit' => ['password_reset' => ['enabled' => true, 'phone_property' => 'mobile']]]);

        $definition = $container->getDefinition(SmsPasswordResetNotifier::class);
        self::assertSame([PasswordResetNotifierInterface::class, null, 0], $definition->getDecoratedService());
        self::assertSame('.inner', (string) $definition->getArgument(0));
        self::assertSame(PropertyPhoneNumberResolver::class, (string) $container->getAlias(PhoneNumberResolverInterface::class));
        self::assertSame('mobile', $container->getDefinition(PropertyPhoneNumberResolver::class)->getArgument(0));
    }

    public function testAuthKitAdapterWithACustomPhoneResolver(): void
    {
        $container = $this->load(['auth_kit' => ['password_reset' => ['enabled' => true, 'phone_resolver' => 'app.phone_resolver']]]);

        self::assertSame('app.phone_resolver', (string) $container->getAlias(PhoneNumberResolverInterface::class));
    }

    public function testAuthKitAdapterNeedsAuthKit(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires nowo-tech/auth-kit-bundle');
        $this->load(['auth_kit' => ['password_reset' => ['enabled' => true]]], new NowoSmsKitExtension('App\\Missing\\NotifierInterface'));
    }
}
