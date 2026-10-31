# Feature Specification: SmsKitBundle baseline (1.0.0)

**Feature Branch**: `001-baseline`
**Created**: 2026-10-31
**Status**: Baseline (documents the whole product at 1.0.0)
**Input**: Extraction of the SMS sender of an application (`src/Shared/Sms/**` + SMS settings fields) into a reusable kit on top of InstanceSettingsBundle.

## Summary

`nowo-tech/sms-kit-bundle` (config root `nowo_sms_kit`) guarantees integrators:

- one public service, `Nowo\SmsKitBundle\Sender\SmsSenderInterface`, that sends an `SmsMessage` through the provider active **now**;
- a registry of `SmsProviderInterface` services (bundled `twilio`, `sms_bridge`, `log`, `null`; host providers by tag);
- an `sms` InstanceSettings section holding the provider, sender, test recipient and credentials (secrets encrypted);
- safe provider HTTP (outbound URL guard, HTTPS, timeouts, no redirects) and masked logging;
- an operator test path (`nowo:sms:send`, "Send a test SMS" admin action) and an opt-in AuthKit password-reset adapter.

## User Scenarios & Testing

### US-1 — Send a message through the active provider (P1)

**Given** the `sms` section selects a configured provider, **when** application code calls
`SmsSenderInterface::send(new SmsMessage('+15550100123', 'Code 424242'))`, **then** the provider receives the
normalized message and the call returns the provider id, message id and status; the log has `SMS sent.` with the
destination masked and no body.

- **Given** nothing is configured, **then** `isConfigured()` is false, `getProviderId()` is `null` and `send()` throws.
- **Given** an invalid destination (`555-0100`), **then** `InvalidPhoneNumberException` is thrown and nothing is sent.
- **Given** a stored provider name that is no longer registered, **then** the null provider applies and a warning is logged.

### US-2 — Change the provider at runtime (P1)

**Given** an admin saves `provider = log` in the `sms` section, **when** the next send happens in the same worker,
**then** it uses the log provider (`SettingsChangedEvent` dropped the memo). **Given** a new main request, **then** the
configuration is rebuilt even without `kernel.reset`.

### US-3 — Twilio and SMS Bridge (P1)

**Given** Twilio credentials and a sender, **then** the request is `POST …/2010-04-01/Accounts/{AC…}/Messages.json` with
HTTP Basic and form fields `To`, `Body`, `From` (or `MessagingServiceSid`), `StatusCallback`. **Given** SMS Bridge in
`twilio` mode, **then** the same API on the bridge base URL with `DeviceId` / `ClientMessageId`; in `native` mode,
`POST /api/v1/sms` with a Bearer `smscli_` key and a JSON body. **Given** a provider error (non-2xx), **then**
`SmsSendException` carries the provider message with numbers and SIDs masked.

### US-4 — Safe outbound HTTP (P1)

**Given** an endpoint on a loopback / private address, cloud metadata, plain `http://`, or with user info / query,
**then** no request leaves and the send fails. **Given** `http.allow_private_endpoints: true` and
`http.require_https: false`, **then** a LAN bridge on `http://` is accepted (metadata still refused). **Given** the
provider does not answer within `http.timeout`, **then** the send fails with a transport error.

### US-5 — Operator test (P2)

**Given** an admin on `/admin/settings/sections/sms` with a saved test recipient, **when** they press "Send a test SMS",
**then** a POST with a valid CSRF token sends one message to the saved recipient and the page shows
`Test SMS sent to +15*******23 via log (status: logged).`. **Given** a forged token → 403; a user without access → 403;
an anonymous user → 401; more than `rate_limit.limit` sends in the window → "Too many test messages".
`nowo:sms:send <to> <body>` prints the masked destination, the id and status, and exits 1 on failure.

### US-6 — AuthKit password reset (P2)

**Given** `auth_kit.password_reset.enabled: true` and a delivery mode with a code, **when** AuthKit notifies a reset,
**then** the decorated notifier runs and the code is sent to the E.164 number stored on the account, in the recipient
locale. **Given** no code, no configured provider or no valid number, **then** no SMS is sent; a failed SMS is logged
and the reset flow continues.

### Edge cases

- Bodies are trimmed; empty or longer than 1600 characters are refused.
- Senders: E.164 or alphanumeric (1–11 letters, digits, spaces, at least one letter).
- Empty section fields fall back to the bundle configuration (env placeholders allowed).
- Security switches given as env placeholders are refused at compile time.

## Requirements

### Bundle / DI

- **FR-DI-001** — `NowoSmsKitBundle` registers the extension `nowo_sms_kit`, the outbound-guard fallback pass and the send-test guard pass.
- **FR-DI-002** — `Configuration` validates every key strictly with safe defaults (provider `null`, HTTPS, private endpoints off, 5 s / 15 s timeouts, rate limit 5 / 15 minutes, AuthKit off); security switches must be literal booleans.
- **FR-DI-003** — `NowoSmsKitExtension` registers providers (tag priorities 100/90/80/70), registry, HTTP client, resolver (`kernel.reset`, `SettingsChangedEvent` listener), sender (public alias), section (InstanceSettings tag), command, route loader, and — when enabled — the controller (not autoconfigured), the rate limiter and the AuthKit decorator.
- **FR-DI-004** — `TestActionPass` refuses to compile the send-test action without form CSRF.
- **FR-DI-005** — `OutboundUrlGuardPass` registers a default `OutboundUrlGuard` when the guard bundle is not registered.

### Sender and messages

