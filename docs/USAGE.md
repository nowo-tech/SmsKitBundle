# Usage

## Table of contents

- [Sending a message](#sending-a-message)
  - [Phone numbers and senders](#phone-numbers-and-senders)
  - [Errors](#errors)
- [Choosing the provider at runtime](#choosing-the-provider-at-runtime)
  - [Twilio](#twilio)
  - [SMS Bridge](#sms-bridge)
  - [Log and null](#log-and-null)
- [Writing a provider](#writing-a-provider)
- [Send-test action](#send-test-action)
- [Command line](#command-line)
- [AuthKit password-reset adapter](#authkit-password-reset-adapter)
- [Long-running processes](#long-running-processes)
- [Logging](#logging)
- [Forms and templates](#forms-and-templates)
- [Translations](#translations)

## Sending a message

Inject `Nowo\SmsKitBundle\Sender\SmsSenderInterface` (public alias):

```php
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;

final class OneTimeCodeNotifier
{
    public function __construct(private SmsSenderInterface $sms) {}

    public function send(string $phone, string $code): bool
    {
        if (!$this->sms->isConfigured()) {
            return false;                       // fall back to e-mail, hide the SMS option, …
        }

        try {
            $result = $this->sms->send(new SmsMessage($phone, sprintf('Your code is %s', $code)));
        } catch (SmsSendException) {
            return false;                       // already logged (masked) by the sender
        }

        return true;                            // $result->providerId / providerMessageId / status
    }
}
```

`SmsMessage` arguments: `to` (E.164), `body`, and optional `from` (sender override), `deviceId` (SMS Bridge device, 1–64 letters, digits, dashes), `clientMessageId` (idempotency id, 1–128 letters, digits, `.`, `_`, `:`, `-`) and `statusCallbackUrl` (delivery callback, Twilio and SMS Bridge: a public `https://` URL checked by the outbound URL guard). Never take `statusCallbackUrl`, `deviceId` or `clientMessageId` from end-user input: the provider calls the callback URL. The provider and its configuration are resolved on every call: what an admin saves applies to the next message.

### Phone numbers and senders

- Destinations must be **E.164**: `+`, a country code that does not start with 0, 7 to 15 digits in total. Whitespace is removed (`+1 555 0100 123` is accepted); dashes, dots and parentheses are refused so a typo is never turned into another number. Helpers: `Nowo\SmsKitBundle\Phone\E164::normalize()`, `isValid()`, `mask()`.
- The sender is the message `from`, else the `sms.sender` setting, else `nowo_sms_kit.sender`: an E.164 number or an alphanumeric sender id (1–11 letters, digits or spaces with at least one letter). Twilio without any sender uses the Messaging Service SID.
- Why a strict syntax check instead of libphonenumber or `nowo-tech/phone-input-bundle`: the provider is the authority on whether a number is reachable, PhoneInput is a form widget bundle (Form / Twig / UX runtime dependencies) and libphonenumber adds a large dataset for no delivery guarantee. Validate numbers when users **enter** them (PhoneInput's `PhoneType` does that) and send the stored E.164 value.
- Bodies are trimmed, must not be empty and are limited to 1600 characters (`ConfiguredSmsSender::MAX_BODY_LENGTH`, the Twilio concatenation limit).
- Every destination goes through the [destination policy](CONFIGURATION.md#destinations): premium / satellite / international-network prefixes are refused by default (`+882…` included), `allowed_country_codes` narrows to your countries, optional per-destination and global budgets.

### Errors

Every failure is a `Nowo\SmsKitBundle\Exception\SmsSendException` (invalid number or sender: `InvalidPhoneNumberException`; destination policy or budget: `DestinationNotAllowedException`): provider not configured, null provider, endpoint refused by the outbound URL guard, timeout / transport error, response too large, provider rejection (`Twilio rejected the message (HTTP 400).`). The **message** is written by the bundle and safe to show to an operator; what the provider or the transport said is in `getDetail()` (masked, truncated to 120 characters) and must only be logged — the sender logs it on the `sms` channel.

## Choosing the provider at runtime

The `sms` section of the InstanceSettings admin UI (`/admin/settings/sections/sms`) holds the provider, the sender, a test recipient and the credentials of the bundled providers; tokens are encrypted secrets that are never shown again (blank keeps, a checkbox clears). From code:

```php
$settings->save('sms', ['provider' => 'sms_bridge', 'bridge_base_url' => 'https://sms-bridge.example.test', 'bridge_auth_token' => $key]);
```

A save dispatches `SettingsChangedEvent`; `SmsConfigResolver` listens to it and rebuilds the configuration for the next send. Empty fields use the [configuration fallbacks](CONFIGURATION.md#provider-and-sender-fallbacks).

### Twilio

Programmable Messaging: `POST {api_base_url}/2010-04-01/Accounts/{AccountSid}/Messages.json`, HTTP Basic with the Account SID and the Auth Token, form body `To`, `Body`, `From` (or `MessagingServiceSid`), `StatusCallback`. Configured when the Account SID (`AC` + 32 hex), the Auth Token and a sender or a Messaging Service SID (`MG` + 32 hex) are set. The result carries Twilio's `sid` and `status`.

### SMS Bridge

The Nowo SMS Bridge server sends through paired Android / iOS phones. Two API surfaces (`bridge_api_mode`):

- `twilio` (default) — the Twilio-compatible Messages API of the bridge (`/2010-04-01/Accounts/{AC…}/Messages.json`), HTTP Basic with the bridge Account SID and the client key (`smscli_…`), plus the bridge extensions `DeviceId` and `ClientMessageId`;
- `native` — `POST /api/v1/sms` with `Authorization: Bearer smscli_…` and a JSON body (`to`, `body`, `from`, `deviceId`, `clientMessageId`).

A bridge on the LAN or in Docker needs `http.allow_private_endpoints: true` (and `http.require_https: false` for plain HTTP). Keep `http.resolve_dns: true`: Docker service names resolve through Docker's DNS and the answer is checked (metadata refused) and pinned. `resolve_dns: false` skips that check for every hostname (a name pointing at cloud metadata would be accepted) and logs a warning on every send when private endpoints are allowed. The device id falls back to the server choice when empty.

### Log and null

- `log` — always configured; writes one `info` record per message (provider, masked destination and sender, body length, a random `log-…` id) and returns status `logged`. Use it in development and demos.
- `null` — the default; never configured, every send fails. `isConfigured()` is false, so callers can hide SMS features.

## Writing a provider

Implement `Nowo\SmsKitBundle\Provider\SmsProviderInterface`; with autoconfiguration the service is tagged `nowo_sms_kit.provider` and appears in the provider choice (tag `priority` orders the choices).

```php
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Provider\SmsProviderInterface;
use Nowo\SmsKitBundle\Settings\SmsConfig;

final readonly class AcmeGatewayProvider implements SmsProviderInterface
{
    public function __construct(private InstanceSettingsInterface $settings, private AcmeClient $client) {}

    public function getName(): string { return 'acme_gateway'; }            // stored in sms.provider; lower snake case
    public function getLabel(): string { return 'Acme gateway'; }           // NowoSmsKitBundle key or plain text

    public function isConfigured(SmsConfig $config): bool
    {
        return $this->settings->getSecret('acme.api_key') !== null;          // your own settings section
    }

    public function send(SmsMessage $message, SmsConfig $config): SmsSendResult
    {
        // $message->to is E.164, $message->body trimmed, $message->from normalized (or null)
        $id = $this->client->send($message->to, $message->body, $message->from ?? $config->sender)
            ?? throw new SmsSendException('Acme gateway refused the message.');

        return new SmsSendResult('acme_gateway', $id, 'queued');
    }
}
```

Rules: keep providers stateless (the configuration of the send is passed in), set timeouts on your HTTP client (or reuse `Nowo\SmsKitBundle\Http\ProviderHttpClient`, which applies the guard, the timeouts and error masking), never put credentials in exception messages, and contribute your credentials through your own settings section (`SettingField::secret()`).

## Send-test action

With `test_action.enabled` (default), the `sms` section page shows **Send a test SMS** next to Save. The button is a POST UiKit action carrying a FormKit CSRF-only form; `SendTestSmsController`:

1. checks the InstanceSettings access checker for the `sms` area (`security.access_roles`, `security.area_roles.sms`, or your custom checker) — 401 / 403 otherwise;
2. validates the CSRF token (`nowo_sms_kit_send_test`) — 403 otherwise;
3. consumes the rate limiter (`test_action.rate_limit`, per user, or per client IP when anonymous);
4. sends `test_sms.body` to the saved **test recipient** through the active provider;
5. redirects (303) to the section page with a flash message (`Test SMS sent to +15*******23 via log (status: logged).`, or the bundle-written reason plus a **reference**; the provider / transport detail is logged with that reference, never flashed).

Import the route with the `nowo_sms_kit` loader ([INSTALLATION](INSTALLATION.md#manual-setup-without-flex)); it is never mounted by `routing.controllers`.

## Command line

```bash
php bin/console nowo:sms:send +15550100123 "Hello from the command line"
php bin/console nowo:sms:send +15550100123 "Hi" --from=Acme --device-id=0f1e2d3c --client-message-id=ticket-42
```

Prints the provider and whether it is configured, then `Sent via <provider> to +15*******23: id=<id> status=<status>`; exits 1 when the provider is not configured or the send fails.

## AuthKit password-reset adapter

```yaml
nowo_sms_kit:
    auth_kit:
        password_reset:
            enabled: true
            phone_property: phone      # User::getPhone(), User::phone() or public $phone (E.164)
```

`SmsPasswordResetNotifier` decorates AuthKit's `PasswordResetNotifierInterface` alias: AuthKit calls it, it calls your notifier (the e-mail one) and then, when the delivery mode includes a **code** (`nowo_auth_kit` `password_reset.delivery_mode: code` or `both`), builds `auth_kit.password_reset.sms_body` (`%code%`) in the recipient locale for the number stored on the account (AuthKit 1.31+ hands the resolved account to notifiers; the typed identifier is never used) and hands it to the configured delivery: **`deferred`** (default, sent after the response on `kernel.terminate`), `messenger` (a `SendSmsMessage` for an async transport) or `sync`. Nothing is sent without a configured provider or a valid number on the account; any failure (\Throwable) of the SMS part is logged without the code and never changes the reset response. Make sure the phone was verified before you let it receive reset codes. Replace the property lookup with `phone_resolver: App\Sms\AccountPhoneResolver` (implements `PhoneNumberResolverInterface`).

Hosts that only want to know whether SMS is available (to offer the OTP option) call `SmsSenderInterface::isConfigured()`.

## Long-running processes

Inside HTTP the configuration is rebuilt for every main request; Messenger workers reset it between messages (`kernel.reset`). A custom long-running CLI daemon keeps the configuration it read first until `kernel.reset`: call `SmsConfigResolver::reset()` periodically (or run it as a Messenger worker) so a provider or token change made by another process applies.

## Logging

| Event | Level | Context |
| --- | --- | --- |
| Message accepted | `info` `SMS sent.` | `provider`, `to` (masked), `message_id`, `status` |
| Send refused / failed | `warning` `SMS not sent.` | `provider`, `to` (masked), `error` (masked) |
| Unknown provider name | `warning` | `provider` |
| Log provider | `info` | `provider`, `message_id`, `to` / `from` (masked), `body_length` |
| AuthKit SMS failed | `warning` | `provider` |

The body (which may carry a one-time code), tokens, client keys and full numbers are never logged. Records use the `sms` Monolog channel.

## Forms and templates

The bundle ships no Twig templates and no pages: the `sms` section is rendered by the InstanceSettings settings scaffold (UiKit page contract, FormKit form types, REQ-TWIG-003 child loop) and the test button is a UiKit `Action` with a FormKit CSRF-only `FormView`. Customise the page through InstanceSettings / UiKit ([its template guide](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/TEMPLATES.md)); hosts must not reintroduce raw `<form>` / `<input>` markup when they override those templates.

## Translations

Domain **`NowoSmsKitBundle`** (`en`, `es`, `fr`, `de`, `it`, `pt`, `nl`): section and field labels, provider names, the action label, flash messages, the test message and the AuthKit SMS body. Override keys in `translations/NowoSmsKitBundle.<locale>.yaml` (see [CONFIGURATION](CONFIGURATION.md#translations)).
