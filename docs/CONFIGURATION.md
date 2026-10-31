# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Provider and sender fallbacks](#provider-and-sender-fallbacks)
- [http](#http)
  - [Timeout hierarchy (REQ-RUNTIME-001)](#timeout-hierarchy-req-runtime-001)
- [providers](#providers)
- [test_action](#test_action)
- [auth_kit](#auth_kit)
- [The sms settings section](#the-sms-settings-section)
- [Translations](#translations)
- [Profiles](#profiles)
- [Container parameters and services](#container-parameters-and-services)
- [Fail-closed checks](#fail-closed-checks)

## Full reference

```yaml
# config/packages/nowo_sms_kit.yaml (defaults shown)
nowo_sms_kit:
    default_provider: 'null'          # provider while sms.provider is not stored: null, log, twilio, sms_bridge or a host provider
    sender: null                      # sender while sms.sender is not stored (E.164 or alphanumeric id)
    http:
        timeout: 5.0                  # idle timeout (s) of a provider request, 0.1 … 60
        max_duration: 15.0            # total duration cap (s), 0.1 … 120, >= timeout
        allow_private_endpoints: false  # loopback / RFC 1918 endpoints (self-hosted SMS Bridge); metadata stays blocked
        resolve_dns: true             # resolve and pin the endpoint address (disable only for Docker service names)
        require_https: true           # refuse http:// endpoints
    providers:
        log:
            enabled: true             # register the "log" provider
        twilio:
            enabled: true
            api_base_url: 'https://api.twilio.com'
            account_sid: null         # fallbacks while the section stores nothing
            auth_token: null
            messaging_service_sid: null
        sms_bridge:
            enabled: true
            base_url: null
            api_mode: twilio          # twilio (Messages API) | native (/api/v1/sms)
            account_sid: null
            auth_token: null
            device_id: null
    test_action:
        enabled: true                 # "Send a test SMS" button + POST route (needs the InstanceSettings admin UI)
        path: /admin/settings/sms/test
        rate_limit:
            limit: 5                  # test sends per user (or per client IP when anonymous) and window
            interval: '15 minutes'    # sliding window
    auth_kit:
        password_reset:
            enabled: false            # decorate AuthKit's PasswordResetNotifierInterface
            phone_property: phone     # user property with the E.164 phone
            phone_resolver: null      # service id of a PhoneNumberResolverInterface
```

Unknown keys are rejected. Security switches (`http.allow_private_endpoints`, `http.require_https`, `http.resolve_dns`, `test_action.enabled`, `auth_kit.password_reset.enabled`) must be literal booleans: `%env(bool:…)%` placeholders are refused because they would read as `true` while the container is built.

## Provider and sender fallbacks

Every value of the `sms` section that is not stored falls back to this configuration — the place for environment variables:

```yaml
nowo_sms_kit:
    default_provider: '%env(SMS_PROVIDER)%'           # .env: SMS_PROVIDER=null
    sender: '%env(default::SMS_SENDER)%'
    providers:
        twilio:
            account_sid: '%env(default::TWILIO_ACCOUNT_SID)%'
            auth_token: '%env(default::TWILIO_AUTH_TOKEN)%'
            messaging_service_sid: '%env(default::TWILIO_MESSAGING_SERVICE_SID)%'
        sms_bridge:
            base_url: '%env(default::SMS_BRIDGE_BASE_URL)%'
            account_sid: '%env(default::SMS_BRIDGE_ACCOUNT_SID)%'
            auth_token: '%env(default::SMS_BRIDGE_AUTH_TOKEN)%'
            device_id: '%env(default::SMS_BRIDGE_DEVICE_ID)%'
```

Provider names are case-insensitive; `''`, `none`, `disabled`, `off` and `null` mean the null provider. An unknown name (a host provider that was removed) also falls back to the null provider and logs a warning. Never write a literal token in YAML.

## http

| Key | Default | Description |
| --- | --- | --- |
| `timeout` | `5.0` | Idle timeout in seconds of every provider request (HttpClient `timeout`). |
| `max_duration` | `15.0` | Total duration cap in seconds, connect included (HttpClient `max_duration`). Must be `>= timeout`. |
| `allow_private_endpoints` | `false` | Allow loopback / private-network endpoints, e.g. an SMS Bridge on the LAN or a Docker network. Cloud metadata addresses (169.254.0.0/16, fe80::/10, …) stay blocked. |
| `resolve_dns` | `true` | Resolve the endpoint host and pin the checked address (no DNS rebinding between the check and the request). Disable only when the endpoint is a Docker service name that must not be looked up. |
| `require_https` | `true` | Refuse `http://` endpoints: the Account SID / Auth Token or the client key travel in every request. |

Every request also gets `max_redirects: 0` (a redirect would be an unchecked second URL) and endpoints with user info, a query string or a fragment are refused. The guard is `nowo-tech/outbound-url-guard-bundle`'s `OutboundUrlGuard` service (its `dns_timeout` / `php_binary` apply); SmsKit passes `allow_private_endpoints` and `resolve_dns` on every call.

### Timeout hierarchy (REQ-RUNTIME-001)

| Layer | Default | Rule |
| --- | --- | --- |
| Provider request (`http.timeout` / `http.max_duration`) | 5 s idle / 15 s total | Fires first; the send fails with a transport error |
| DNS lookup of the guard (`nowo_outbound_url_guard.dns_timeout`) | 2 s | Included in the request budget |
| PHP `max_execution_time` | host | Strictly greater than `max_duration` (the demo uses 30 s) |
| Reverse proxy / Caddy write timeout | host | Strictly greater than PHP (the demo uses 40 s) |

Raise them together. Long bulk sends belong in a Messenger worker, not in a web request.

## providers

| Key | Default | Description |
| --- | --- | --- |
| `log.enabled` | `true` | Registers the `log` provider (masked metadata to the `sms` logger channel, nothing sent). Disable it in production if operators must not pick it. |
| `twilio.enabled` | `true` | Registers the `twilio` provider. |
| `twilio.api_base_url` | `https://api.twilio.com` | Twilio API base URL (a regional edge or a test double). |
| `twilio.account_sid` / `auth_token` / `messaging_service_sid` | `null` | Fallbacks of the section fields. |
| `sms_bridge.enabled` | `true` | Registers the `sms_bridge` provider. |
| `sms_bridge.base_url` / `api_mode` / `account_sid` / `auth_token` / `device_id` | `null` / `twilio` / `null` … | Fallbacks of the section fields. `api_mode` is `twilio` (Twilio-compatible Messages API, HTTP Basic with the Account SID and the client key) or `native` (`POST /api/v1/sms`, Bearer `smscli_…`). |

The null provider is always registered. Host providers: see [USAGE](USAGE.md#writing-a-provider).

## test_action

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Shows **Send a test SMS** on the `sms` section page and registers the POST route of the `nowo_sms_kit` route loader. Needs the InstanceSettings admin UI and form CSRF. |
| `path` | `/admin/settings/sms/test` | Route path. Keep it under the InstanceSettings prefix so the same firewall rule covers it. |
| `rate_limit.limit` | `5` | Messages per window and per user (hash of the user identifier; client IP hash when anonymous). |
| `rate_limit.interval` | `15 minutes` | Sliding window, any relative time `strtotime()` understands. State lives in `cache.app`. |

## auth_kit

| Key | Default | Description |
| --- | --- | --- |
| `password_reset.enabled` | `false` | Decorates `Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface` with `SmsPasswordResetNotifier`: the decorated notifier (your e-mail notifier) runs first, then the reset code (delivery modes `code` / `both`) is sent to the account phone. Requires `nowo-tech/auth-kit-bundle`. |
| `password_reset.phone_property` | `phone` | Property read through `get<Property>()`, `<property>()` or the public property of the AuthKit user. |
| `password_reset.phone_resolver` | `null` | Service id of a `PhoneNumberResolverInterface` when the number lives elsewhere. |

## The sms settings section

Contributed to InstanceSettingsBundle (section name `sms`, translation domain `NowoSmsKitBundle`):

| Field | Type | Fallback | Validation |
| --- | --- | --- | --- |
| `provider` | choice (registered providers) | `default_provider` | one of the registered names |
| `sender` | string | `sender` | E.164 or alphanumeric sender id (1–11 letters, digits, spaces) |
| `test_recipient` | string | — | E.164 |
| `twilio_account_sid` | string | `providers.twilio.account_sid` | `AC` + 32 hex |
| `twilio_auth_token` | **secret** | `providers.twilio.auth_token` | — |
| `twilio_messaging_service_sid` | string | `providers.twilio.messaging_service_sid` | `MG` + 32 hex |
| `bridge_base_url` | url | `providers.sms_bridge.base_url` | absolute http(s) URL |
| `bridge_api_mode` | choice `twilio` / `native` | `providers.sms_bridge.api_mode` | — |
| `bridge_account_sid` | string | `providers.sms_bridge.account_sid` | `AC` + 32 hex |
| `bridge_auth_token` | **secret** | `providers.sms_bridge.auth_token` | — |
| `bridge_device_id` | string | `providers.sms_bridge.device_id` | 1–64 letters, digits, dashes |

Restrict who may edit it with `nowo_instance_settings.security.area_roles.sms: [ROLE_SUPER_ADMIN]` (the send-test action checks the same area). Exports include the plain fields; tokens travel only sealed with the instance key.

## Translations

Domain `NowoSmsKitBundle`, catalogues `en`, `es`, `fr`, `de`, `it`, `pt`, `nl` under `src/Resources/translations/`. Override any key in `translations/NowoSmsKitBundle.<locale>.yaml`:

```yaml
settings:
    sms:
        send_test: 'Send me a test message'
auth_kit:
    password_reset:
        sms_body: 'Acme code: %code%'
```

## Profiles

Not applicable (REQ-CFG-001): there is one SMS configuration per instance; the runtime choice lives in the `sms` section.

## Container parameters and services

| Id | Description |
| --- | --- |
| `Nowo\SmsKitBundle\Sender\SmsSenderInterface` (public alias) | the sender |
| `Nowo\SmsKitBundle\Provider\SmsProviderRegistry` | registered providers |
| `Nowo\SmsKitBundle\Settings\SmsConfigResolver` | effective configuration (memo, `kernel.reset`) |
| `nowo_sms_kit.send_test.rate_limiter` | rate limiter factory of the send-test action |
| `nowo_sms_kit.test_action.enabled`, `nowo_sms_kit.test_action.path` | parameters |

Log records use the `sms` Monolog channel when MonologBundle is installed.

## Fail-closed checks

The container refuses to compile when:

- `test_action.enabled` is true and form CSRF is unavailable or disabled (`framework.csrf_protection`, `framework.form.csrf_protection`);
- `test_action.enabled` is true and the InstanceSettings admin UI is disabled (no access checker for the `sms` area);
- `auth_kit.password_reset.enabled` is true without `nowo-tech/auth-kit-bundle`;
- a security switch is an environment placeholder;
- two providers share a name, or a provider name is not lower snake case (at first use).
