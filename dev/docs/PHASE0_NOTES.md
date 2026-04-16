# Phase 0 Notes

Date: 2026-02-06
Scope: Inventory and contracts baseline for refactor kickoff.

## What Was Closed
- Endpoint inventory completed across AJAX, MediaVault query actions, and dormant REST routes.
- Data-store inventory completed across `wp_options`, custom tables, transients, and dormant CPT/meta stores.
- Session/cookie map completed, including key-based request path.
- Risk summary created with priority, impact, and evidence references.

## Key Observations
- Business-critical flows are currently split across mixed surfaces (admin-ajax, init query handlers, and view-driven handlers).
- Core domain state is still option-based (`sales_log`, tiers, leads, play counts), which increases migration pressure for Phase 2.
- Auth patterns are inconsistent between handlers (nonce + capability coverage is uneven).

## Artifacts Produced
- `/docs/ENDPOINTS.md`
- `/docs/DATA_STORES.md`
- `/docs/SESSIONS.md`
- `/docs/PHASE0_RISKS.md`

## Carry-Forward for Phase 1
- Centralize auth/nonce checks before touching business logic.
- Remove frontend exposure of secret key paths.
- Converge session model to a single signed-token strategy.

## Process Notes (AI Knowledge Habit)
- Keep notes with strict, reusable structure (Context / worked / failed / next time) to reduce drift between sessions.
- Validate documentation consistency early (cross-reference endpoints, stores, sessions, and risk evidence in one pass).
- Prefer explicit file references and closure criteria in the plan log to improve handoff quality.
