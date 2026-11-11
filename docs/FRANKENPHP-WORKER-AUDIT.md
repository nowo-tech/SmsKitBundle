# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/sms-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.5.0` + fix for 1.5.1 (REQ-RUNTIME-002 sweep, batch A1); first audit `1.0.0` |
| Audit date | 2026-11-11 (first audit 2026-10-31) |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker`; Igor (`composer igor`); unit test of the request-bound memo and of the `SettingsChangedEvent` reset; kernel test (save → next send uses the new provider); demo run in worker mode |
| Remediation (2026-11-11, 1.5.1) | `DeferredSmsDelivery` (default AuthKit `delivery: deferred`) is also flushed at `WorkerMessageHandledEvent` and discarded at `WorkerMessageFailedEvent`; before, a consumer run with `--no-reset` held queued SMS until it stopped (affected 1.0.0 to 1.5.0). `SmsConfigResolver` is rebuilt per Messenger message and console command (before: kept for the whole consumer without a main request) |
| **Verdict** | ✅ **Viable with kernel reset false (REQ-RUNTIME-002)** — one configuration memo bound to the main request in HTTP (rebuilt on every new request even without a reset) and rebuilt per Messenger message / console command outside HTTP, dropped by `SettingsChangedEvent`, the deferred SMS queue flushed by the bundle at every unit-of-work boundary, an immutable provider registry and the route loader's load-once flag; no request, user or message data survives a request or a Messenger message |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Boundary events](#boundary-events)
- [Worker tests](#worker-tests)
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
| Queued messages | ✅ | `DeferredSmsDelivery::$queue` (destination + body, reset code) sent and emptied at `kernel.terminate`, `WorkerMessageHandledEvent` and `console.terminate`, discarded at `WorkerMessageFailedEvent` (1.5.1: the Messenger events were missing) |
| `ResetInterface` / `kernel.reset` | ✅ | `SmsConfigResolver::reset()` and `DeferredSmsDelivery::reset()` (tag `kernel.reset`, tested) — both also correct without it (scenario B) |
| Request / user captured in services | ✅ | The controller reads the token per call; the resolver keeps a weak reference to the request only |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Outbound I/O | ✅ | Every provider request has an idle timeout, a total duration cap and no redirects |
| Resources held open | ✅ | None (HttpClient manages its connection pool) |

## Services reviewed

| Service | State | Verdict |
|---------|-------|---------|
| `ConfiguredSmsSender` | `readonly`; resolves configuration and provider per call | ✅ |
| `SmsConfigResolver` | `?SmsConfig` + `WeakReference<Request>`; rebuilt per main request, per Messenger message and console command (1.5.1); reset by `kernel.reset` and `SettingsChangedEvent` | ✅ |
| `SmsProviderRegistry` | provider map built once from the tagged iterator, never mutated | ✅ |
| `TwilioProvider`, `SmsBridgeProvider`, `LogSmsProvider`, `NullSmsProvider` | `readonly` / stateless; configuration passed per send | ✅ |
| `ProviderHttpClient`, `HttpPolicy` | `readonly` | ✅ |
| `SmsSettingsSection` | stateless (choices read from the registry on each call) | ✅ |
| `SendTestSmsController` | `readonly`; user, form and limiter created per request | ✅ |
| `SmsKitRouteLoader` | load-once flag at router warm-up | ✅ (`@igor-ignore` with reason) |
| `SmsPasswordResetNotifier`, `PropertyPhoneNumberResolver` | `readonly` | ✅ |
| `DeferredSmsDelivery` (AuthKit adapter, `delivery: deferred`, default) | `list<SmsMessage> $queue`; flushed or discarded at every boundary (see below), `reset()` sends what is left | ✅ (1.5.1) |
| `SyncSmsDelivery`, `MessengerSmsDelivery`, `SendSmsMessageHandler`, `SafeSender` | `readonly` | ✅ |
| `SendSmsCommand` | stateless | ✅ |

## Boundary events

| Service | Cleared at | Registered by |
|---------|------------|---------------|
| `SmsConfigResolver` memo | a new main request (memo keyed by a `WeakReference` to it); outside HTTP `WorkerMessageReceivedEvent`, `console.command`, `console.terminate` (1.5.1); `SettingsChangedEvent` (`sms`), `kernel.reset` | the bundle |
| `DeferredSmsDelivery::$queue` | sent at `kernel.terminate`, `WorkerMessageHandledEvent`, `console.terminate` (`flush()`) and `kernel.reset` (`reset()`); discarded at `WorkerMessageFailedEvent` (`discard()`) | the bundle (`kernel.event_listener` tags; Messenger event names as strings, Messenger optional) |

A failed message discards the SMS it queued (its work was rolled back); the retry queues it again and sends exactly one
when it is handled.

## Worker tests

Both boot **one** kernel and never call `services_resetter` (kernel reset false):

- `tests/Integration/Ddd/WithoutInstanceSettingsTest.php::testKeptAliveKernelServesManyTenantsWithoutLeakingState`:
  600 HTTP requests (`http_kernel` handle + terminate) for two tenants (acme / globex) in alternation; each send uses
  its own tenant's sender; memory margin 2 MiB after 100 warm-up requests.
- `tests/Integration/Ddd/DeferredDeliveryWorkerTest.php`: 600 Messenger messages (`WorkerMessageReceived` → AuthKit
  adapter `notify()` → `WorkerMessageHandled`) for two users in alternation, one in five failing once
  (`WorkerMessageFailed` with retry) before its retry; a failed attempt sends nothing, exactly one SMS to that
  message's user with that message's code is sent when it is handled, nothing is carried to the next message; memory
  margin 2 MiB after 100 warm-up messages. Also a failed message without retry and `console.terminate`.
- `tests/Integration/Kernel/SenderIntegrationTest.php::testTheMemoIsRebuiltPerMessengerMessageAndConsoleCommand`:
  the `SmsConfigResolver` memo is dropped at `WorkerMessageReceivedEvent`, `console.command` and `console.terminate`.

Static checks: `composer igor` and the `nowo-tech/phpstan-frankenphp` worker ruleset pass in CI.

## Usage recommendations in worker mode

- Read the active provider through `SmsSenderInterface` on every use; do not cache `isConfigured()` in your own
  long-lived services.
- Host providers must be stateless as well: take credentials from settings per send.
- Keep `http.max_duration` below PHP `max_execution_time`; send bulk messages from Messenger workers.

## Re-audit triggers

- A new service with mutable properties, a cache, or a static.
- Retries, queueing or batching inside the bundle (the deferred queue must stay flushed at every boundary above).
- Changes to the InstanceSettings memo contract.