- **FR-SEND-001** — `SmsSenderInterface` / `ConfiguredSmsSender`: per-call provider + configuration, configured check, message normalization, masked logging, failures rethrown.
- **FR-SEND-002** — `SmsMessage` (to, body, from, deviceId, clientMessageId, statusCallbackUrl) and `SmsSendResult` (providerId, providerMessageId, status, raw).
- **FR-SEND-003** — `SmsSendException` / `InvalidPhoneNumberException` messages never contain credentials.

### Providers

- **FR-PROV-001** — `SmsProviderInterface` (name, label, `isConfigured(SmsConfig)`, `send(SmsMessage, SmsConfig)`), autoconfigured tag.
- **FR-PROV-002** — `SmsProviderRegistry`: unique lower-snake names, lookup, ordered list.
- **FR-PROV-003** — `TwilioProvider`: Account SID + Auth Token + sender or Messaging Service SID; Messages API.
- **FR-PROV-004** — `SmsBridgeProvider`: `twilio` / `native` API modes, device and client message ids.
- **FR-PROV-005** — `LogSmsProvider` (masked metadata, `logged`) and `NullSmsProvider` (never configured).

### HTTP and redaction

- **FR-HTTP-001** — `ProviderHttpClient`: endpoint checks (absolute, HTTPS unless allowed, no user info / query / fragment), outbound URL guard + DNS pin, forced `timeout` / `max_duration` / `max_redirects: 0`, transport errors mapped, non-2xx mapped with masked truncated detail.
- **FR-HTTP-002** — `HttpPolicy` carries the `http.*` configuration.
- **FR-HTTP-003** — Responses larger than `http.max_response_bytes` (Content-Length or streamed) abort the transfer (`ResponseTooLargeException`); provider / transport texts go to `SmsSendException::getDetail()` (logs only), never to messages.
- **FR-RED-001** — `E164` (normalize, isValid, mask), `SenderId` (normalize, isNumber), `Redactor` (numbers, SIDs, client keys).

### Destination policy

- **FR-POL-001** — `DestinationPolicy`: default denied premium / satellite / international-network prefixes, optional `allowed_country_codes`, optional per-destination and global budgets; applied to every send (`DestinationNotAllowedException`).

### Deliveries (AuthKit)

- **FR-DEL-001** — `SmsDeliveryInterface` and `SafeSender` (send if configured, every \Throwable logged, never thrown).
- **FR-DEL-002** — `DeferredSmsDelivery` (default: queue, sent on `kernel.terminate` / `console.terminate`, leftovers on `kernel.reset`) and `SyncSmsDelivery`.
- **FR-DEL-003** — `MessengerSmsDelivery`, `SendSmsMessage`, `SendSmsMessageHandler` (`delivery: messenger`).

### Settings

- **FR-SET-001** — `SmsSettingsSection` (`sms`, domain `NowoSmsKitBundle`): 11 fields (2 secrets) with validation; send-test action when enabled.
- **FR-SET-002** — `SmsConfig` / `SmsFallbacks`: effective values; secrets masked in debug output.
- **FR-SET-003** — `SmsConfigResolver`: stored value, else fallback; provider-name normalization; memo bound to the main request; reset on `kernel.reset` and `SettingsChangedEvent` (`sms`).

### HTTP action and CLI

- **FR-ACT-001** — `SendTestSmsController`: access checker (`sms` area), CSRF, rate limit, saved recipient, 303 redirect with masked flash.
- **FR-ACT-002** — `SmsKitRouteLoader` (`type: nowo_sms_kit`): the route at `test_action.path`, only when enabled, load-once.
- **FR-CLI-001** — `SendSmsCommand` (`nowo:sms:send`).

### AuthKit

- **FR-AUTH-001** — `SmsPasswordResetNotifier` decorates AuthKit's notifier; code to the stored phone through the configured delivery (deferred by default); every \Throwable logged without the code.
- **FR-AUTH-002** — `PhoneNumberResolverInterface` / `PropertyPhoneNumberResolver` (getter, method or public property, E.164 only).

### i18n

- **FR-I18N-001** — `NowoSmsKitBundle` catalogues in en, es, fr, de, it, pt, nl with key parity.

## Key Entities

- **SmsMessage** — outbound message; **SmsSendResult** — provider answer.
- **SmsConfig** — effective configuration of one send; **SmsFallbacks** — bundle configuration defaults.
- **Provider** — named transport in the registry.
- **`sms` section** — InstanceSettings rows `sms.<field>` (secrets encrypted).

## Success Criteria

- **SC-001** — PHPUnit green on PHP 8.2–8.5 × Symfony 7.4 / 8.1; statement and element coverage of `src/` 100%, no exclusions.
- **SC-002** — PHPStan level 8 with the FrankenPHP classic + worker rulesets, Igor, Rector dry-run, PHP-CS-Fixer clean.
- **SC-003** — The code inventory maps **49/49** production files under `src/` (42 PHP, 7 translation catalogues).
- **SC-004** — Demo boots (HTTP 200) and the Playwright e2e sends a test SMS through the log provider.
- **SC-005** — No log record, flash or exception produced by the bundle contains a full phone number, a body or a credential (tests assert it on the sender, the log provider, the HTTP client and the action).

## Assumptions

- InstanceSettingsBundle 1.x stores the section, encrypts secrets and renders the admin page (including section actions).
- Providers' public APIs (Twilio Messages API, SMS Bridge client API) stay as documented.

## Non-goals

Incoming SMS, delivery webhooks, retries / queueing, bulk sends, a sent-message log, number entry UI, numbering-plan validation, e-mail.

## Validation

```bash
make test-coverage-100
make phpstan cs-check rector-dry igor validate-phpdoc validate-translations
make release-check
```

See [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/USAGE.md`](../../docs/USAGE.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
