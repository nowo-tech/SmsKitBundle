<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\DependencyInjection;

use LogicException;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\SmsKitBundle\AuthKit\PhoneNumberResolverInterface;
use Nowo\SmsKitBundle\AuthKit\PropertyPhoneNumberResolver;
use Nowo\SmsKitBundle\AuthKit\SmsPasswordResetNotifier;
use Nowo\SmsKitBundle\Command\SendSmsCommand;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\Http\HttpPolicy;
use Nowo\SmsKitBundle\Http\ProviderHttpClient;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use Nowo\SmsKitBundle\Provider\LogSmsProvider;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsBridgeProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderInterface;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Provider\TwilioProvider;
use Nowo\SmsKitBundle\Routing\SmsKitRouteLoader;
use Nowo\SmsKitBundle\Sender\ConfiguredSmsSender;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Settings\SmsSettingsSection;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Loads `nowo_sms_kit`: providers and their registry, the guarded HTTP client, the configuration resolver, the sender,
 * the `sms` settings section, the CLI, and (when enabled) the send-test action and the AuthKit adapter.
 */
final class NowoSmsKitExtension extends Extension
{
    /** Service id of the rate limiter factory of the send-test action. */
    public const TEST_RATE_LIMITER = 'nowo_sms_kit.send_test.rate_limiter';

    /**
     * @param string $authKitNotifierInterface AuthKit notifier interface the adapter decorates (overridable in tests)
     */
    public function __construct(
        private readonly string $authKitNotifierInterface = PasswordResetNotifierInterface::class,
    ) {
    }

    /**
     * `nowo_sms_kit`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Registers the services from the processed configuration.
     *
     * @param array<array<string, mixed>> $configs raw configuration blocks
     * @param ContainerBuilder $container container being built
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertLiteralBooleans($config);

        /* @var array{default_provider: string, sender: string|null, http: array{timeout: float, max_duration: float, allow_private_endpoints: bool, resolve_dns: bool, require_https: bool}, providers: array{log: array{enabled: bool}, twilio: array{enabled: bool, api_base_url: string, account_sid: string|null, auth_token: string|null, messaging_service_sid: string|null}, sms_bridge: array{enabled: bool, base_url: string|null, api_mode: string, account_sid: string|null, auth_token: string|null, device_id: string|null}}, test_action: array{enabled: bool, path: string, rate_limit: array{limit: int, interval: string}}, auth_kit: array{password_reset: array{enabled: bool, phone_property: string, phone_resolver: string|null}}} $config */
        $container->setParameter('nowo_sms_kit.test_action.enabled', $config['test_action']['enabled']);
        $container->setParameter('nowo_sms_kit.test_action.path', $config['test_action']['path']);

        $container->registerForAutoconfiguration(SmsProviderInterface::class)->addTag(NowoSmsKitBundle::PROVIDER_TAG);

