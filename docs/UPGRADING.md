# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.1.0](#to-110)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app SMS service](#migrating-from-an-in-app-sms-service)
  - [Breaking changes](#breaking-changes)

## Unreleased

## To 1.1.0

From **1.0.0**:

```bash
composer update nowo-tech/sms-kit-bundle
```

The bundle now requires `nowo-tech/instance-settings-bundle` ^1.1 and `nowo-tech/ui-kit-bundle` ^2.21:

```bash
composer update nowo-tech/sms-kit-bundle nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle --with-dependencies
```

With `delivery: messenger`, the new `auth_kit.password_reset.messenger_failures: rethrow` (opt-in) lets send failures
reach the Messenger retry strategy and failure transport. The default `log` keeps the 1.0.0 behaviour. See
[CONFIGURATION.md](CONFIGURATION.md#messenger-delivery).

## To 1.0.0

Initial release: there is nothing to upgrade from.

### Requirements

- PHP `>=8.2 <8.6`, Symfony 7.4 / 8.0 / 8.1.
- `nowo-tech/instance-settings-bundle` ^1.0 installed and configured (Doctrine table, DoctrineEncrypt key, admin UI).
- `framework.http_client`, `framework.csrf_protection` and `framework.form.csrf_protection` enabled.

### Enable and configure

1. `composer require nowo-tech/sms-kit-bundle` (Flex registers the bundles and copies the recipe).
2. Import the send-test route (opt-in):

   ```yaml
   # config/routes/nowo_sms_kit.yaml
   nowo_sms_kit:
       resource: .
       type: nowo_sms_kit
   ```

3. Optionally map your environment variables as fallbacks ([CONFIGURATION](CONFIGURATION.md#provider-and-sender-fallbacks)).
4. Open `/admin/settings/sections/sms`, choose the provider, save, send a test message.

### Migrating from an in-app SMS service

Applications that carry their own `SmsSenderInterface` / `ConfiguredSmsSender` copy (SMS fields on a settings entity,
`SMS_PROVIDER` / `TWILIO_*` / `SMS_BRIDGE_*` variables):

| In-app | SmsKit |
| --- | --- |
| `App\…\SmsSenderInterface::send(SmsOutboundMessage)` | `Nowo\SmsKitBundle\Sender\SmsSenderInterface::send(SmsMessage)` (`to`, `body`, `from`, `deviceId`, `clientMessageId`, `statusCallbackUrl`) |
| `SmsOutboundMessage::$toE164` / `$fromE164` | `SmsMessage::$to` / `$from` |
| `SmsSendResult` (`providerId`, `providerMessageId`, `status`, `raw`) | same shape, `Nowo\SmsKitBundle\Message\SmsSendResult` |
| `SmsSendException` | `Nowo\SmsKitBundle\Exception\SmsSendException` |
| `app:sms:send` | `nowo:sms:send` (same arguments; also `--client-message-id`) |
| Settings entity columns `smsProvider`, `twilio*`, `smsBridge*` | the `sms` section (`provider`, `sender`, `twilio_*`, `bridge_*`, `test_recipient`) |
| `TWILIO_FROM` / `SMS_BRIDGE_FROM` | one `sender` (setting or `nowo_sms_kit.sender`) |

Copy the stored values once (a migration or a console command calling `InstanceSettingsInterface::save('sms', …)`;
tokens through the same call, they are encrypted on flush), then drop the old columns. Differences to check:

- E.164 validation and normalization are the same (`^\+[1-9]\d{6,14}$`, whitespace removed); the sender now also accepts
  alphanumeric sender ids.
- SMS Bridge: the API surface follows `bridge_api_mode` strictly (`twilio` needs the Account SID, `native` a `smscli_`
  key); an in-app copy that silently switched to the native API when no Account SID was set must set `native`.
- Provider endpoints go through the outbound URL guard: a bridge on a private network needs
  `http.allow_private_endpoints: true` (and `http.require_https: false` for plain HTTP).
- Unknown or empty provider names mean the null provider (as before); bodies longer than 1600 characters are refused.
- Destinations in premium / satellite / international-network ranges (`+870`, `+878`, `+881`, `+882`, `+883`, `+888`,
  `+979`, `+991`, `+808`) are refused by default (`nowo_sms_kit.destinations`).
- The AuthKit reset SMS is sent after the response (`delivery: deferred`) instead of inside the request; exception
  messages no longer contain provider texts (`SmsSendException::getDetail()` for logs).

### Breaking changes

None (initial release).
