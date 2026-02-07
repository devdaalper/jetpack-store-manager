---
name: jpsm-architecture-audit
description: Audit this repository architecture by mapping endpoints, data stores, sessions, and technical risks; use when planning refactors, diagnosing fragility, or preparing risk reports and inventory docs.
---

# JPSM Architecture Audit

Use this skill when auditing the codebase or before any major refactor.

## Steps
1. Inventory endpoints
- Find all `wp_ajax_*` and REST routes.
- Record name, auth checks, inputs, outputs, and side effects.
- Update or create `docs/ENDPOINTS.md`.

2. Inventory data stores
- List `wp_options` keys used as storage.
- List custom tables and schema versions.
- Update or create `docs/DATA_STORES.md`.

3. Inventory sessions and cookies
- List cookies, who sets them, and how they are verified.
- Update or create `docs/SESSIONS.md`.

4. Map critical flows
- Sale -> Email -> Log -> Access
- Login/Session -> Tier resolution
- MediaVault browse/search -> Presigned URL -> Download

5. Risk summary
- Output top risks with file references and priority.
- Note any duplicated logic or missing auth checks.

## Output
- Short summary and prioritized findings.
- Updated docs in `docs/` for inventories.
