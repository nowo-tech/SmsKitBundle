# Feature Specification: DB-only mode (seed from fallbacks, missing-credentials warning)

**Feature Branch**: `feat/db-only-seed`  
**Created**: 2026-11-02  
**Status**: Active (1.2.0)  

**Package**: `nowo-tech/sms-kit-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Code inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md)  
**Depends on**: `nowo-tech/instance-settings-bundle` ^1.2 (spec 002-seed-from-fallbacks)

## Summary

Instances can keep every SMS setting in the `sms` instance settings section and set no environment variable. Hosts
that used `%env()%` fallbacks copy them once with `nowo:instance-settings:seed-from-fallbacks` before removing them.
The settings page and a status service warn when the active provider misses required fields.

## User Scenarios

### US-1 — Seed the env fallbacks once (P1)

**Given** `default_provider`, `sender` and `providers.*` mapped to `%env()%`, **When** the host runs the seed command,
**Then** each non-empty value is stored where the `sms` field has no value (tokens encrypted), a `null` default
provider and the Twilio API base URL are not seeded, the API mode is seeded only with a bridge URL, and the output
lists field names only. A second run writes nothing.

### US-2 — Missing credentials warning (P1)

**Given** an active provider other than `null` with a required field empty (stored and fallback), **When** an admin
opens `/admin/settings/sections/sms`, **Then** a warning names the missing fields; `SmsConfigStatus::missingFields()`
returns the same names for host health pages.

### US-3 — DB-only installation (P2)

Flex recipe 1.2 configures no env placeholder; sending stays disabled until a provider is chosen in the admin UI.

## Functional Requirements

- **FR-DBO-001** `SmsSettingsSection` implements `SectionFallbackProviderInterface` over `SmsFallbacks`
  (secrets as `SettingFallback::secret()`).
- **FR-DBO-002** `SmsSettingsSection` implements `SettingsSectionNoticesInterface`: one
  `settings.sms.notice_missing` message (`%provider%`, `%fields%`), lazily resolved through a service closure (the
  status reads the store the section belongs to).
- **FR-DBO-003** `SmsConfigStatus` (public): `provider()`, `missingFields()` (Twilio: account SID, auth token, sender
  or messaging service; SMS Bridge: URL, client key, account SID in Twilio-compatible mode; other providers:
  `['provider']` when not registered or `isConfigured()` is false; an empty sender does not hide the Messaging Service
  SID), `hasMissingFields()`, `isProviderUnset()` (provider `null` and `sms.provider` not stored → page notice
  `settings.sms.notice_no_provider`).
- **FR-DBO-004** Docs: DB-only mode (USAGE), seed order (UPGRADING), recipe 1.2.

## Success Criteria

- Unit tests for the fallbacks, the notice and every status branch; kernel test: seed command with env placeholders,
  secret encrypted at rest and never printed, idempotent; warning rendered on the page.
- 100% coverage gate and translation parity (7 locales) kept; inventory 100% of `src/`.
