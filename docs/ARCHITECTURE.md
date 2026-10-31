# Architecture

## Table of contents

- [Overview](#overview)
- [Send flow](#send-flow)
- [Configuration resolution](#configuration-resolution)
- [Send-test action](#send-test-action)
- [AuthKit adapter](#authkit-adapter)
- [Source layout](#source-layout)

## Overview

```
caller ──► SmsSenderInterface (ConfiguredSmsSender)
              │  current SmsConfig ◄── SmsConfigResolver ◄── InstanceSettingsInterface (`sms` section)
              │                                        └── SmsFallbacks (nowo_sms_kit.* / env)
              ▼
         SmsProviderRegistry ──► SmsProviderInterface (null | log | twilio | sms_bridge | host)
                                        │
                                        ▼
                                 ProviderHttpClient ──► OutboundUrlGuard ──► Symfony HttpClient
```

The bundle depends on InstanceSettingsBundle for storage, encryption and the admin page; it does not depend on any
other kit (MailKit, …).

## Send flow

1. `ConfiguredSmsSender::send()` asks `SmsConfigResolver::current()` for the effective `SmsConfig`.
2. The registry returns the provider named by `SmsConfig::$provider` (unknown → null provider + warning).
3. The provider must be configured for that `SmsConfig`, else `SmsSendException`.
4. The message is normalized: E.164 destination checked by the `DestinationPolicy` (denied prefixes, allowed country
   codes, budgets), sender (`from` → `sms.sender` → `nowo_sms_kit.sender`), trimmed body (1–1600 characters), device /
   client ids and a public https status callback.
5. The provider sends; HTTP providers go through `ProviderHttpClient` (endpoint checks, guard + DNS pin, timeouts,
   no redirects, error mapping and masking).
6. The sender logs `SMS sent.` / `SMS not sent.` with the masked destination and rethrows failures.

## Configuration resolution

`SmsConfigResolver` reads every `sms.<field>` with `InstanceSettingsInterface::get()` / `getSecret()` (one query per
request, decrypted on load by DoctrineEncrypt) and falls back to `SmsFallbacks` for empty values. The result is
memoized for the current main request (`WeakReference`), and reset by `kernel.reset` and by `SettingsChangedEvent`
for the `sms` section, so a save in the admin UI, an import or a `save()` in code applies to the next send.

## Send-test action

`SmsSettingsSection` implements InstanceSettings' `SettingsSectionActionsInterface` and returns a POST UiKit `Action`
with a FormKit CSRF-only `FormView`. The InstanceSettings settings scaffold renders it next to Save.
`SendTestSmsController` (route from `SmsKitRouteLoader`, not autoconfigured) checks
`InstanceSettingsAccessCheckerInterface::canAccess($user, 'sms')`, the CSRF token, the rate limiter
(`RateLimiterFactory` on `cache.app`), sends to the saved test recipient and redirects to the section page with a flash.
`TestActionPass` refuses to compile without form CSRF.

## AuthKit adapter

`SmsPasswordResetNotifier` decorates `Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface` (`.inner` first),
then hands `PasswordResetTokenResult::code()` for `PhoneNumberResolverInterface::resolve($context->user ?? $token->user)`
to a `SmsDeliveryInterface`: `DeferredSmsDelivery` (default, sent on `kernel.terminate`), `MessengerSmsDelivery` or
`SyncSmsDelivery`, all through `SafeSender` (never throws).

## Source layout

| Directory | Responsibility |
| --- | --- |
| `src/Sender` | public sender contract and implementation |
| `src/Provider` | provider contract, registry, bundled providers |
| `src/Http` | guarded HTTP client and its policy |
| `src/Settings` | `sms` section, effective configuration, resolver, fallbacks |
| `src/Message`, `src/Exception` | value objects and exceptions |
| `src/Phone`, `src/Log` | E.164 / sender id helpers, redaction |
| `src/Command`, `src/Controller`, `src/Routing` | CLI, send-test action, route loader |
| `src/AuthKit` | password-reset adapter and phone resolvers |
| `src/DependencyInjection` | configuration tree, extension, compiler passes |
| `src/Resources/translations` | `NowoSmsKitBundle` catalogues |
