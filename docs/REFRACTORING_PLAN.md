# JetPack Store Manager - Refactor Plan

Date: 2026-02-05
Owner: Team
Status: In Progress

## Purpose
Reduce fragility, improve maintainability, and enable safe growth without breaking core flows.

## Principles
- Incremental refactor, no big-bang rewrite.
- Each phase must leave the system functional.
- Migrations are reversible and safe by default.
- Prefer a single source of truth for business rules.

## Core Flows (Must Not Break)
- Sale -> Email -> Log -> Access
- User login/session -> Tier resolution
- MediaVault browse/search -> Presigned URL -> Download
- Index sync -> Search performance

## Phase 0 - Inventory and Contracts
Goals:
- List all AJAX endpoints and their auth/nonce rules.
- List all options used as storage.
- List cookies and session paths.
- Map high-risk file locations.

Deliverables:
- Endpoints table (name, auth, inputs, outputs).
- Options table (key, usage, writer/reader locations).
- Session map (cookie name, creator, validator, uses).

Status: Completed (2026-02-06)

## Phase 1 - Stabilization (Security + Errors)
Goals:
- Centralize auth checks for AJAX.
- Remove secret key exposure in frontend.
- Replace email cookie session with signed token.
- Add null guards and PHP 8 warning fixes.

Deliverables:
- Shared auth helper for AJAX.
- Signed session tokens.
- Updated endpoints using shared auth.

Status: Completed (2026-02-06)

## Phase 2 - Data Layer
Goals:
- Move sales log, user tiers, leads, play counts to tables.
- Create DAO/repository layer.
- Provide migration with fallback.

Deliverables:
- Tables created and versioned.
- Data access class with CRUD.
- Migration script + rollback notes.

Status: Completed (2026-02-07)

## Phase 3 - Domain Model (Single Source of Truth)
Goals:
- Centralize packages, tiers, pricing, templates.
- Remove string matching scattered across code.

Deliverables:
- Package registry config.
- Tier/price resolver functions.
- Updated references.

Status: Completed (2026-02-07)

## Phase 4 - API Consistency
Goals:
- Unify endpoints and responses.
- Consider REST API for new endpoints.
- Standardize errors and payloads.

Deliverables:
- Consistent response shape.
- Shared input validation.

Status: Completed (2026-02-07)

## Phase 5 - UI/Logic Separation
Goals:
- Extract heavy logic from UI classes.
- Reduce inline JS/CSS.
- Isolate view rendering.

Deliverables:
- Services for stats and access.
- Clean view templates.

Status: Completed (2026-02-07)

## Phase 6 - Tests and Release Discipline
Goals:
- Unit tests for access rules and pricing.
- Integration tests for endpoints.
- Smoke test checklist.

Deliverables:
- Test suite + baseline.
- Release checklist.

Status: Completed (2026-02-07)

## Open Questions
- Which flows are most fragile in practice?
- Any constraints on DB migrations in production?
- Preferred authentication pattern (JWT, WP user meta, custom tokens)?

## Progress Log
- 2026-02-05: Initial plan created.
- 2026-02-06: Completed Phase 0 inventories and risk mapping in `/docs/ENDPOINTS.md`, `/docs/DATA_STORES.md`, `/docs/SESSIONS.md`, and `/docs/PHASE0_RISKS.md`.
- 2026-02-06: Added closure notes and carry-forward criteria in `/docs/PHASE0_NOTES.md`.
- 2026-02-06: Implemented Phase 1 auth/session hardening with centralized `JPSM_Auth`, nonce enforcement for hardened AJAX paths, signed session cookies, and frontend key-exposure removal.
- 2026-02-06: Executed Phase 1 runtime smoke in temporary WP+SQLite environment and recorded pass/fail evidence in `/docs/SMOKE_TESTS.md`.
- 2026-02-07: Implemented Phase 2 data layer (`JPSM_Data_Layer`) with versioned schema, table CRUD, legacy migration state, and fallback-safe mirror writes.
- 2026-02-07: Updated sales/access/dashboard flows to consume the data layer for sales log, tiers, leads, and play counts.
- 2026-02-07: Added Phase 2 migration + rollback runbook in `/docs/PHASE2_MIGRATION.md` and validated runtime behavior in temporary wp-now environment (`/docs/SMOKE_TESTS.md`).
- 2026-02-07: Implemented Phase 3 domain model (`JPSM_Domain_Model`) as a single registry for packages, tiers, templates, and prices.
- 2026-02-07: Updated sales, access, admin settings/views, dashboard, and frontend permission selectors to consume registry-driven package/tier mappings.
- 2026-02-07: Executed Phase 3 runtime smoke in temporary wp-now environment and recorded evidence in `/docs/SMOKE_TESTS.md`.
- 2026-02-07: Implemented Phase 4 API contract (`JPSM_API_Contract`) with optional v2 response envelope, consistent error codes/statuses, and shared required-field validation.
- 2026-02-07: Added Phase 4 REST pilot route (`/wp-json/jpsm/v1/status`) and verified contracts via runtime smoke (`/docs/SMOKE_TESTS.md`).
- 2026-02-07: Completed Phase 5 UI/logic separation by extracting dashboard aggregates into services (`JPSM_Stats_Service`, `JPSM_Access_Service`), moving frontend dashboard rendering into `templates/`, and migrating Chart.js/login inline scripts into `assets/js/` with localized payload.
- 2026-02-07: Executed Phase 5 runtime smoke in temporary wp-now environment and recorded evidence in `/docs/SMOKE_TESTS.md`.
- 2026-02-07: Implemented Phase 6 tests + release discipline: added PHPUnit unit tests (domain model + access manager), added wp-now integration smoke script, and added release checklist (`/docs/RELEASE_CHECKLIST.md`). Validated with `composer test` and `composer integration`.

## Technical Decisions Log
Format:
- YYYY-MM-DD | Decision | Rationale | Impact

- 2026-02-05 | Added repository-scoped skills in `/SKILLS` and registered them in `AGENTS.md` | Standardize execution patterns for audit, auth, data, domain, and release checks in this project only | Reduces process drift across sessions and lowers risk of monolithic regressions during refactor.
- 2026-02-07 | Keep legacy options mirrored while moving core entities to custom tables | Enables immediate rollback and avoids hard cutover risk during migration | Data writes now target Phase 2 tables first with legacy fallback compatibility.
- 2026-02-07 | Bootstrap Phase 2 schema+migration on `plugins_loaded` via `JPSM_Data_Layer::bootstrap()` | Ensures table availability and idempotent migration before endpoint execution | Eliminates ordering drift and centralizes persistence initialization.
- 2026-02-07 | Keep analytics buckets normalized (`basic`/`vip`/`full`) while package registry supports five concrete tiers | Preserves historical KPI/chart continuity while enabling granular package variants in the domain model | Dashboard and persistent stats remain backward-compatible without losing new package precision in access/pricing/template logic.
- 2026-02-07 | Introduce opt-in API response envelope (`api_version=2`) rather than breaking legacy JSON payloads | Allows gradual migration of frontend and external callers while still standardizing codes/statuses | Existing UI continues working with legacy `success/data` shape; new callers can depend on stable `{ ok, code, message, data }` contract.
- 2026-02-07 | Keep CSS inline only as a fallback when shortcode assets fail to enqueue pre-head | Frontend shortcodes can render after `wp_head`, making late `wp_enqueue_style` ineffective | Default path uses enqueue; rare fallback inlines `assets/css/admin.css` to avoid broken UI when theme/shortcode detection fails.
