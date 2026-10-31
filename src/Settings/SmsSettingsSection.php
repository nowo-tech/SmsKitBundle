<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Phone\SenderId;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Provider\TwilioProvider;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The `sms` instance settings section: active provider, sender, test recipient and the credentials of the bundled
 * providers (tokens are encrypted secrets). With the send-test action enabled, the section page also shows a
 * "Send a test SMS" POST button (CSRF form) next to Save.
 *
 * Empty fields fall back to the bundle configuration ({@see SmsFallbacks}).
 */
final class SmsSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface
{
    /** Section name (key prefix `sms.`, URL segment, export key). */
    public const NAME = 'sms';

    /**
     * @param SmsProviderRegistry $providers registered providers (choices of `sms.provider`)
     * @param UrlGeneratorInterface|null $urls router (send-test action)
     * @param CsrfOnlyFormFactory|null $csrfForms FormKit CSRF-only form factory (send-test action)
     * @param bool $testAction whether the send-test action is enabled
     */
    public function __construct(
        private readonly SmsProviderRegistry $providers,
        private readonly ?UrlGeneratorInterface $urls = null,
        private readonly ?CsrfOnlyFormFactory $csrfForms = null,
        private readonly bool $testAction = false,
    ) {
    }

    /**
     * `sms`.
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * Section title key.
     */
    public function getLabel(): string
    {
        return 'settings.sms.label';
    }

    /**
     * `NowoSmsKitBundle`.
     */
    public function getTranslationDomain(): string
    {
        return NowoSmsKitBundle::TRANSLATION_DOMAIN;
    }

    /**
     * Intro key.
     */
    public function getIntro(): string
    {
        return 'settings.sms.intro';
    }

    /**
     * Fields in form order.
     *
     * @return iterable<SettingField>
     */
    public function getFields(): iterable
    {
        $choices = [];
        foreach ($this->providers->all() as $name => $provider) {
            $choices[$provider->getLabel()] = $name;
        }

        yield SettingField::choice('provider', $choices, null, 'settings.sms.provider', 'settings.sms.provider_help');
        yield SettingField::string('sender', null, 'settings.sms.sender', 'settings.sms.sender_help', constraints: [new Regex(SenderId::PATTERN, 'Enter an E.164 number (+15550100123) or an alphanumeric sender id (1-11 letters, digits or spaces).')]);
        yield SettingField::string('test_recipient', null, 'settings.sms.test_recipient', 'settings.sms.test_recipient_help', constraints: [new Regex(E164::PATTERN, 'Enter an E.164 number such as +15550100123.')]);
        yield SettingField::string('twilio_account_sid', null, 'settings.sms.twilio_account_sid', constraints: [new Regex(TwilioProvider::ACCOUNT_SID_PATTERN, 'A Twilio Account SID is "AC" followed by 32 hexadecimal characters.')]);
        yield SettingField::secret('twilio_auth_token', 'settings.sms.twilio_auth_token');
        yield SettingField::string('twilio_messaging_service_sid', null, 'settings.sms.twilio_messaging_service_sid', 'settings.sms.twilio_messaging_service_sid_help', constraints: [new Regex(TwilioProvider::MESSAGING_SERVICE_SID_PATTERN, 'A Messaging Service SID is "MG" followed by 32 hexadecimal characters.')]);
        yield SettingField::url('bridge_base_url', null, 'settings.sms.bridge_base_url', 'settings.sms.bridge_base_url_help');
        yield SettingField::choice('bridge_api_mode', ['settings.sms.bridge_api_mode_twilio' => SmsConfig::BRIDGE_MODE_TWILIO, 'settings.sms.bridge_api_mode_native' => SmsConfig::BRIDGE_MODE_NATIVE], null, 'settings.sms.bridge_api_mode');
        yield SettingField::string('bridge_account_sid', null, 'settings.sms.bridge_account_sid', constraints: [new Regex(TwilioProvider::ACCOUNT_SID_PATTERN, 'An SMS Bridge Account SID is "AC" followed by 32 hexadecimal characters.')]);
        yield SettingField::secret('bridge_auth_token', 'settings.sms.bridge_auth_token', 'settings.sms.bridge_auth_token_help');
        yield SettingField::string('bridge_device_id', null, 'settings.sms.bridge_device_id', 'settings.sms.bridge_device_id_help', constraints: [new Regex(SmsMessage::DEVICE_ID_PATTERN, 'A device id has 1 to 64 letters, digits or dashes.')]);
    }

    /**
     * "Send a test SMS" (POST + CSRF) when the action is enabled.
     *
     * @param SectionActionContext $context section, request and user of the page being rendered (unused: the page itself is access-checked)
     *
     * @return iterable<Action>
     */
    public function getActions(SectionActionContext $context): iterable
    {
        if (!$this->testAction || !$this->urls instanceof UrlGeneratorInterface || !$this->csrfForms instanceof CsrfOnlyFormFactory) {
            return [];
        }

        $url = $this->urls->generate(SendTestSmsController::ROUTE);

        return [new Action(
            'custom',
            new Label('settings.sms.send_test', NowoSmsKitBundle::TRANSLATION_DOMAIN),
            $url,
            method: 'POST',
            variant: 'secondary',
            form: $this->csrfForms->createNamed($url, SendTestSmsController::CSRF_TOKEN_ID)->createView(),
        )];
    }
}
