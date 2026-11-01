<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\DependencyInjection;

use Nowo\SmsKitBundle\Policy\DestinationPolicy;
use Nowo\SmsKitBundle\Settings\SmsConfig;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_filter;
use function is_array;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strtotime;

/**
 * Configuration tree of `nowo_sms_kit` (REQ-SF-006: strict nodes, safe defaults — provider `null`, HTTPS endpoints,
 * private endpoints refused, send-test action protected and rate limited, AuthKit adapter off).
 */
final class Configuration implements ConfigurationInterface
{
    /** Extension alias / root key. */
    public const ALIAS = 'nowo_sms_kit';

    /** Default path of the send-test action (inside the instance settings admin prefix). */
    public const DEFAULT_TEST_PATH = '/admin/settings/sms/test';

    /**
     * Builds the tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->info('Nowo SMS Kit — provider registry, runtime configuration in the "sms" instance settings section, test command and action, AuthKit adapter.')
            ->children()
                ->scalarNode('default_provider')
                    ->info('Provider used while "sms.provider" is not stored: null (disabled), log, twilio, sms_bridge or a host provider name (empty = null).')
                    ->defaultValue('null')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || ($v !== '' && !str_starts_with($v, '%env(') && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $v) !== 1))
                        ->thenInvalid('nowo_sms_kit.default_provider must be a lower snake case provider name (null, log, twilio, sms_bridge, …), got %s.')
                    ->end()
                ->end()
                ->scalarNode('sender')
                    ->info('Sender used while "sms.sender" is not stored: an E.164 number or an alphanumeric sender id (e.g. %env(default::SMS_SENDER)%).')
                    ->defaultNull()
                ->end()
                ->append($this->httpNode())
                ->append($this->providersNode())
                ->append($this->destinationsNode())
                ->append($this->testActionNode())
                ->append($this->authKitNode())
            ->end();

        return $treeBuilder;
    }

    private function httpNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('http'))->getRootNode();
        $node
            ->info('Provider HTTP calls (REQ-RUNTIME-001): timeouts and outbound URL policy (nowo-tech/outbound-url-guard-bundle).')
            ->addDefaultsIfNotSet()
            ->children()
                ->floatNode('timeout')
                    ->info('Idle timeout in seconds of a provider request.')
                    ->defaultValue(5.0)
                    ->min(0.1)->max(60.0)
                ->end()
                ->floatNode('max_duration')
                    ->info('Total duration cap in seconds of a provider request (connect included). Keep it below PHP max_execution_time.')
                    ->defaultValue(15.0)
                    ->min(0.1)->max(120.0)
                ->end()
                ->booleanNode('allow_private_endpoints')
                    ->info('Allow loopback / private-network endpoints (self-hosted SMS Bridge on the LAN or a Docker network). Cloud metadata addresses stay blocked.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('resolve_dns')
                    ->info('Resolve the endpoint host and pin the checked address (DNS rebinding protection). Disable only for Docker service names.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('require_https')
                    ->info('Refuse plain http:// provider endpoints (credentials travel in every request).')
                    ->defaultTrue()
                ->end()
                ->integerNode('max_response_bytes')
                    ->info('Largest provider response body read; the transfer is aborted beyond it.')
                    ->defaultValue(65536)
                    ->min(1024)->max(4194304)
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['max_duration'] < $v['timeout'])
                ->thenInvalid('nowo_sms_kit.http.max_duration must be greater than or equal to nowo_sms_kit.http.timeout.')
            ->end();

        return $node;
    }

    private function providersNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('providers'))->getRootNode();
        $node
            ->info('Bundled providers. Credentials here are fallbacks used while the "sms" section stores nothing (prefer %env(...)% placeholders).')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('log')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->info('Register the "log" provider (writes masked metadata to the logger, sends nothing).')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('twilio')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->info('Register the "twilio" provider.')->defaultTrue()->end()
                        ->scalarNode('api_base_url')
                            ->info('Twilio API base URL.')
                            ->defaultValue(SmsConfig::TWILIO_API_BASE_URL)
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('account_sid')->info('Fallback Account SID (AC…).')->defaultNull()->end()
                        ->scalarNode('auth_token')->info('Fallback Auth Token (use an %env()% placeholder, never a literal).')->defaultNull()->end()
                        ->scalarNode('messaging_service_sid')->info('Fallback Messaging Service SID (MG…).')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('sms_bridge')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->info('Register the "sms_bridge" provider.')->defaultTrue()->end()
                        ->scalarNode('base_url')->info('Fallback SMS Bridge base URL.')->defaultNull()->end()
                        ->enumNode('api_mode')
                            ->info('Fallback API surface: twilio (Twilio-compatible Messages API) or native (/api/v1/sms with a Bearer client key).')
                            ->values([SmsConfig::BRIDGE_MODE_TWILIO, SmsConfig::BRIDGE_MODE_NATIVE])
                            ->defaultValue(SmsConfig::BRIDGE_MODE_TWILIO)
                        ->end()
                        ->scalarNode('account_sid')->info('Fallback Account SID (AC…, Twilio-compatible mode).')->defaultNull()->end()
                        ->scalarNode('auth_token')->info('Fallback client key (smscli_…; use an %env()% placeholder).')->defaultNull()->end()
                        ->scalarNode('device_id')->info('Fallback device id (null = the bridge picks a device).')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function destinationsNode(): ArrayNodeDefinition
    {
        $digits = static fn (mixed $v): bool => !is_array($v) || array_filter($v, static fn (mixed $c): bool => !is_string($c) || !DestinationPolicy::isDigits($c)) !== [];
        $node   = (new TreeBuilder('destinations'))->getRootNode();
        $node
            ->info('Destination and cost policy of every send (SMS pumping / toll fraud / SMS bombing).')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('allowed_country_codes')
                    ->info('Calling codes (digits, e.g. ["34", "1"]) a destination must start with. Empty = every country.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->validate()
                        ->ifTrue($digits)
                        ->thenInvalid('nowo_sms_kit.destinations.allowed_country_codes must list calling codes as digit strings (1-6 digits), got %s.')
                    ->end()
                ->end()
                ->arrayNode('denied_prefixes')
                    ->info('Prefixes (digits) always refused: premium, satellite and international-network ranges. Replacing the list replaces the defaults.')
                    ->scalarPrototype()->end()
                    ->defaultValue(DestinationPolicy::DEFAULT_DENIED_PREFIXES)
                    ->validate()
                        ->ifTrue($digits)
                        ->thenInvalid('nowo_sms_kit.destinations.denied_prefixes must list prefixes as digit strings (1-6 digits), got %s.')
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->info('Optional send budgets (sliding window, state in cache.app). Off unless set.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->budgetNode('per_destination', 'Messages per destination number and window, e.g. {limit: 3, interval: "1 hour"}.'))
                        ->append($this->budgetNode('global', 'Messages for the whole instance and window, e.g. {limit: 500, interval: "1 day"}.'))
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function budgetNode(string $name, string $info): ArrayNodeDefinition
    {
        $node = (new TreeBuilder($name))->getRootNode();
        $node
            ->info($info)
            ->canBeEnabled()
            ->children()
                ->integerNode('limit')->defaultValue(10)->min(1)->max(1000000)->end()
                ->scalarNode('interval')
                    ->defaultValue('1 hour')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || strtotime('+' . $v, 0) === false || strtotime('+' . $v, 0) <= 0)
                        ->thenInvalid('nowo_sms_kit.destinations.rate_limit.' . $name . '.interval must be a relative time such as "1 hour", got %s.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function testActionNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('test_action'))->getRootNode();
        $node
            ->info('"Send a test SMS" button of the "sms" settings section (POST + CSRF, instance settings access checker, rate limit).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('path')
                    ->info('Path of the POST route (import the "nowo_sms_kit" route loader). Keep it under the instance settings admin prefix so the firewall rule covers it.')
                    ->defaultValue(self::DEFAULT_TEST_PATH)
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('#^/[A-Za-z0-9_\-/.]*$#', $v) !== 1)
                        ->thenInvalid('nowo_sms_kit.test_action.path must be an absolute path such as /admin/settings/sms/test, got %s.')
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('limit')->info('Test sends allowed per user and interval.')->defaultValue(5)->min(1)->max(1000)->end()
                        ->scalarNode('interval')
                            ->info('Window of the limit (sliding window), e.g. "15 minutes".')
                            ->defaultValue('15 minutes')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || strtotime('+' . $v, 0) === false || strtotime('+' . $v, 0) <= 0)
                                ->thenInvalid('nowo_sms_kit.test_action.rate_limit.interval must be a relative time such as "15 minutes", got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function authKitNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('auth_kit'))->getRootNode();
        $node
            ->info('AuthKit adapter (nowo-tech/auth-kit-bundle).')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('password_reset')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Decorate AuthKit\'s PasswordResetNotifierInterface: the reset code also goes by SMS to the account phone (delivery modes code / both).')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('phone_property')
                            ->info('User property holding the E.164 phone (read through get<Property>(), <property>() or the public property).')
                            ->defaultValue('phone')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $v) !== 1)
                                ->thenInvalid('nowo_sms_kit.auth_kit.password_reset.phone_property must be a PHP property name, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('phone_resolver')
                            ->info('Service id of a PhoneNumberResolverInterface replacing the property resolver.')
                            ->defaultNull()
                        ->end()
                        ->enumNode('delivery')
                            ->info('When the code SMS is sent: deferred (after the response, kernel.terminate), messenger (SendSmsMessage, route it to an async transport) or sync (inside the request; the provider latency then shows in the response time).')
                            ->values(['deferred', 'messenger', 'sync'])
                            ->defaultValue('deferred')
                        ->end()
                        ->enumNode('messenger_failures')
                            ->info('delivery: messenger only. log (default): send failures are logged and swallowed, Messenger never retries and the failure transport is not used. rethrow: provider / transport failures propagate (redacted) so the retry strategy and the failure transport apply; a refused destination or invalid number is unrecoverable (no retry). A retried message may deliver a stale code, and a redelivery sends a second SMS (no idempotency key).')
                            ->values(['log', 'rethrow'])
                            ->defaultValue('log')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }
}
