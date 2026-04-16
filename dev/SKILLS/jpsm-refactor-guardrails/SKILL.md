---
name: jpsm-refactor-guardrails
description: Enforce refactor guardrails to prevent monolithic regressions and fragile code; use during feature work, refactors, and code reviews to keep separation of concerns and definition-of-done standards.
---

# JPSM Refactor Guardrails

Use this skill for any refactor or new feature work.

## Guardrails
- Do not add business logic in templates or view classes.
- Do not access `$_POST`, `$_GET`, or cookies in views.
- All input parsing and validation lives in controllers or services.
- All business rules live in a single domain module.
- All data access goes through a data layer (no direct `get_option` in new code).
- Prefer small services over large static classes.

## Required patterns
- New endpoints must call a shared auth function and nonce check.
- New logic must include a unit test or a smoke test update.
- If you add a new option or table, update `docs/DATA_STORES.md`.
- If you add an endpoint, update `docs/ENDPOINTS.md`.

## Definition of done
- No new logic in UI files.
- Tests or smoke checklist updated.
- Docs updated for endpoints or data stores.
