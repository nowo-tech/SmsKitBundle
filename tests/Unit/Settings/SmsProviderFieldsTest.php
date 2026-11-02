<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\SmsKitBundle\Provider\LogSmsProvider;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsConfigStatus;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Settings\SmsSettingsSection;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_filter;
use function array_keys;

/**
 * 1.3: provider-dependent rows of the `sms` section (InstanceSettings `visible_when`) and their consistency with
 * {@see SmsConfigStatus}.
 */
final class SmsProviderFieldsTest extends TestCase
{
    private function providers(): SmsProviderRegistry
    {
        return new SmsProviderRegistry([new NullSmsProvider(), new LogSmsProvider(), new StubProvider('twilio'), new StubProvider('sms_bridge')]);
    }

    public function testCredentialsDependOnTheProvider(): void
    {
        $registry = new SettingsSectionRegistry([new SmsSettingsSection($this->providers())]);
        $fields   = $registry->fields('sms');

        foreach (['twilio_account_sid', 'twilio_auth_token', 'twilio_messaging_service_sid'] as $name) {
            self::assertSame(['field' => 'provider', 'in' => ['twilio']], $fields[$name]->visibleWhen, $name);
        }
        foreach (['bridge_base_url', 'bridge_api_mode', 'bridge_account_sid', 'bridge_auth_token', 'bridge_device_id'] as $name) {
            self::assertSame(['field' => 'provider', 'in' => ['sms_bridge']], $fields[$name]->visibleWhen, $name);
        }
        foreach (['provider', 'sender', 'test_recipient'] as $name) {
            self::assertNull($fields[$name]->visibleWhen, $name);
        }

        self::assertSame(['provider', 'sender', 'test_recipient'], $this->visible($registry, ['provider' => 'null']));
        self::assertSame(['provider', 'sender', 'test_recipient'], $this->visible($registry, ['provider' => 'log']));
        self::assertSame(['provider', 'sender', 'test_recipient', 'twilio_account_sid', 'twilio_auth_token', 'twilio_messaging_service_sid'], $this->visible($registry, ['provider' => 'twilio']));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private function visible(SettingsSectionRegistry $registry, array $values): array
    {
        return array_keys(array_filter($registry->fields('sms'), static fn (SettingField $field): bool => $field->isVisibleFor($values)));
    }

    public function testUnregisteredProvidersKeepTheirRowsVisible(): void
    {
        $registry = new SettingsSectionRegistry([new SmsSettingsSection(new SmsProviderRegistry([new NullSmsProvider()]))]);
        self::assertNull($registry->field('sms', 'twilio_account_sid')->visibleWhen);
        self::assertNull($registry->field('sms', 'bridge_base_url')->visibleWhen);
    }

    public function testStatusOnlyNamesFieldsVisibleForTheActiveProvider(): void
    {
        $registry = new SettingsSectionRegistry([new SmsSettingsSection($this->providers())]);
        foreach (['twilio', 'sms_bridge'] as $provider) {
            $status  = new SmsConfigStatus(new SmsConfigResolver(new FakeInstanceSettings(), new SmsFallbacks($provider)), $this->providers());
            $missing = $status->missingFields();
            self::assertNotSame([], $missing, $provider);
            self::assertSame([], array_diff($missing, $this->visible($registry, ['provider' => $provider])), $provider);
        }
    }
}
