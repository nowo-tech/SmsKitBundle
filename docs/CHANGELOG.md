# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

