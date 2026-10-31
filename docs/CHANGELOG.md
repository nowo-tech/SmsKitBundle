# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

First release (1.0.0): SMS sending on top of InstanceSettingsBundle.

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
