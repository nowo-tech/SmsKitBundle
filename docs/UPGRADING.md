# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
  - [To 1.2.0](#to-120-1)
- [To 1.1.0](#to-110)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app SMS service](#migrating-from-an-in-app-sms-service)
  - [Breaking changes](#breaking-changes)

## Unreleased

Next patch: 1.5.1. No configuration change. With the default `auth_kit.password_reset.delivery: deferred`, an SMS
queued inside a Messenger message is now sent when that message is handled, even with `messenger:consume --no-reset`.
When the message fails, the SMS it queued is **discarded** (not sent): the retry queues it again, so the user gets one
SMS for the attempt that succeeded. If a host handler relied on an SMS going out although the handler then threw,
send it from a message of its own or use `delivery: sync`. SmsKit's own `sms` configuration memo is dropped per
Messenger message and per console command. SmsKit only drops its own memo: the values come from InstanceSettings, which (1.13.0) keeps its rows in memory and
resets its private EntityManager only on `kernel.reset`. With `messenger:consume --no-reset` (kernel reset false) a
consumer therefore sees a provider or token saved by another process only with an InstanceSettings release that
clears its memo at the same boundaries (follow-up release), or after a worker restart.

## To 1.5.0

Target **1.5.0**, from **1.4.0**: `composer update nowo-tech/sms-kit-bundle`. **Standard hosts: nothing to change**
(Instance Settings registered → same services, same defaults).

1. **Without Instance Settings (opt-in, DDD hosts).** Leave `NowoInstanceSettingsBundle` (and, if nothing else needs
   them, DoctrineBundle and DoctrineEncrypt) out of `config/bundles.php`. The configuration then comes from
   `nowo_sms_kit.config_provider` (your `SmsConfigProviderInterface` service) or, when unset, from the bundle
   configuration (`default_provider`, `sender`, `providers.*`, typically `%env()%`). There is no settings page, so no
   send-test action; `nowo:sms:send` keeps working.
2. **New port (optional).** Prefer `SmsConfigStatusInterface` over `SmsConfigStatus` in health pages.
3. **Deprecation notice.** Instance Settings stays in `require` in 1.x; 2.0 moves it to `suggest`. If your
   application uses Instance Settings itself (or relies on SmsKit to install it), require
   `nowo-tech/instance-settings-bundle` explicitly now.

## To 1.4.0

From **1.3.0**:

```bash
composer update nowo-tech/sms-kit-bundle
```

Nothing changes by default.

1. **Optional configuration port.** Set `nowo_sms_kit.config_provider` to a service implementing
   `Nowo\SmsKitBundle\Settings\SmsConfigProviderInterface` to take provider, sender and credentials from your own
   store, or to `Nowo\SmsKitBundle\Settings\FallbackSmsConfigProvider` to use only the bundle configuration
   (`%env()%`). Values stored in the `sms` section are then ignored by the sender; the section page still exists.
2. Code that builds `ConfiguredSmsSender`, `SmsConfigStatus` or `SendTestSmsController` by hand with a
   `SmsConfigResolver` keeps working.
3. Instance Settings stays required (`^1.3`); this release does not need a newer one.

## To 1.3.0

From **1.2.0**:

```bash
composer update nowo-tech/sms-kit-bundle
```

From **1.2.x**: no configuration change, no BC break. Requires `nowo-tech/instance-settings-bundle` ^1.3 and
`nowo-tech/ui-kit-bundle` ^2.23:

```bash
composer update nowo-tech/sms-kit-bundle nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle --with-dependencies
```

- Register the UiKit `visible-when` Stimulus peer in the host app
  (`vendor/nowo-tech/ui-kit-bundle/src/Resources/assets/stimulus-peers/visible_when_controller.ts`, identifier
  `visible-when`) so the `sms` page shows only the selected provider's credentials. Without it every row stays visible.
- Saving the `sms` section with provider A no longer validates, overwrites or empties the fields of provider B (they
  keep their stored values). Exception: checking "clear" on a hidden token (e.g. the Twilio token after switching to
  SMS Bridge) deletes it without activating that provider; a value typed in a hidden row is ignored. To edit another
  provider's plain fields, select it first. `InstanceSettings::save()` from
  code, imports and the seed are unchanged.

## To 1.2.0

From **1.1.0**:

```bash
composer update nowo-tech/sms-kit-bundle
```

### To 1.2.0

From **1.1.x**. The bundle now requires `nowo-tech/instance-settings-bundle` ^1.2:

```bash
composer update nowo-tech/sms-kit-bundle nowo-tech/instance-settings-bundle --with-dependencies
```

No configuration change, no BC break: the `%env()%` fallbacks keep working. New:

- The `sms` section exposes its fallbacks to `nowo:instance-settings:seed-from-fallbacks` (DB-only mode).
- The `sms` settings page warns when the active provider misses required fields; `SmsConfigStatus` (public service)
  exposes the same check to health / status pages.
- Flex recipe 1.2 installs a DB-only configuration (no env placeholder, no `SMS_PROVIDER`).

To go DB-only, **in this order**: (1) upgrade while the `%env()%` fallbacks are still wired; (2) run
`php bin/console nowo:instance-settings:seed-from-fallbacks --section=sms` (try `--dry-run` first); (3) only then remove
`default_provider` / `sender` / `providers.*` placeholders and the `SMS_*`, `TWILIO_*`, `SMS_BRIDGE_*` env vars. Removing
them first would seed nothing. Run `composer recipes:update nowo-tech/sms-kit-bundle` (recipe 1.2, no env
placeholders) only **after** the seed: applied before, SMS falls to the `null` provider and the `sms` page shows "No SMS
provider is chosen yet" (`SmsConfigStatus::isProviderUnset()`). From a Doctrine migration, call the seeder from a non-transactional migration (see
InstanceSettings [USAGE](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/USAGE.md#calling-the-seeder-from-a-migration)).

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
