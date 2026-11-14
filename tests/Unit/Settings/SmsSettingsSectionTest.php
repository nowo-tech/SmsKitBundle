<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Settings;

use Closure;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\Provider\LogSmsProvider;
use Nowo\SmsKitBundle\Provider\NullSmsProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsConfigStatus;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Settings\SmsSettingsSection;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\Forms;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\TranslatableMessage;

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
        self::assertSame('tabler:message-2', $section->getIcon());

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
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry()))->getActions($this->context())));
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry(), $this->urls(), Forms::csrf(), false))->getActions($this->context())));
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry(), null, Forms::csrf(), true))->getActions($this->context())));
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry(), $this->urls(), null, true))->getActions($this->context())));
    }

    public function testSendTestActionIsACsrfPost(): void
    {
        $actions = iterator_to_array((new SmsSettingsSection($this->registry(), $this->urls(), Forms::csrf(), true))->getActions($this->context()));

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

    public function testFallbacksForTheSeed(): void
    {
        self::assertSame([], iterator_to_array((new SmsSettingsSection($this->registry()))->getFallbacks()));

        $token     = Numbers::token();
        $fallbacks = new SmsFallbacks('Twilio', 'Acme', Numbers::accountSid(), $token, Numbers::messagingServiceSid(), 'https://api.example.test', 'https://bridge.example.test', 'native', Numbers::accountSid(), Numbers::bridgeKey(), 'device-1');
        $values    = [];
        $secrets   = [];
        foreach ((new SmsSettingsSection($this->registry(), fallbacks: $fallbacks))->getFallbacks() as $fallback) {
            self::assertInstanceOf(SettingFallback::class, $fallback);
            $values[$fallback->field] = $fallback->value();
            if ($fallback->secret) {
                $secrets[] = $fallback->field;
            }
        }

        self::assertSame('twilio', $values['provider']);
        self::assertSame('Acme', $values['sender']);
        self::assertSame($token, $values['twilio_auth_token']);
        self::assertSame('native', $values['bridge_api_mode']);
        self::assertSame('device-1', $values['bridge_device_id']);
        self::assertArrayNotHasKey('twilio_api_base_url', $values, 'the API base URL stays in the bundle configuration');
        self::assertSame(['twilio_auth_token', 'bridge_auth_token'], $secrets);

        $disabled = [];
        foreach ((new SmsSettingsSection($this->registry(), fallbacks: new SmsFallbacks(' None ')))->getFallbacks() as $fallback) {
            $disabled[$fallback->field] = $fallback->value();
        }
        self::assertNull($disabled['provider'], 'a null default provider is not seeded');
        self::assertNull($disabled['bridge_api_mode'], 'the API mode is seeded only with a bridge URL');
    }

    public function testNoticeWhenTheActiveProviderMissesFields(): void
    {
        $registry = $this->registry();
        $status   = static fn (SmsFallbacks $f): Closure => static fn (): SmsConfigStatus => new SmsConfigStatus(new SmsConfigResolver(new FakeInstanceSettings(), $f), $registry, new FakeInstanceSettings());

        self::assertSame([], iterator_to_array((new SmsSettingsSection($registry))->getNotices($this->context())));
        $unset = iterator_to_array((new SmsSettingsSection($registry, status: $status(new SmsFallbacks())))->getNotices($this->context()));
        self::assertCount(1, $unset, 'recipe 1.2 before the seed: no provider chosen yet');
        self::assertSame('settings.sms.notice_no_provider', $unset[0]->getMessage());
        $disabled = static fn (): SmsConfigStatus => new SmsConfigStatus(new SmsConfigResolver(new FakeInstanceSettings(['sms.provider' => 'null']), new SmsFallbacks()), $registry, new FakeInstanceSettings(['sms.provider' => 'null']));
        self::assertSame([], iterator_to_array((new SmsSettingsSection($registry, status: $disabled))->getNotices($this->context())), 'explicitly disabled: no warning');

        $notices = iterator_to_array((new SmsSettingsSection($registry, status: $status(new SmsFallbacks('twilio', 'Acme'))))->getNotices($this->context()));
        self::assertCount(1, $notices);
        self::assertInstanceOf(TranslatableMessage::class, $notices[0]);
        self::assertSame('settings.sms.notice_missing', $notices[0]->getMessage());
        self::assertSame('NowoSmsKitBundle', $notices[0]->getDomain());
        self::assertSame(['%provider%' => 'twilio', '%fields%' => 'twilio_account_sid, twilio_auth_token'], $notices[0]->getParameters());
    }

    private function context(): SectionActionContext
    {
        return new SectionActionContext('sms', new Request());
    }

    private function urls(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add(SendTestSmsController::ROUTE, new Route('/admin/settings/sms/test'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
