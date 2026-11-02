# Feature Specification: provider-dependent SMS settings fields

**Feature Branch**: `feat/1.3.0`  
**Created**: 2026-11-02  
**Status**: Active (1.3.0)  

**Package**: `nowo-tech/sms-kit-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Code inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md) (no new file)  
**Depends on**: `nowo-tech/instance-settings-bundle` ^1.3 (spec 003-provider-dependent-fields), `nowo-tech/ui-kit-bundle` ^2.23

## Summary

The `sms` section lists the credentials of every bundled provider. With InstanceSettings 1.3 `visible_when`, only the
selected provider's rows are shown and saved; the other providers keep their stored values across provider switches.

## User Scenarios

### US-1 — Only the selected provider's credentials (P1)

**Given** provider `twilio`, **Then** the page shows provider, sender, test recipient and the three Twilio rows;
**Given** `sms_bridge`, the five `bridge_*` rows instead; **Given** `log` or `null`, no credential row.

### US-2 — Switching keeps values (P1)

**Given** stored SMS Bridge settings, **When** the admin selects `log` and saves, **Then** the `bridge_*` rows are not
validated nor written; selecting `sms_bridge` again shows the stored values.

### US-3 — Consistent status (P2)

`SmsConfigStatus::missingFields()` names only fields visible for the active provider.

## Functional Requirements

- **FR-SMSVIS-001** `SmsSettingsSection::getFields()`: `twilio_*` fields `withVisibleWhen('provider', ['twilio'])`,
  `bridge_*` fields `withVisibleWhen('provider', ['sms_bridge'])` when that provider is registered (else unchanged);
  `provider`, `sender`, `test_recipient` always visible.
- **FR-SMSVIS-002** `SmsConfigStatus` unchanged in behaviour; documented and tested to name only visible fields.
- **FR-SMSVIS-003** Requires `nowo-tech/instance-settings-bundle` ^1.3, `nowo-tech/ui-kit-bundle` ^2.23.

## Success Criteria

- Unit tests (`SmsProviderFieldsTest`): dependency per field, unregistered providers, status ⊆ visible fields.
- Demo Playwright e2e: switch, save, switch back, value kept; axe in both states.
- 100% coverage kept; inventory 100% of `src/`; translation parity unchanged.
