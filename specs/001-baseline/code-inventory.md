# Code inventory — 001-baseline

**Last audited**: 2026-10-31
**Scope**: every production file under `src/` (`find src -type f | wc -l`). Tests, demo and generated caches are out of scope.

## Bundle and dependency injection

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoSmsKitBundle.php` | Bundle / DI | FR-DI-001 |
| `src/DependencyInjection/Configuration.php` | Bundle / DI | FR-DI-002 |
| `src/DependencyInjection/NowoSmsKitExtension.php` | Bundle / DI | FR-DI-003 |
| `src/DependencyInjection/Compiler/TestActionPass.php` | Bundle / DI | FR-DI-004 |
| `src/DependencyInjection/Compiler/OutboundUrlGuardPass.php` | Bundle / DI | FR-DI-005 |

## Sender, messages and exceptions

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Sender/SmsSenderInterface.php` | Sender and messages | FR-SEND-001 |
| `src/Sender/ConfiguredSmsSender.php` | Sender and messages | FR-SEND-001 |
| `src/Message/SmsMessage.php` | Sender and messages | FR-SEND-002 |
| `src/Message/SmsSendResult.php` | Sender and messages | FR-SEND-002 |
| `src/Exception/SmsSendException.php` | Sender and messages | FR-SEND-003 |
| `src/Exception/InvalidPhoneNumberException.php` | Sender and messages | FR-SEND-003 |
| `src/Exception/DestinationNotAllowedException.php` | Sender and messages | FR-SEND-003, FR-POL-001 |

## Providers

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Provider/SmsProviderInterface.php` | Providers | FR-PROV-001 |
| `src/Provider/SmsProviderRegistry.php` | Providers | FR-PROV-002 |
| `src/Provider/TwilioProvider.php` | Providers | FR-PROV-003 |
| `src/Provider/SmsBridgeProvider.php` | Providers | FR-PROV-004 |
| `src/Provider/LogSmsProvider.php` | Providers | FR-PROV-005 |
| `src/Provider/NullSmsProvider.php` | Providers | FR-PROV-005 |

## HTTP and redaction

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Http/ProviderHttpClient.php` | HTTP and redaction | FR-HTTP-001 |
| `src/Http/HttpPolicy.php` | HTTP and redaction | FR-HTTP-002 |
| `src/Http/ResponseTooLargeException.php` | HTTP and redaction | FR-HTTP-003 |
| `src/Phone/E164.php` | HTTP and redaction | FR-RED-001 |
| `src/Phone/SenderId.php` | HTTP and redaction | FR-RED-001 |
| `src/Log/Redactor.php` | HTTP and redaction | FR-RED-001 |

## Settings

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Settings/SmsSettingsSection.php` | Settings | FR-SET-001 |
| `src/Settings/SmsConfig.php` | Settings | FR-SET-002 |
| `src/Settings/SmsFallbacks.php` | Settings | FR-SET-002 |
| `src/Settings/SmsConfigResolver.php` | Settings | FR-SET-003 |

## HTTP action and CLI

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/SendTestSmsController.php` | HTTP action and CLI | FR-ACT-001 |
| `src/Routing/SmsKitRouteLoader.php` | HTTP action and CLI | FR-ACT-002 |
| `src/Command/SendSmsCommand.php` | HTTP action and CLI | FR-CLI-001 |

## AuthKit adapter

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/AuthKit/SmsPasswordResetNotifier.php` | AuthKit | FR-AUTH-001 |
| `src/AuthKit/PhoneNumberResolverInterface.php` | AuthKit | FR-AUTH-002 |
| `src/AuthKit/PropertyPhoneNumberResolver.php` | AuthKit | FR-AUTH-002 |

## Destination policy and deliveries

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Policy/DestinationPolicy.php` | Destination policy | FR-POL-001 |
| `src/Delivery/SmsDeliveryInterface.php` | Deliveries | FR-DEL-001 |
| `src/Delivery/SafeSender.php` | Deliveries | FR-DEL-001 |
| `src/Delivery/SyncSmsDelivery.php` | Deliveries | FR-DEL-002 |
| `src/Delivery/DeferredSmsDelivery.php` | Deliveries | FR-DEL-002 |
| `src/Delivery/MessengerSmsDelivery.php` | Deliveries | FR-DEL-003 |
| `src/Delivery/SendSmsMessage.php` | Deliveries | FR-DEL-003 |
| `src/Delivery/SendSmsMessageHandler.php` | Deliveries | FR-DEL-003 |

## Translations

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/translations/NowoSmsKitBundle.en.yaml` | i18n | FR-I18N-001 |
| `src/Resources/translations/NowoSmsKitBundle.es.yaml` | i18n | FR-I18N-001 |
| `src/Resources/translations/NowoSmsKitBundle.fr.yaml` | i18n | FR-I18N-001 |
| `src/Resources/translations/NowoSmsKitBundle.de.yaml` | i18n | FR-I18N-001 |
| `src/Resources/translations/NowoSmsKitBundle.it.yaml` | i18n | FR-I18N-001 |
| `src/Resources/translations/NowoSmsKitBundle.pt.yaml` | i18n | FR-I18N-001 |
| `src/Resources/translations/NowoSmsKitBundle.nl.yaml` | i18n | FR-I18N-001 |

## Coverage summary

| Category | Files |
| --- | --- |
| Bundle and DI | 5 |
| Sender, messages, exceptions | 7 |
| Providers | 6 |
| HTTP and redaction | 6 |
| Settings | 4 |
| HTTP action and CLI | 3 |
| AuthKit adapter | 3 |
| Destination policy and deliveries | 8 |
| Translations | 7 |
| **Total production sources** | **49 / 49** (`find src -type f \| wc -l` = 49) |
