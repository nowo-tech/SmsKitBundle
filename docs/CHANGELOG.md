# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.5.1] - 2026-11-11](#151---2026-11-11)
  - [Fixed](#fixed)
  - [Tests](#tests)
  - [Documentation](#documentation)
- [[1.5.0] - 2026-11-11](#150---2026-11-11)
  - [Target 1.5.0](#target-150)
- [[1.4.0] - 2026-11-10](#140---2026-11-10)
  - [Added](#added-1)
  - [Changed](#changed-1)
  - [Documentation](#documentation-1)
- [[1.3.0] - 2026-11-02](#130---2026-11-02)
  - [Added](#added-2)
  - [Changed](#changed-2)
- [[1.2.0] - 2026-11-02](#120---2026-11-02)
  - [Added](#added-3)
  - [Changed](#changed-3)
  - [Documentation](#documentation-2)
- [[1.1.0] - 2026-11-01](#110---2026-11-01)
  - [Added](#added-4)
  - [Changed](#changed-4)
  - [Fixed](#fixed-1)
  - [Documentation](#documentation-3)
- [[1.0.0] - 2026-10-31](#100---2026-10-31)
  - [Added](#added-5)
  - [Changed](#changed-5)
  - [Security](#security)

## [Unreleased]

### Added

- The InstanceSettings tab of the SMS section now has an icon (`tabler:message-2`).

## [1.5.1] - 2026-11-11

Next patch: 1.5.1.

### Fixed

- **Deferred SMS held by Messenger consumers (REQ-RUNTIME-002):** with the default
  `auth_kit.password_reset.delivery: deferred`, `DeferredSmsDelivery` sent its queue only at `kernel.terminate`,
  `console.terminate` and `kernel.reset`. The stock `messenger:consume` runs `services_resetter` after every message,
  which sends the queue through `reset()`, so it was not affected. A consumer run with `--no-reset` (or a host without
  `services_resetter`, kernel reset false) held every SMS queued while a message was handled (for example the AuthKit
  password-reset code from a host handler) until the consumer stopped; a clean stop (`--time-limit`, `--limit`,
  `--memory-limit`, SIGTERM) sends them at `console.terminate`, a hard kill (OOM, SIGKILL) lost them. The queue is now
  sent at `WorkerMessageHandledEvent` (event names as strings, `symfony/messenger` stays optional). Affected:
  **1.0.0 to 1.5.0** with the AuthKit adapter, the default `deferred` delivery and a consumer run with `--no-reset` or
  without `services_resetter`. HTTP requests and console commands were not affected.
- **A failed Messenger message no longer sends its deferred SMS:** at `WorkerMessageFailedEvent` the queue of that
  message is discarded (`DeferredSmsDelivery::discard()`, a notice logs only the count). Its work was rolled back (for
  example the reset token under `doctrine_transaction`), so the SMS would carry a code that does not exist, and each
  retry sent one more. The retry queues it again and sends exactly one when it is handled. Before, the stock consumer
  sent it through `services_resetter` after the failure.
- **`SmsConfigResolver` memo rebuilt outside HTTP:** without a main request the configuration memo was kept for the
  whole consumer or command (only `SettingsChangedEvent` in the same process or `kernel.reset` cleared it), so with
  kernel reset false a consumer kept the old provider, sender and credentials after an admin changed them in another
  process (also after disabling SMS). SmsKit now drops its own memo at `WorkerMessageReceivedEvent`,
  `console.command` and `console.terminate`. Configuration only, no user data. SmsKit only drops its own memo: the values come from InstanceSettings, which (1.13.0) keeps its rows in memory and
  resets its private EntityManager only on `kernel.reset`. With `messenger:consume --no-reset` (kernel reset false) a
  consumer therefore sees a provider or token saved by another process only with an InstanceSettings release that
  clears its memo at the same boundaries (follow-up release), or after a worker restart.

### Tests

- `tests/Integration/Ddd/DeferredDeliveryWorkerTest.php`: one kept-alive kernel, no services resetter, 600 Messenger
  messages for two users through the AuthKit adapter, one in five failing once and retried; a failed attempt sends
  nothing, each message's SMS is sent exactly once, to its own user with its own code, when the message is handled;
  memory margin 2 MiB; failed-without-retry and console terminate cases.
- `SenderIntegrationTest::testTheMemoIsRebuiltPerMessengerMessageAndConsoleCommand` and `DeliveryTest` (`discard()`).

### Documentation

- `docs/FRANKENPHP-WORKER-AUDIT.md` refreshed for 1.5.1 (`DeferredSmsDelivery`, boundary events, worker tests);
  CONFIGURATION and USAGE list the Messenger boundary.

## [1.5.0] - 2026-11-11

Instance Settings and Doctrine are optional when the host provides the configuration ports; standard hosts unchanged. See [UPGRADING](https://github.com/nowo-tech/SmsKitBundle/blob/v1.5.0/docs/UPGRADING.md#to-150).

### Target 1.5.0

Architecture bar W2 (standard Symfony by default, strict DDD opt-in): rule 8 (configuration through provider ports)
and rule 1.

#### Added

- Instance Settings is optional: without `NowoInstanceSettingsBundle` registered, the bundle boots without it (and
  without Doctrine). `SmsConfigResolver`, the `sms` section and the send-test action are then not registered, and
  the configuration comes from `config_provider` (a host service) or, by default, from the bundle configuration
  (`FallbackSmsConfigProvider`). `test_action.enabled` is ignored without Instance Settings (the button lives on its
  settings page; the effective value is in the `nowo_sms_kit.test_action.enabled` parameter).
- `Settings\SmsConfigStatusInterface` (`isProviderUnset()`, `provider()`, `missingFields()`, `hasMissingFields()`),
  aliased to `SmsConfigStatus`, for host health / status pages.
- Container parameter `nowo_sms_kit.instance_settings` (whether Instance Settings is registered).

#### Changed

- Standard hosts (Instance Settings registered): unchanged services and defaults.
- `composer.json` keeps `nowo-tech/instance-settings-bundle` in `require` until 2.0 (it moves to `suggest` then); the
  code path is optional now.

## [1.4.0] - 2026-11-10

Target 1.4.0. DDD / hexagonal P2 fix (audit 2026-11-09, SM-D1).

### Added

- `Settings\SmsConfigProviderInterface::current(): SmsConfig`: the configuration source of a send is a port.
  `SmsConfigResolver` implements it and stays the default. New option `nowo_sms_kit.config_provider` (service id,
  null = the settings resolver).
- `Settings\FallbackSmsConfigProvider`: reads only the bundle configuration (`default_provider`, `sender`,
  `providers.*`), never the `sms` section.

### Changed

- `ConfiguredSmsSender`, `SmsConfigStatus` and `SendTestSmsController` take a `SmsConfigProviderInterface` (a
  `SmsConfigResolver` still fits). With a custom `config_provider`, `SmsConfigStatus::isProviderUnset()` is always
  false (there is no settings store to ask).

### Documentation

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

## [1.3.0] - 2026-11-02

provider-dependent Twilio / SMS Bridge fields (InstanceSettings 1.3 visible_when)

### Added

- Provider-dependent rows in the `sms` settings section (InstanceSettings 1.3 `visible_when`): the Twilio fields
  (`twilio_account_sid`, `twilio_auth_token`, `twilio_messaging_service_sid`) show only for `twilio`, the SMS Bridge
  fields (`bridge_*`) only for `sms_bridge`; `log` / `null` show provider, sender and test recipient. Hidden rows keep
  their values: a save validates and writes only the selected provider's fields, so switching provider never loses the
  other credentials; an explicit "clear" on a hidden token still deletes it (InstanceSettings 1.3). A provider that is not registered keeps its rows always visible. `SmsConfigStatus` only names
  fields visible for the active provider (tested).
- Demo: the UiKit `visible-when` peer is loaded; Playwright e2e (switch, save, switch back; axe in both states).

### Changed

- Requires `nowo-tech/instance-settings-bundle` ^1.3 and `nowo-tech/ui-kit-bundle` ^2.23.

## [1.2.0] - 2026-11-02

DB-only SMS settings: the sms section exposes its env / YAML fallbacks to nowo:instance-settings:seed-from-fallbacks, SmsConfigStatus + page notices for missing fields or no provider, Flex recipe 1.2 without env placeholders. Requires InstanceSettings ^1.2. See [UPGRADING](https://github.com/nowo-tech/SmsKitBundle/blob/v1.2.0/docs/UPGRADING.md#to-120).

### Added

- DB-only mode: `SmsSettingsSection` implements InstanceSettings 1.2 `SectionFallbackProviderInterface` over
  `SmsFallbacks` (`default_provider` unless null, `sender`, `providers.twilio.*` except `api_base_url`,
  `providers.sms_bridge.*`), so `nowo:instance-settings:seed-from-fallbacks --section=sms` copies them once into the
  store (tokens encrypted). Flex recipe 1.2 without env placeholders. See [UPGRADING](UPGRADING.md#to-120).
- `SmsConfigStatus` (public service): required fields missing for the active provider; the `sms` settings page shows
  a warning naming them (`settings.sms.notice_missing`, 7 locales), and `isProviderUnset()` / a "no provider chosen
  yet" warning when nothing is stored and the provider resolves to `null` (`settings.sms.notice_no_provider`).

### Changed

- Requires `nowo-tech/instance-settings-bundle` ^1.2 (was ^1.1).

### Documentation

- SECURITY.md: with `password_reset.delivery: messenger` the reset code travels in the queued `SendSmsMessage`, and
  with `messenger_failures: rethrow` also in the failure transport; use an encrypted or short-TTL private transport
  (or the default `delivery: deferred`).

## [1.1.0] - 2026-11-01

Messenger delivery docs + opt-in messenger_failures: rethrow; instance-settings ^1.1, ui-kit ^2.21 See [UPGRADING](UPGRADING.md#to-110).

### Added

- `auth_kit.password_reset.messenger_failures` (`log` | `rethrow`, default `log`). With `delivery: messenger` and
  `rethrow`, send failures propagate from `SendSmsMessageHandler` with a redacted message, so the Messenger retry
  strategy and failure transport apply. Refused destinations and invalid numbers are thrown as
  `UnrecoverableMessageHandlingException` and are never retried. The default is unchanged: failures are logged, not
  retried.

### Changed

- Requires `nowo-tech/instance-settings-bundle` ^1.1 and `nowo-tech/ui-kit-bundle` ^2.21. The lock resolves
  InstanceSettings 1.1.0, UiKit 2.21.0 and DoctrineEncrypt 2.10.1.

### Fixed

- Release notes link UPGRADING with an absolute URL. `release.yml` rewrites relative `UPGRADING.md` links to the
  tagged file.

### Documentation

- CONFIGURATION / USAGE: new "Messenger delivery" section covering routing, the retry policy (`SafeSender` swallows
  failures, so Messenger never retries by default), the failure transport and idempotency (a redelivery sends a
  second SMS).
- CHANGELOG / UPGRADING: removed the release-candidate leftovers and merged the duplicate "To 1.0.0" sections. README
  coverage figures refreshed.

## [1.0.0] - 2026-10-31

First release: runtime-configured SMS sender with Twilio, SMS Bridge, log and null providers, encrypted credentials in InstanceSettings, destination policy against toll fraud, guarded provider HTTP, test CLI/action and an AuthKit password-reset SMS adapter. See [UPGRADING](https://github.com/nowo-tech/SmsKitBundle/blob/main/docs/UPGRADING.md#to-100).

### Added

- **Sender** — `SmsSenderInterface` (`send()`, `isConfigured()`, `getProviderId()`) and `ConfiguredSmsSender`: resolves the provider and its configuration on every send, validates the E.164 destination, the sender (E.164 or alphanumeric id) and the body (1–1600 characters), logs the outcome with the destination masked.
- **Provider registry** — `SmsProviderInterface` services autoconfigured with the `nowo_sms_kit.provider` tag; bundled `twilio` (Programmable Messaging), `sms_bridge` (Twilio-compatible Messages API or native `/api/v1/sms`), `log` (masked metadata to the logger) and `null` (default, disabled). Unknown provider names fall back to `null`.
- **`sms` instance settings section** — provider, sender, test recipient, Twilio and SMS Bridge credentials (Auth Token and client key as encrypted secrets), field validation (E.164, sender id, SID patterns); empty fields fall back to `nowo_sms_kit.default_provider`, `sender` and `providers.*` (environment variables).
- **Worker-safe configuration** — `SmsConfigResolver` memoizes the effective configuration for the current main request only and resets on `kernel.reset` and `SettingsChangedEvent` for `sms`.
- **Guarded provider HTTP** — `ProviderHttpClient`: outbound URL guard (`nowo-tech/outbound-url-guard-bundle`) with DNS pinning, HTTPS required, no credentials / query in endpoints, private endpoints opt-in, idle timeout and total duration cap (`http.*`), no redirects, provider errors masked and truncated.
- **Redaction** — `E164::mask()` and `Redactor` (phone numbers, Twilio SIDs, SMS Bridge client keys) for logs, flashes and exceptions.
- **CLI** — `nowo:sms:send <to> <body>` with `--from`, `--device-id`, `--client-message-id`.
- **Send-test admin action** — "Send a test SMS" POST button on the `sms` section page (UiKit action with a FormKit CSRF-only form), InstanceSettings access checker for the `sms` area, per-user rate limit (`test_action.rate_limit`), flash feedback; opt-in route through the `nowo_sms_kit` loader (REQ-SF-004 rule 7); the container refuses to compile without form CSRF or the InstanceSettings admin UI.
- **AuthKit adapter** — `SmsPasswordResetNotifier` (opt-in `auth_kit.password_reset.enabled`) decorates AuthKit's `PasswordResetNotifierInterface` and sends the reset code to the phone stored on the account (`PhoneNumberResolverInterface`, default property `phone`).
- Translations (`NowoSmsKitBundle`) in en, es, fr, de, it, pt and nl.
- Flex recipe, Symfony 8.1 FrankenPHP demo with the log provider, Playwright e2e and screenshots, Spec Kit baseline, CI on PHP 8.2–8.5 × Symfony 7.4 / 8.1 with a 100% coverage gate.

### Changed

- Requires `nowo-tech/instance-settings-bundle` **^1.0** (tagged release; no VCS repository): `SmsSettingsSection::getActions()` takes the new `SectionActionContext`, the controller redirects through `InstanceSettingsRoutes::SECTION` (to `/` when the InstanceSettings admin routes are not imported), and the send-test action no longer requires the InstanceSettings web UI (its access checker is always registered).

### Security

- REQ-SEC-004 audit BZ (Pass good / Low) findings fixed before 1.0.0:
  - AuthKit adapter: the code SMS is sent after the response by default (`auth_kit.password_reset.delivery: deferred`; also `messenger` with `SendSmsMessage` and `sync`), every `\Throwable` of the SMS part is caught and logged without the code (L1).
  - Destination / cost policy on every send: `destinations.allowed_country_codes`, default denied premium / satellite / international-network prefixes (`+870`, `+878`, `+881`, `+882`, `+883`, `+888`, `+979`, `+991`, `+808`), optional per-destination and global budgets (L2).
  - Provider responses capped at `http.max_response_bytes` (64 KiB; `Content-Length` and `on_progress` abort) (L3).
  - Provider / transport texts are no longer part of exception messages or flashes: `SmsSendException::getDetail()` for logs, a reference in the send-test flash (L4).
  - Docs: `resolve_dns: false` does not check hostnames against metadata; the recipe keeps it on and a warning is logged when it meets `allow_private_endpoints` (L5).
  - `Redactor` masks whole `smscli_` keys and drops text after CR / LF; credentials with CR / LF are treated as not configured; E.164 / sender / SID / id patterns anchored with `D`; caller `statusCallbackUrl` (public https, outbound URL guard), `deviceId` and `clientMessageId` validated (I1, I2, I7).
  - Demo: e2e `sharp` 0.35.x, port bound to 127.0.0.1 (I8, I9).

