# Security

Bundle-specific security analysis. The short GitHub reporting policy lives in [`.github/SECURITY.md`](../.github/SECURITY.md).

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Admin action exposure](#admin-action-exposure)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Resource limits](#resource-limits)
- [CSRF](#csrf)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## Scope

Covers the sender (`ConfiguredSmsSender`), the provider registry and the bundled providers (Twilio, SMS Bridge, log,
null), the guarded provider HTTP client, the configuration resolver and the `sms` settings section, the
`nowo:sms:send` command, the send-test admin action (controller, route loader, rate limiter, compile-time guards), the
AuthKit password-reset adapter and the configuration tree. It does **not** cover the host firewall, the InstanceSettings
admin UI and storage (see that bundle's security guide), DoctrineEncryptBundle's cryptography and key storage, host
providers, the providers' own services (Twilio, SMS Bridge), or what callers put in a message body.

## Attack surface

| Input | Where | Notes |
| --- | --- | --- |
| Admin HTTP `POST` (session) | `test_action.path` (only when the host imports the `nowo_sms_kit` route loader) | InstanceSettings access checker for the `sms` area, FormKit CSRF-only form, rate limit; sends only to the saved test recipient. |
| Settings values | `sms` section (admin UI, import, code) | Validated by InstanceSettings with the field types and constraints of the section (E.164, sender id, SID patterns, absolute URL). |
| Provider endpoints | `twilio.api_base_url` (config), `bridge_base_url` (setting / config) | Every request goes through the outbound URL guard and the endpoint checks below. |
| Provider responses | Twilio / SMS Bridge | Decoded as JSON with `toArray(false)`; non-JSON bodies are ignored; error texts are masked and truncated before they reach exceptions, flashes or logs. |
| `SmsMessage` from callers | application code, AuthKit adapter, CLI | Destination and sender validated (E.164 / sender id), body trimmed and length-capped. |
| CLI arguments | `nowo:sms:send` | Operator input; same validation as code. |
| Configuration | `nowo_sms_kit.*` | Validated at compile time; security switches must be literal booleans. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **SSRF through a provider URL** (an admin or an import points `bridge_base_url` at an internal service or cloud metadata) | `nowo-tech/outbound-url-guard-bundle` on every request: private / loopback ranges refused unless `http.allow_private_endpoints` is true, cloud metadata always refused, hostnames resolved and the checked address pinned (`resolve`, no DNS rebinding), IDN / `%`-encoded hosts refused; `max_redirects: 0`; HTTPS required by default (`http.require_https`); URLs with user info, query or fragment refused. Tests cover loopback, metadata, plain http, credentials and query strings. |
| **Credential disclosure** | Auth Tokens and client keys are `SettingField::secret()` values (encrypted at rest by InstanceSettings / DoctrineEncrypt, never rendered back); config fallbacks should be `%env()%`. `SmsConfig` / `SmsFallbacks` mark them `#[SensitiveParameter]` and mask them in `__debugInfo()`. Credentials are sent only in the `Authorization` header (HTTP Basic or Bearer), never in URLs, logs, flashes or exception messages. |
| **Personal data in logs / UI** (phone numbers, one-time codes) | Logs and flash messages carry masked numbers (`+15*******23`); the body is never logged (the log provider records its length only); provider and transport error texts are passed through `Redactor` (numbers, `AC…` / `MG…` / `SM…` SIDs, `smscli_` keys masked) and truncated to 200 characters. `SmsSendResult::raw` may contain the destination: callers must not log it. |
| **Abuse of the send-test action** (SMS pumping, cost, spam to a third party) | Private by default (InstanceSettings checker for the `sms` area, `ROLE_ADMIN`), CSRF, POST only, sliding-window rate limit per user (client IP when anonymous), and the recipient is the **saved** test recipient — never request input. |
| **Unauthorized access / misconfiguration** | The container refuses to compile the action without form CSRF (pattern P2) or without the InstanceSettings access checker; the controller is not autoconfigured, so `routing.controllers` never mounts it (REQ-SF-004 rule 7, integration test); the controller checks access itself, so a custom path outside the settings prefix stays protected. |
| **Password-reset hijack through SMS** | The AuthKit adapter sends only to the number stored on the resolved account (`context->user` / `token->user`, AuthKit 1.31 contract), never to request input; nothing is sent without a code or a valid E.164 number; failures are logged without the code and do not change the reset response (no enumeration signal). |
| **Hanging requests (FrankenPHP worker / FPM pool)** | Every provider request has an idle timeout and a total `max_duration` (defaults 5 s / 15 s); the guard's DNS lookup has its own timeout. See the [timeout hierarchy](CONFIGURATION.md#timeout-hierarchy-req-runtime-001). |
| **Silent misdelivery** | The null provider is the default; an unknown provider name falls back to null with a warning instead of picking another provider; `isConfigured()` lets callers hide SMS features. |
| **Cross-request state leaks (FrankenPHP worker)** | Providers and the sender are stateless; the configuration memo is bound to the main request (`WeakReference`) and cleared by `kernel.reset` and `SettingsChangedEvent`. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). |
| **XSS** | No Twig templates in the bundle; flash messages and labels are rendered (escaped) by the InstanceSettings / UiKit scaffold. |

## Admin action exposure

The send-test action spends money and sends to a real phone. Keep it behind the firewall (`access_control` on the
InstanceSettings prefix, which contains the default `test_action.path`) **and** the InstanceSettings checker; restrict
the section to fewer people with `nowo_instance_settings.security.area_roles.sms`. Disable it with
`nowo_sms_kit.test_action.enabled: false` on instances where nobody should trigger sends from the browser. The demo uses
InstanceSettings' `allow_unauthenticated` (debug only) and the log provider, so nothing is sent.

## Secrets and cryptography

- No cryptography of its own: secrets are encrypted at rest by InstanceSettings with DoctrineEncryptBundle and exported
  only sealed with the instance key.
- Transport security is TLS through Symfony HttpClient (HTTPS required by default). HTTP Basic credentials (Twilio,
  SMS Bridge Twilio-compatible mode) and Bearer keys (SMS Bridge native mode) are sent on each request only.
- Fixtures and the demo use obvious placeholders built at runtime (`AC` + zeros, `test-token-…`, `smscli_` + zeros) and
  numbers in the reserved fictional `+1 555 01xx` range.

## Logging

- `info` "SMS sent." — provider, masked destination, provider message id, status.
- `warning` "SMS not sent." — provider, masked destination, masked error.
- `warning` unknown provider name; `warning` AuthKit SMS failure (provider only).
- `info` from the log provider — masked destination / sender, body length, generated id.
- Never logged: the body, tokens, client keys, full phone numbers, provider responses. Channel: `sms`.

## Resource limits

- One provider request per message, idle timeout + total duration cap, no redirects, no retries (retry and queueing
  belong to the caller, e.g. a Messenger handler with its own retry policy).
- Body length capped at 1600 characters; the send-test action is rate limited (5 / 15 min per user by default).
- No subprocesses of its own (the guard's bounded DNS child process is part of OutboundUrlGuardBundle).

## CSRF

Applies to the send-test action (session-authenticated POST): FormKit CSRF-only form (`nowo_sms_kit_send_test`)
validated with `handleRequest()` / `isValid()`, 403 otherwise, container refuses to compile without form CSRF. The CLI
and the sender API have no session and no CSRF.

## Dependencies and updates

- Runtime dependencies: Symfony components (HttpClient, RateLimiter, Form, Validator, Security CSRF, …),
  InstanceSettingsBundle (with Doctrine and DoctrineEncrypt), OutboundUrlGuardBundle, FormKit, UiKit. CI runs
  `composer audit --locked` on every matrix cell; Dependabot groups Composer and GitHub Actions updates.
- Security fixes are released as patch versions and noted in the changelog.

## Release security checklist (12.4.1)

| Item | How it is satisfied |
| --- | --- |
| SECURITY.md | This document + `.github/SECURITY.md`. |
| `.env` in `.gitignore` | Root and demo `.gitignore` exclude `.env`, `.env.local`, `.env.dev`, key files. |
| No secrets | Placeholders built at runtime in fixtures; the demo uses the log provider and a generated key. |
| Safe config / recipe | Recipe ships provider `null`, HTTPS-only endpoints, the rate-limited action and commented `%env()%` fallbacks. |
| Input / output | E.164 / sender / body validation, section constraints, masked and truncated provider errors. |
| Dependencies | `composer audit --locked` in CI. |
| No-secret logs | See [Logging](#logging). |
| Cryptography | Delegated to InstanceSettings / DoctrineEncrypt; TLS for transport. |
| Permissions / exposure | Opt-in route, InstanceSettings checker for the `sms` area, CSRF, rate limit, fail-closed compile checks. |
| Limits / DoS | Provider timeouts, no redirects, body cap, rate limit. |
| SSRF | Outbound URL guard on every request, HTTPS required, private endpoints opt-in. |
| AI security audit (REQ-SEC-004) | Pending independent audit before the first tag — see below. |

## AI security audit (REQ-SEC-004)

Not yet audited. The first release (1.0.0) is blocked until an independent AI security audit records a passing grade
in the monorepo `BUNDLES_SECURITY_ANALYSIS.md`.

## Reporting a vulnerability

Please follow [`.github/SECURITY.md`](../.github/SECURITY.md) — do not open a public issue.
