# SmsKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit and PHPStan (and Vitest when frontend exists) are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

### VI. Fail closed, secrets never leave in clear
The send-test action is private (InstanceSettings checker for the `sms` area), CSRF-protected and rate limited; the
container refuses insecure wiring (no form CSRF, no access checker, env placeholders for security switches). Provider
tokens are InstanceSettings secrets (encrypted at rest, never rendered back); credentials travel only in request
headers and never appear in logs, flashes or exception messages. Phone numbers are masked everywhere.

### VII. Worker-safe and bounded
Providers and the sender are stateless; the configuration memo is bound to the main request and reset by
`kernel.reset` and `SettingsChangedEvent`. Every provider request goes through the outbound URL guard with an idle
timeout, a total duration cap and no redirects.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-10-31 | **Last Amended**: 2026-10-31
