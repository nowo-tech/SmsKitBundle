<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Settings;

use Closure;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionNoticesInterface;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Phone\SenderId;
use Nowo\SmsKitBundle\Provider\SmsBridgeProvider;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Provider\TwilioProvider;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints\Regex;

use function implode;
use function in_array;
use function trim;

/**
 * The `sms` instance settings section: active provider, sender, test recipient and the credentials of the bundled
 * providers (tokens are encrypted secrets). With the send-test action enabled, the section page also shows a
 * "Send a test SMS" POST button (CSRF form) next to Save.
 *
 * Empty fields fall back to the bundle configuration ({@see SmsFallbacks}). Since 1.2 the section exposes those
 * fallbacks to `nowo:instance-settings:seed-from-fallbacks` (DB-only mode) and warns on its page when the active
 * provider misses required fields ({@see SmsConfigStatus}). Since 1.3 the Twilio and SMS Bridge credentials are
 * provider-dependent (`visible_when` on `provider`): only the selected provider's rows are shown and validated, the
 * others keep their stored values.
 */
final class SmsSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface, SectionFallbackProviderInterface, SettingsSectionNoticesInterface
{
    /** Section name (key prefix `sms.`, URL segment, export key). */
    public const NAME = 'sms';

    /**
     * @param SmsProviderRegistry $providers registered providers (choices of `sms.provider`)
     * @param UrlGeneratorInterface|null $urls router (send-test action)
     * @param CsrfOnlyFormFactory|null $csrfForms FormKit CSRF-only form factory (send-test action)
     * @param bool $testAction whether the send-test action is enabled
     * @param SmsFallbacks|null $fallbacks bundle configuration copied by the seed (null = no fallbacks)
     * @param (Closure(): SmsConfigStatus)|null $status lazy status of the active provider (page notice); lazy because
     *                                                  the status reads the settings store this section belongs to
     */
    public function __construct(
        private readonly SmsProviderRegistry $providers,
        private readonly ?UrlGeneratorInterface $urls = null,
        private readonly ?CsrfOnlyFormFactory $csrfForms = null,
        private readonly bool $testAction = false,
        private readonly ?SmsFallbacks $fallbacks = null,
        private readonly ?Closure $status = null,
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
     * `tabler:message-2`.
     */
    public function getIcon(): ?string
    {
        return 'tabler:message-2';
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
        // Provider-dependent rows (1.3, InstanceSettings visible_when): credentials of a provider are shown only while
        // it is selected and keep their stored values when another provider is saved. Unregistered provider: always shown.
        $twilio = static fn (SettingField $field): SettingField => in_array(TwilioProvider::NAME, $choices, true) ? $field->withVisibleWhen('provider', [TwilioProvider::NAME]) : $field;
        $bridge = static fn (SettingField $field): SettingField => in_array(SmsBridgeProvider::NAME, $choices, true) ? $field->withVisibleWhen('provider', [SmsBridgeProvider::NAME]) : $field;

        yield SettingField::choice('provider', $choices, null, 'settings.sms.provider', 'settings.sms.provider_help');
        yield SettingField::string('sender', null, 'settings.sms.sender', 'settings.sms.sender_help', constraints: [new Regex(SenderId::PATTERN, 'Enter an E.164 number (+15550100123) or an alphanumeric sender id (1-11 letters, digits or spaces).')]);
        yield SettingField::string('test_recipient', null, 'settings.sms.test_recipient', 'settings.sms.test_recipient_help', constraints: [new Regex(E164::PATTERN, 'Enter an E.164 number such as +15550100123.')]);
        yield $twilio(SettingField::string('twilio_account_sid', null, 'settings.sms.twilio_account_sid', constraints: [new Regex(TwilioProvider::ACCOUNT_SID_PATTERN, 'A Twilio Account SID is "AC" followed by 32 hexadecimal characters.')]));
        yield $twilio(SettingField::secret('twilio_auth_token', 'settings.sms.twilio_auth_token'));
        yield $twilio(SettingField::string('twilio_messaging_service_sid', null, 'settings.sms.twilio_messaging_service_sid', 'settings.sms.twilio_messaging_service_sid_help', constraints: [new Regex(TwilioProvider::MESSAGING_SERVICE_SID_PATTERN, 'A Messaging Service SID is "MG" followed by 32 hexadecimal characters.')]));
        yield $bridge(SettingField::url('bridge_base_url', null, 'settings.sms.bridge_base_url', 'settings.sms.bridge_base_url_help'));
        yield $bridge(SettingField::choice('bridge_api_mode', ['settings.sms.bridge_api_mode_twilio' => SmsConfig::BRIDGE_MODE_TWILIO, 'settings.sms.bridge_api_mode_native' => SmsConfig::BRIDGE_MODE_NATIVE], null, 'settings.sms.bridge_api_mode'));
        yield $bridge(SettingField::string('bridge_account_sid', null, 'settings.sms.bridge_account_sid', constraints: [new Regex(TwilioProvider::ACCOUNT_SID_PATTERN, 'An SMS Bridge Account SID is "AC" followed by 32 hexadecimal characters.')]));
        yield $bridge(SettingField::secret('bridge_auth_token', 'settings.sms.bridge_auth_token', 'settings.sms.bridge_auth_token_help'));
        yield $bridge(SettingField::string('bridge_device_id', null, 'settings.sms.bridge_device_id', 'settings.sms.bridge_device_id_help', constraints: [new Regex(SmsMessage::DEVICE_ID_PATTERN, 'A device id has 1 to 64 letters, digits or dashes.')]));
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

    /**
     * The bundle configuration values (`default_provider`, `sender`, `providers.twilio.*`, `providers.sms_bridge.*`)
     * for the seed. A disabled default provider (`null`, `none`…) is not a fallback; the Twilio API base URL is not a
     * setting (it stays in the bundle configuration); the bridge API mode is only seeded with a bridge URL.
     *
     * @return iterable<SettingFallback>
     */
    public function getFallbacks(): iterable
    {
        $f = $this->fallbacks;
        if (!$f instanceof SmsFallbacks) {
            return [];
        }

        $provider = SmsConfigResolver::normalizeProvider($f->defaultProvider);

        return [
            SettingFallback::plain('provider', $provider === 'null' ? null : $provider),
            SettingFallback::plain('sender', $f->sender),
            SettingFallback::plain('twilio_account_sid', $f->twilioAccountSid),
            SettingFallback::secret('twilio_auth_token', $f->twilioAuthToken),
            SettingFallback::plain('twilio_messaging_service_sid', $f->twilioMessagingServiceSid),
            SettingFallback::plain('bridge_base_url', $f->bridgeBaseUrl),
            SettingFallback::plain('bridge_api_mode', $f->bridgeBaseUrl === null || trim($f->bridgeBaseUrl) === '' ? null : $f->bridgeApiMode),
            SettingFallback::plain('bridge_account_sid', $f->bridgeAccountSid),
            SettingFallback::secret('bridge_auth_token', $f->bridgeAuthToken),
            SettingFallback::plain('bridge_device_id', $f->bridgeDeviceId),
        ];
    }

    /**
     * Warning when the active provider (not `null`) misses required fields; names the fields, never their values.
     *
     * @param SectionActionContext $context page being rendered (unused)
     *
     * @return iterable<TranslatableMessage>
     */
    public function getNotices(SectionActionContext $context): iterable
    {
        $status = $this->status instanceof Closure ? ($this->status)() : null;
        if (!$status instanceof SmsConfigStatus) {
            return [];
        }
        if ($status->isProviderUnset()) {
            return [new TranslatableMessage('settings.sms.notice_no_provider', [], NowoSmsKitBundle::TRANSLATION_DOMAIN)];
        }
        $missing = $status->missingFields();
        if ($missing === []) {
            return [];
        }

        return [new TranslatableMessage('settings.sms.notice_missing', ['%provider%' => $status->provider(), '%fields%' => implode(', ', $missing)], NowoSmsKitBundle::TRANSLATION_DOMAIN)];
    }
}
