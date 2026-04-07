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
- 2026-02-12: Added folder-download demand analytics (Top 30 folders) to manager frontend: new data-layer table `{prefix}jpsm_folder_download_events`, passive instrumentation in `mv_list_folder`, and new dedicated dashboard tab with historical KPIs/table.
- 2026-02-16: Added behavior analytics foundation for strategic usage decisions: new raw/daily tables (`{prefix}jpsm_behavior_events`, `{prefix}jpsm_behavior_daily`), tracking/report/export AJAX endpoints, backend download/search telemetry hooks, and frontend manager behavior tab with month + tier/region/device filtering (MoM/YoY deltas).
- 2026-02-19: Extended behavior telemetry with transfer bytes (`bytes_authorized`, `bytes_observed`), transfer report/export endpoints, and manager transfer dashboards (90d/mensual/lifetime absolute+relative, top carpetas, cobertura).
- 2026-02-19: Added authorized-only historical backfill (`download_folder_granted_backfill`) from `{prefix}jpsm_folder_download_events` + local MediaVault index sizes to support temporal trend analysis without fabricating observed bytes.
- 2026-03-02: Implemented MediaVault BPM pipeline end-to-end: schema upgrade to index v2.1 (`bpm`, `bpm_source`), persistent override table, CSV import endpoint/UI, and global search filtering by BPM range.
- 2026-03-02: Added automatic BPM extraction batches in Synchronizer (`jpsm_auto_detect_bpm_batch`) to process pending audio rows by software (MP3 ID3 `TBPM`) without manual CSV per file.
- 2026-03-03: Extended BPM extractor with deep mode (`mode=deep`) for acoustic estimation via `ffmpeg`, added reset endpoint (`jpsm_reset_auto_bpm_scan_marks`) to reprocess `auto_*` rows safely, and wired Synchronizer UI flow to reset + run deep batches.

## Technical Decisions Log
Format:
- YYYY-MM-DD | Decision | Rationale | Impact

- 2026-02-05 | Added repository-scoped skills in `/SKILLS` and registered them in `AGENTS.md` | Standardize execution patterns for audit, auth, data, domain, and release checks in this project only | Reduces process drift across sessions and lowers risk of monolithic regressions during refactor.
- 2026-02-07 | Keep legacy options mirrored while moving core entities to custom tables | Enables immediate rollback and avoids hard cutover risk during migration | Data writes now target Phase 2 tables first with legacy fallback compatibility.
- 2026-02-07 | Bootstrap Phase 2 schema+migration on `plugins_loaded` via `JPSM_Data_Layer::bootstrap()` | Ensures table availability and idempotent migration before endpoint execution | Eliminates ordering drift and centralizes persistence initialization.
- 2026-02-07 | Keep analytics buckets normalized (`basic`/`vip`/`full`) while package registry supports five concrete tiers | Preserves historical KPI/chart continuity while enabling granular package variants in the domain model | Dashboard and persistent stats remain backward-compatible without losing new package precision in access/pricing/template logic.
- 2026-02-07 | Introduce opt-in API response envelope (`api_version=2`) rather than breaking legacy JSON payloads | Allows gradual migration of frontend and external callers while still standardizing codes/statuses | Existing UI continues working with legacy `success/data` shape; new callers can depend on stable `{ ok, code, message, data }` contract.
- 2026-02-07 | Keep CSS inline only as a fallback when shortcode assets fail to enqueue pre-head | Frontend shortcodes can render after `wp_head`, making late `wp_enqueue_style` ineffective | Default path uses enqueue; rare fallback inlines `assets/css/admin.css` to avoid broken UI when theme/shortcode detection fails.
- 2026-02-12 | Track folder-download demand in active MediaVault flow (`mv_list_folder`) instead of reviving dormant downloader module | Keeps analytics aligned with production download path and avoids cross-module drift | Manager frontend can show reliable historical Top 30 folder demand without exposing new public endpoints.
- 2026-02-16 | Keep behavior telemetry split into raw events + daily rollups with dedupe by `event_uuid` | Enables detailed funnel diagnostics while keeping dashboard/report queries stable and cheaper for monthly MoM/YoY reads | Report endpoints now consume rollups by default and can warm/rebuild ranges without blocking MediaVault interactions.
- 2026-02-19 | Keep transfer analytics under existing behavior pipeline (same raw+daily tables) and add byte columns instead of creating a parallel transfer store | Reuses validated auth/rollup/report contracts and avoids dual instrumentation complexity | New transfer reporting is now filter-compatible with tier/region/device and can coexist with behavior MoM/YoY without schema fragmentation.
- 2026-02-19 | Enforce "observed exact only" for direct egress paths and use explicit coverage KPI instead of estimation | Direct presigned traffic cannot be measured server-side reliably without proxying all downloads | Dashboard now separates authorized vs observed bytes and exposes `coverage_event_ratio` to make measurement scope auditable.
- 2026-02-28 | Adopt atomic MediaVault index sync with double-buffer tables (`primary`/`shadow`) and active-table pointer option | Prevents partial-index regressions when long syncs fail mid-run and enables richer normalized metadata ingestion without read downtime | Search/browse now read the active pointer table; sync writes to inactive table and swaps only on successful completion, preserving instrumentation continuity.
- 2026-03-02 | Persist BPM as index metadata with explicit source (`path_pattern` vs `manual_csv`) and a dedicated overrides table | Backblaze listing does not provide reliable BPM metadata, so detection must combine deterministic inference and operator corrections that survive re-syncs | MediaVault can now sort/filter by BPM while keeping access-lock funnel logic unchanged and without exposing signed URLs in browse/search payloads.
- 2026-03-02 | Add iterative software extraction for pending BPM rows instead of forcing full manual CSV curation | Catalog size can be very large; requiring manual per-file BPM input is not operationally viable | Synchronizer can now enrich BPM automatically in batches and report remaining pending rows while preserving manual CSV as highest-priority source.
- 2026-03-03 | Add optional acoustic BPM estimation (`ffmpeg` decode + onset autocorrelation) behind deep-mode batches with explicit reset step | Metadata-only extraction does not cover most catalog files; retryability is required when extraction strategy evolves | Admin can now trigger full software backfill for pending audio without manual per-file uploads; failures remain visible and recoverable (`reset_auto_bpm_scan_marks` + `first_error`/breakdown).
