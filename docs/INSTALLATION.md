# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual setup (without Flex)](#manual-setup-without-flex)
- [Instance settings prerequisites](#instance-settings-prerequisites)
- [Firewall and access control](#firewall-and-access-control)
- [Verify](#verify)

## Requirements

- PHP `>=8.2 <8.6` (Symfony 8.x requires PHP 8.4+)
- Symfony 7.4, 8.0 or 8.1: FrameworkBundle (HttpClient enabled), RateLimiter, Form, Validator, Security CSRF, Console; **SecurityBundle** for the admin UI of InstanceSettingsBundle
- `nowo-tech/instance-settings-bundle` ^1.0 — stores the `sms` section (Doctrine ORM + `nowo-tech/doctrine-encrypt-bundle` for the encrypted tokens)
- `nowo-tech/outbound-url-guard-bundle` ^1.3 (endpoint guard), `nowo-tech/form-kit-bundle` ^2.17 (CSRF-only form of the send-test action), `nowo-tech/ui-kit-bundle` ^2.17.5 (action view model) — all installed as dependencies
- Optional: `nowo-tech/auth-kit-bundle` ^1.31 (password-reset SMS adapter)

## Install with Composer

```bash
composer require nowo-tech/sms-kit-bundle
```

## Symfony Flex recipe

The recipe lives in this repository under [`.symfony/recipe/nowo-tech/sms-kit-bundle/1.0/`](../.symfony/recipe/nowo-tech/sms-kit-bundle/1.0/) and installs:

- `config/packages/nowo_sms_kit.yaml` — safe defaults (provider `null`, HTTPS endpoints, rate-limited send-test action) with commented environment fallbacks;
- `config/routes/nowo_sms_kit.yaml` — the route loader import of the send-test action (`/admin/settings/sms/test`).

## Manual setup (without Flex)

1. Register the bundles (InstanceSettings and its dependencies first):

   ```php
   // config/bundles.php
   return [
       // …
       Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
       Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle::class => ['all' => true],
       Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
       Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
       Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle::class => ['all' => true],
       Nowo\OutboundUrlGuardBundle\NowoOutboundUrlGuardBundle::class => ['all' => true],
       Nowo\SmsKitBundle\NowoSmsKitBundle::class => ['all' => true],
   ];
   ```

   Without `NowoOutboundUrlGuardBundle` the bundle registers a default guard itself (same policy, 2 s DNS timeout).

2. Import the send-test route (opt-in; never picked up by `routing.controllers`):

   ```yaml
   # config/routes/nowo_sms_kit.yaml
   nowo_sms_kit:
       resource: .
       type: nowo_sms_kit
   ```

3. Configure the bundle (optional — defaults are safe), see [CONFIGURATION.md](CONFIGURATION.md).

4. Keep `framework.csrf_protection`, `framework.form.csrf_protection` and `framework.http_client` on (Symfony defaults).

## Instance settings prerequisites

The `sms` section is stored by InstanceSettingsBundle: create its table (Doctrine migration) and the DoctrineEncrypt key once, and import its admin routes — see the InstanceSettings [installation guide](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/INSTALLATION.md). The send-test action needs the InstanceSettings admin UI (`nowo_instance_settings.web_ui.enabled: true`, the default) for its access checker; the container refuses to compile otherwise (set `nowo_sms_kit.test_action.enabled: false` for API-only installs).

## Firewall and access control

The send-test action defaults to `/admin/settings/sms/test`, inside the InstanceSettings prefix, so the rule that protects the settings UI covers it too:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

The controller also checks the InstanceSettings access checker for the `sms` area itself (`nowo_instance_settings.security.area_roles.sms` restricts it further), so a custom `test_action.path` outside the prefix is still protected.

## Verify

```bash
php bin/console debug:router | grep nowo_sms_kit
php bin/console nowo:sms:send +15550100123 "Hello"   # fails with "not configured" until a provider is chosen
```

Open `/admin/settings/sections/sms` as an admin: the SMS section with the provider choice and the **Send a test SMS** button.
