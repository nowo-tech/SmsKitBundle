# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Provider and sender fallbacks](#provider-and-sender-fallbacks)
- [http](#http)
  - [Timeout hierarchy (REQ-RUNTIME-001)](#timeout-hierarchy-req-runtime-001)
- [providers](#providers)
- [destinations](#destinations)
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
        allow_private_endpoints: false  # loopback / RFC 1918 endpoints (self-hosted SMS Bridge); metadata refused while resolve_dns is on
        resolve_dns: true             # resolve, check and pin the endpoint address; keep it on (Docker service names resolve too)
        require_https: true           # refuse http:// endpoints
        max_response_bytes: 65536     # largest provider response read (1024 … 4194304); larger transfers are aborted
    destinations:
        allowed_country_codes: []     # calling codes a destination must start with, e.g. ['34', '1']; empty = every country
        denied_prefixes: ['870', '878', '881', '882', '883', '888', '979', '991', '808']   # always refused (replaces the defaults when set)
        rate_limit:
            per_destination: { enabled: false, limit: 10, interval: '1 hour' }   # budget per destination number
            global: { enabled: false, limit: 10, interval: '1 hour' }            # budget for the whole instance
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
        enabled: true                 # "Send a test SMS" button + POST route
        path: /admin/settings/sms/test
        rate_limit:
            limit: 5                  # test sends per user (or per client IP when anonymous) and window
            interval: '15 minutes'    # sliding window
    auth_kit:
        password_reset:
            enabled: false            # decorate AuthKit's PasswordResetNotifierInterface
            phone_property: phone     # user property with the E.164 phone
            phone_resolver: null      # service id of a PhoneNumberResolverInterface
            delivery: deferred        # deferred (after the response) | messenger | sync
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
| `allow_private_endpoints` | `false` | Allow loopback / private-network endpoints, e.g. an SMS Bridge on the LAN or a Docker network. Cloud metadata **literals** (169.254.0.0/16, fe80::/10, …) and well-known metadata names stay blocked; **hostnames** are checked against metadata only while `resolve_dns` is true. |
| `resolve_dns` | `true` | Resolve the endpoint host, check every answer and pin the checked address (no DNS rebinding between the check and the request). Keep it on: Docker service names resolve through Docker's DNS (with `allow_private_endpoints: true`). With `false`, hostnames are neither resolved nor checked, so a name pointing at cloud metadata (`169.254.169.254.nip.io`, attacker DNS) is **accepted**; combined with `allow_private_endpoints: true` every send logs a warning. |
| `max_response_bytes` | `65536` | Largest provider response body read: a larger `Content-Length` or a transfer that passes the cap is aborted (`on_progress`) and the send fails. |
| `require_https` | `true` | Refuse `http://` endpoints: the Account SID / Auth Token or the client key travel in every request. |

Every request also gets `max_redirects: 0` (a redirect would be an unchecked second URL) and endpoints with user info, a query string or a fragment are refused. The guard is `nowo-tech/outbound-url-guard-bundle`'s `OutboundUrlGuard` service (its `dns_timeout` / `php_binary` apply); SmsKit passes `allow_private_endpoints` and `resolve_dns` on every call.

Other notes:

- **Proxies:** Symfony HttpClient honours `HTTPS_PROXY` / `HTTP_PROXY`; a proxy resolves the host itself, so the DNS pin does not apply. Add the provider hosts to `NO_PROXY` when an outbound proxy is set, or rely on the proxy's own egress policy.
- **Ports** are not restricted: a `bridge_base_url` such as `https://host:22` is attempted (POST, fixed path, rate limited, `sms` area only). Restrict egress at the network layer when that matters.

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

## destinations

Applied by `ConfiguredSmsSender` to **every** send (application code, CLI, send-test action, AuthKit adapter), after E.164 normalisation, against SMS pumping / toll fraud and SMS bombing:

| Key | Default | Description |
| --- | --- | --- |
| `allowed_country_codes` | `[]` (every country) | Calling codes (digit strings) a destination must start with, e.g. `['34', '351']`. Set it when you know where your users are. |
| `denied_prefixes` | `870`, `878`, `881`, `882`, `883`, `888`, `979`, `991`, `808` | Prefixes always refused: Inmarsat, UPT, satellite (GMSS), international networks, premium IPRS, OCHA, ITPCS, shared cost. Setting the key replaces the list (`[]` disables it). |
| `rate_limit.per_destination` | off | `{enabled: true, limit, interval}`: messages per destination number and sliding window (key: SHA-256 of the number). |
| `rate_limit.global` | off | `{enabled: true, limit, interval}`: messages for the whole instance and window. |

Refusals raise `DestinationNotAllowedException` (a `SmsSendException`). Budgets live in `cache.app`: use a shared pool (Redis, …) when several nodes send. Also enable the provider-side controls (Twilio Geo Permissions, SMS Pumping Protection) and verify phone numbers before using them for password resets.

## test_action

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Shows **Send a test SMS** on the `sms` section page and registers the POST route of the `nowo_sms_kit` route loader. Needs form CSRF; the button shows on the InstanceSettings section page (without that admin UI the route still answers, protected, and redirects to `/`). |
| `path` | `/admin/settings/sms/test` | Route path. Keep it under the InstanceSettings prefix so the same firewall rule covers it. |
| `rate_limit.limit` | `5` | Messages per window and per user (hash of the user identifier; client IP hash when anonymous). |
| `rate_limit.interval` | `15 minutes` | Sliding window, any relative time `strtotime()` understands. State lives in `cache.app`: per node with the filesystem default, so use a shared pool in a cluster (N nodes otherwise allow N × limit). |

## auth_kit

| Key | Default | Description |
| --- | --- | --- |
| `password_reset.enabled` | `false` | Decorates `Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface` with `SmsPasswordResetNotifier`: the decorated notifier (your e-mail notifier) runs first, then the reset code (delivery modes `code` / `both`) is sent to the account phone. Requires `nowo-tech/auth-kit-bundle`. |
| `password_reset.phone_property` | `phone` | Property read through `get<Property>()`, `<property>()` or the public property of the AuthKit user. |
| `password_reset.phone_resolver` | `null` | Service id of a `PhoneNumberResolverInterface` when the number lives elsewhere. |
| `password_reset.delivery` | `deferred` | `deferred`: the code SMS is sent after the response (`kernel.terminate` / `console.terminate`), so the reset response time does not depend on the provider. `messenger`: a `SendSmsMessage` is dispatched (needs `symfony/messenger`; route it to an async transport). `sync`: sent inside the request (timing signal; tests / authenticated flows only). |

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
- `auth_kit.password_reset.enabled` is true without `nowo-tech/auth-kit-bundle`, or `delivery: messenger` without `symfony/messenger`;
- a security switch is an environment placeholder;
- two providers share a name, or a provider name is not lower snake case (at first use).
