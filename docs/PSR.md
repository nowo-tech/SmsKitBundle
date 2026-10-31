# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/sms-kit-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A** (or already covered by Symfony) so the decision stays auditable.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 map for package sources and tests. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | `ConfiguredSmsSender`, `LogSmsProvider` and `SmsPasswordResetNotifier` take a `Psr\Log\LoggerInterface` (default `NullLogger`, wired to `logger`, `sms` channel); masked numbers and identifiers only. |
| PSR-6 / PSR-16 Cache | **N/A** | No package-owned cache: the configuration memo is per request on purpose (it holds decrypted tokens). The rate limiter of the send-test action stores its state through Symfony RateLimiter on `cache.app`. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | HttpFoundation for the admin action; the provider calls use Symfony HttpClient options, not message objects. |
| PSR-18 HTTP client | **N/A — Symfony HttpClient is the SPI** | Providers need per-request `timeout`, `max_duration`, `max_redirects` and the guard's `resolve` pin, which PSR-18 cannot express; `Symfony\Contracts\HttpClient\HttpClientInterface` is the documented client (any implementation, e.g. `MockHttpClient` in tests). |
| PSR-11 Container | **N/A** | Constructor injection and a tagged iterator only. |
| PSR-14 Event dispatcher | **N/A (listener only)** | The bundle listens to InstanceSettings' `SettingsChangedEvent` (a kernel event listener); it dispatches no events of its own. |
| PSR-15 HTTP middleware | **N/A** | One controller; no middleware pipeline. |
| PSR-20 Clock | **N/A** | No time logic of its own; the rate limiter uses Symfony RateLimiter's clock. |

## Summary

- **Adopted beyond baseline:** PSR-3 (logger), type-hinted and wired.
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.
- **Re-evaluate** when the package gains its own cache, clock or event SPIs.

---

_REQ-CS-007 evaluation date: 2026-10-31._
