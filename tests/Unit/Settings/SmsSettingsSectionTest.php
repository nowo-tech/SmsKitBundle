<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\Provider\LogSmsProvider;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Settings\SmsSettingsSection;
use Nowo\SmsKitBundle\Tests\Support\Forms;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function array_keys;
use function iterator_to_array;

final class SmsSettingsSectionTest extends TestCase
{
    private function registry(): SmsProviderRegistry
    {
        return new SmsProviderRegistry([new NullSmsProvider(), new LogSmsProvider()]);
    }

    public function testMetadataAndFields(): void
    {
        $section = new SmsSettingsSection($this->registry());

        self::assertSame('sms', $section->getName());
        self::assertSame('settings.sms.label', $section->getLabel());
        self::assertSame('NowoSmsKitBundle', $section->getTranslationDomain());
        self::assertSame('settings.sms.intro', $section->getIntro());
        self::assertNull($section->getIcon());

        $fields = [];
        foreach ($section->getFields() as $field) {
            self::assertInstanceOf(SettingField::class, $field);
            $fields[$field->name] = $field;
        }

        self::assertSame(['provider', 'sender', 'test_recipient', 'twilio_account_sid', 'twilio_auth_token', 'twilio_messaging_service_sid', 'bridge_base_url', 'bridge_api_mode', 'bridge_account_sid', 'bridge_auth_token', 'bridge_device_id'], array_keys($fields));
        self::assertSame(['settings.sms.provider_null' => 'null', 'settings.sms.provider_log' => 'log'], $fields['provider']->choices);
        self::assertTrue($fields['twilio_auth_token']->secret);
        self::assertTrue($fields['bridge_auth_token']->secret);
        self::assertFalse($fields['sender']->secret);
    }

    public function testNoActionWhenDisabledOrUnwired(): void
    {
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry()))->getActions()));
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry(), $this->urls(), Forms::csrf(), false))->getActions()));
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry(), null, Forms::csrf(), true))->getActions()));
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry(), $this->urls(), null, true))->getActions()));
    }

    public function testSendTestActionIsACsrfPost(): void
    {
        $actions = iterator_to_array((new SmsSettingsSection($this->registry(), $this->urls(), Forms::csrf(), true))->getActions());

        self::assertCount(1, $actions);
        $action = $actions[0];
        self::assertInstanceOf(Action::class, $action);
        self::assertSame('POST', $action->method);
        self::assertSame('/admin/settings/sms/test', $action->href);
        self::assertInstanceOf(Label::class, $action->label);
        self::assertSame('settings.sms.send_test', $action->label->key);
        self::assertNotNull($action->form);
        self::assertSame('csrf_only', $action->form->vars['name']);
        self::assertArrayHasKey('_token', $action->form->children);
    }

    private function urls(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add(SendTestSmsController::ROUTE, new Route('/admin/settings/sms/test'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
