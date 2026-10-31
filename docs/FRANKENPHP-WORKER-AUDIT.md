# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/sms-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `feat/1.0.0`) |
| Audit date | 2026-10-31 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker`; Igor (`composer igor`); unit test of the request-bound memo and of the `SettingsChangedEvent` reset; kernel test (save → next send uses the new provider); demo run in worker mode |
| **Verdict** | ✅ **Viable** — one configuration memo bound to the main request (rebuilt on every new request even without a reset, dropped by `SettingsChangedEvent`), an immutable provider registry and the route loader's load-once flag; no request, user or message data survives a request |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once per worker and serves many requests with the same container. Two
scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs.**
- **B — no reset at all:** any state kept in a service survives into the next request.

A bundle safe under **B** is safe under **A**, classic mode and PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `SmsConfigResolver` memo, bound to the main request (`WeakReference`), cleared by `kernel.reset` and `SettingsChangedEvent` (section `sms`); route loader load-once flag (cache warm-up) |
| Static properties / `static` locals | ✅ | None (helpers `E164`, `SenderId`, `Redactor` are pure static functions) |
| `ResetInterface` / `kernel.reset` | ✅ | `SmsConfigResolver::reset()` (tag `kernel.reset`) — also correct without it (scenario B) |
| Request / user captured in services | ✅ | The controller reads the token per call; the resolver keeps a weak reference to the request only |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Outbound I/O | ✅ | Every provider request has an idle timeout, a total duration cap and no redirects |
| Resources held open | ✅ | None (HttpClient manages its connection pool) |

## Services reviewed

| Service | State | Verdict |
|---------|-------|---------|
| `ConfiguredSmsSender` | `readonly`; resolves configuration and provider per call | ✅ |
| `SmsConfigResolver` | `?SmsConfig` + `WeakReference<Request>`; reset by `kernel.reset` and `SettingsChangedEvent` | ✅ |
| `SmsProviderRegistry` | provider map built once from the tagged iterator, never mutated | ✅ |
| `TwilioProvider`, `SmsBridgeProvider`, `LogSmsProvider`, `NullSmsProvider` | `readonly` / stateless; configuration passed per send | ✅ |
| `ProviderHttpClient`, `HttpPolicy` | `readonly` | ✅ |
| `SmsSettingsSection` | stateless (choices read from the registry on each call) | ✅ |
| `SendTestSmsController` | `readonly`; user, form and limiter created per request | ✅ |
| `SmsKitRouteLoader` | load-once flag at router warm-up | ✅ (`@igor-ignore` with reason) |
| `SmsPasswordResetNotifier`, `PropertyPhoneNumberResolver` | `readonly` | ✅ |
| `SendSmsCommand` | stateless | ✅ |

## Usage recommendations in worker mode

- Read the active provider through `SmsSenderInterface` on every use; do not cache `isConfigured()` in your own
  long-lived services.
- Host providers must be stateless as well: take credentials from settings per send.
- Keep `http.max_duration` below PHP `max_execution_time`; send bulk messages from Messenger workers.

## Re-audit triggers

- A new service with mutable properties, a cache, or a static.
- Retries, queueing or batching inside the bundle.
- Changes to the InstanceSettings memo contract.