        $this->registerProviders($container, $config['http'], $config['providers']);
        $this->registerSender($container, $config['default_provider'], $config['sender'], $config['providers'], $config['test_action']['enabled']);
        $this->registerTestAction($container, $config['test_action']);
        $this->registerAuthKit($container, $config['auth_kit']['password_reset']);
    }

    /**
     * @param array{timeout: float, max_duration: float, allow_private_endpoints: bool, resolve_dns: bool, require_https: bool} $http
     * @param array{log: array{enabled: bool}, twilio: array{enabled: bool}, sms_bridge: array{enabled: bool}} $providers
     */
    private function registerProviders(ContainerBuilder $container, array $http, array $providers): void
    {
        $container->setDefinition(HttpPolicy::class, new Definition(HttpPolicy::class, [
            $http['timeout'],
            $http['max_duration'],
            $http['allow_private_endpoints'],
            $http['resolve_dns'],
            $http['require_https'],
        ]));
        $container->setDefinition(ProviderHttpClient::class, new Definition(ProviderHttpClient::class, [
            new Reference('http_client'),
            new Reference(OutboundUrlGuard::class),
            new Reference(HttpPolicy::class),
        ]));

        // Tag priority = order of the provider choices in the settings form.
        $container->setDefinition(NullSmsProvider::class, (new Definition(NullSmsProvider::class))
            ->addTag(NowoSmsKitBundle::PROVIDER_TAG, ['priority' => 100]));
        if ($providers['log']['enabled']) {
            $container->setDefinition(LogSmsProvider::class, (new Definition(LogSmsProvider::class, [
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]))->addTag(NowoSmsKitBundle::PROVIDER_TAG, ['priority' => 90])->addTag('monolog.logger', ['channel' => 'sms']));
        }
        if ($providers['twilio']['enabled']) {
            $container->setDefinition(TwilioProvider::class, (new Definition(TwilioProvider::class, [new Reference(ProviderHttpClient::class)]))
                ->addTag(NowoSmsKitBundle::PROVIDER_TAG, ['priority' => 80]));
        }
        if ($providers['sms_bridge']['enabled']) {
            $container->setDefinition(SmsBridgeProvider::class, (new Definition(SmsBridgeProvider::class, [new Reference(ProviderHttpClient::class)]))
                ->addTag(NowoSmsKitBundle::PROVIDER_TAG, ['priority' => 70]));
        }

        $container->setDefinition(SmsProviderRegistry::class, new Definition(SmsProviderRegistry::class, [
            new TaggedIteratorArgument(NowoSmsKitBundle::PROVIDER_TAG),
        ]));
    }

    /**
     * @param string $defaultProvider `default_provider`
     * @param string|null $sender `sender`
     * @param array{twilio: array{api_base_url: string, account_sid: string|null, auth_token: string|null, messaging_service_sid: string|null}, sms_bridge: array{base_url: string|null, api_mode: string, account_sid: string|null, auth_token: string|null, device_id: string|null}} $providers `providers`
     * @param bool $testAction `test_action.enabled`
     */
    private function registerSender(ContainerBuilder $container, string $defaultProvider, ?string $sender, array $providers, bool $testAction): void
    {
        $twilio = $providers['twilio'];
        $bridge = $providers['sms_bridge'];

        $container->setDefinition(SmsFallbacks::class, new Definition(SmsFallbacks::class, [
            $defaultProvider,
            $sender,
            $twilio['account_sid'],
            $twilio['auth_token'],
            $twilio['messaging_service_sid'],
            $twilio['api_base_url'],
            $bridge['base_url'],
            $bridge['api_mode'],
            $bridge['account_sid'],
            $bridge['auth_token'],
            $bridge['device_id'],
        ]));

        $container->setDefinition(SmsConfigResolver::class, (new Definition(SmsConfigResolver::class, [
            new Reference(InstanceSettingsInterface::class),
            new Reference(SmsFallbacks::class),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->addTag('kernel.reset', ['method' => 'reset'])
            ->addTag('kernel.event_listener', ['event' => SettingsChangedEvent::class, 'method' => 'onSettingsChanged']));

        $container->setDefinition(ConfiguredSmsSender::class, (new Definition(ConfiguredSmsSender::class, [
            new Reference(SmsProviderRegistry::class),
            new Reference(SmsConfigResolver::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('monolog.logger', ['channel' => 'sms']));
        $container->setAlias(SmsSenderInterface::class, ConfiguredSmsSender::class)->setPublic(true);

        $container->setDefinition(SmsSettingsSection::class, (new Definition(SmsSettingsSection::class, [
            new Reference(SmsProviderRegistry::class),
            new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(CsrfOnlyFormFactory::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $testAction,
        ]))->addTag(NowoInstanceSettingsBundle::SECTION_TAG));

        $container->setDefinition(SendSmsCommand::class, (new Definition(SendSmsCommand::class, [
            new Reference(SmsSenderInterface::class),
        ]))->addTag('console.command', ['command' => SendSmsCommand::NAME]));
    }

    /**
     * @param array{enabled: bool, path: string, rate_limit: array{limit: int, interval: string}} $testAction
     */
    private function registerTestAction(ContainerBuilder $container, array $testAction): void
    {
        $container->setDefinition(SmsKitRouteLoader::class, (new Definition(SmsKitRouteLoader::class, [
            $testAction['enabled'],
            $testAction['path'],
        ]))->addTag('routing.loader'));

        if (!$testAction['enabled']) {
            return;
        }

        $container->setDefinition(self::TEST_RATE_LIMITER . '.storage', new Definition(CacheStorage::class, [new Reference('cache.app')]));
        $container->setDefinition(self::TEST_RATE_LIMITER, new Definition(RateLimiterFactory::class, [
            [
                'id'       => 'nowo_sms_kit_send_test',
                'policy'   => 'sliding_window',
                'limit'    => $testAction['rate_limit']['limit'],
                'interval' => $testAction['rate_limit']['interval'],
            ],
            new Reference(self::TEST_RATE_LIMITER . '.storage'),
        ]));

        // REQ-SF-004 rule 7: not autoconfigured (no `routing.controller` tag from #[Route]); the route comes only from
        // the `nowo_sms_kit` loader.
        $container->setDefinition(SendTestSmsController::class, (new Definition(SendTestSmsController::class, [
            new Reference(InstanceSettingsAccessCheckerInterface::class),
            new Reference(CsrfOnlyFormFactory::class),
            new Reference(SmsSenderInterface::class),
            new Reference(SmsConfigResolver::class),
            new Reference(self::TEST_RATE_LIMITER),
            new Reference('router'),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->addTag('controller.service_arguments'));
    }

    /**
     * @param array{enabled: bool, phone_property: string, phone_resolver: string|null} $passwordReset
     */
    private function registerAuthKit(ContainerBuilder $container, array $passwordReset): void
    {
        if (!$passwordReset['enabled']) {
            return;
        }
        if (!interface_exists($this->authKitNotifierInterface)) {
            throw new LogicException('NowoSmsKitBundle: nowo_sms_kit.auth_kit.password_reset.enabled requires nowo-tech/auth-kit-bundle (composer require nowo-tech/auth-kit-bundle).');
        }

        $container->setDefinition(PropertyPhoneNumberResolver::class, new Definition(PropertyPhoneNumberResolver::class, [$passwordReset['phone_property']]));
        $resolver = $passwordReset['phone_resolver'];
        $container->setAlias(PhoneNumberResolverInterface::class, is_string($resolver) && $resolver !== '' ? $resolver : PropertyPhoneNumberResolver::class);

        $container->setDefinition(SmsPasswordResetNotifier::class, (new Definition(SmsPasswordResetNotifier::class, [
            new Reference('.inner'),
            new Reference(SmsSenderInterface::class),
            new Reference(PhoneNumberResolverInterface::class),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setDecoratedService($this->authKitNotifierInterface));
    }

    /**
     * Security switches must be literal booleans: an `%env(bool:…)%` placeholder is a non-empty string while the
     * container is built and would read as true (fail open).
     *
     * @param array<string, mixed> $config processed configuration
     */
    private function assertLiteralBooleans(array $config): void
    {
        /** @var array<string, array<string, mixed>> $config */
        $passwordReset = $config['auth_kit']['password_reset'] ?? null;
        $switches      = [
            'http.allow_private_endpoints'    => $config['http']['allow_private_endpoints'] ?? null,
            'http.require_https'              => $config['http']['require_https'] ?? null,
            'http.resolve_dns'                => $config['http']['resolve_dns'] ?? null,
            'test_action.enabled'             => $config['test_action']['enabled'] ?? null,
            'auth_kit.password_reset.enabled' => is_array($passwordReset) ? ($passwordReset['enabled'] ?? null) : null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_sms_kit.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for security switches.', $path));
            }
        }
    }
}
