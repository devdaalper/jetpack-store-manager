# JPSM Smoke Tests

## 2026-02-07 - Phase 6 Automated Smoke (PHPUnit + wp-now integration)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Phase 6 validation of unit tests + integration checks (real AJAX endpoints + MediaVault cookie session).

| Check | Result | Notes |
|---|---|---|
| Unit: Domain model + access rules | Pass | `composer test` passed (10 tests / 30 assertions). |
| Integration: Dashboard renders + nonces extracted | Pass | `jpsm_vars` extracted from dashboard HTML and nonces present. |
| Integration: Sale -> history -> tier | Pass | `jpsm_process_sale`, `jpsm_get_history` (v2 envelope), `jpsm_get_user_tier` verified. |
| Integration: Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON (delivery may fail locally). |
| Integration: MediaVault login cookie + search | Pass | Login POST returned `302`, cookie set, `mv_search_global` returned seeded result. |
| Integration: Index stats + sync responds | Pass | `jpsm_get_index_stats` returned expected payload; sync returned JSON (remote 403 acceptable locally). |

Note:
- PHP 8.5 reports `curl_close()` as deprecated; integration script suppresses `E_DEPRECATED` to keep output signal-focused.

## 2026-02-07 - Phase 5 Runtime Smoke (wp-now + SQLite)
Environment: `@wp-now/wp-now` temporary instance on `http://localhost:8099` (WordPress 6.9.x + SQLite).  
Scope: Runtime validation after Phase 5 UI/logic separation (services + templates + extracted JS).

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | `home=200`, admin pages `200`, MediaVault `200`; no `Fatal error`, `Parse error`, `Warning`, `Uncaught`, or `Deprecated` markers in response HTML. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` and rendered `jpsm-mobile-app` (dashboard template + localized chart payload). |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` persisted sale row; `jpsm_get_history` returned `buyer-phase5@example.com` entry (local mail failure expected). |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON response (local `wp_mail` failure expected). |
| 5. Access control page loads and returns user tier | Pass | Access page returned `200`; `jpsm_get_user_tier` returned success with `tier=4` (`vip_pelis`) for smoke buyer. |
| 6. MediaVault renders and search returns results | Pass | `/?pagename=descargas` returned `200`; `mv_search_global` returned success with one seeded result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` returned JSON (`S3 Error 403` in local env) and `jpsm_get_index_stats` returned success (`table_exists=true`). |

Note:
- Local runtime lacks production SMTP and valid B2 credentials; email delivery and remote sync failures are expected while endpoint contracts and persistence behavior remain verifiable.

## 2026-02-07 - Phase 4 Runtime Smoke (wp-now + SQLite)
Environment: `@wp-now/wp-now` temporary instance on `http://localhost:8099` (WordPress 6.9.x + SQLite).  
Scope: Runtime validation after Phase 4 API consistency work (v2 response envelope + REST pilot route).

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | Home/admin/MediaVault pages returned `200`; no `Fatal error`, `Parse error`, `Warning`, `Uncaught`, or `Deprecated` markers in response HTML. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` and rendered `jpsm-mobile-app`. |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` persisted sale row and `jpsm_get_history` included `buyer-phase4@example.com` entry (local mail failure expected). |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON response (local `wp_mail` failure expected). |
| 5. Access control page loads and returns user tier | Pass | Access page returned `200`; `jpsm_get_user_tier` returned success with `tier=4` (`vip_pelis`) for smoke buyer. |
| 6. MediaVault renders and search returns results | Pass | `/?pagename=descargas` returned `200`; `mv_search_global` returned success with one seeded result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` returned JSON (`S3 Error 403` in local env) and `jpsm_get_index_stats` returned success (`table_exists=true`). |

Contract checks (Phase 4):
- `api_version=2` envelope validated for `jpsm_get_history` and `jpsm_get_user_tier` (payload keys: `ok`, `code`, `message`, `data`).
- REST pilot route validated via `?rest_route=/jpsm/v1/status&key=...` returning `{ ok: true, code: \"status_ok\" }`.

Note:
- Local runtime lacks production SMTP and valid B2 credentials; email delivery and remote sync failures are expected while endpoint contracts and persistence behavior remain verifiable.

## 2026-02-07 - Phase 3 Runtime Smoke (wp-now + SQLite)
Environment: `@wp-now/wp-now` temporary instance on `http://localhost:8099` (WordPress 6.9.x + SQLite).  
Scope: Runtime validation after Phase 3 domain-model consolidation.

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | Home/admin/MediaVault pages returned `200`; no `Fatal error`, `Parse error`, `Warning`, `Uncaught`, or `Deprecated` markers in response HTML. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` and rendered `jpsm-mobile-app`. |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` response reported local mail failure, but `jpsm_get_history` included `buyer-phase3@example.com` with the new sale entry. |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON response (local `wp_mail` failure expected in this environment). |
| 5. Access control page loads and returns user tier | Pass | Access page returned `200`; `jpsm_get_user_tier` returned success with `tier=2` (`vip_basic`) for smoke buyer. |
| 6. MediaVault renders and search returns results | Pass | `/?pagename=descargas` returned `200`; `mv_search_global` returned success with one seeded result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` returned JSON (`S3 Error 403` in local env) and `jpsm_get_index_stats` returned success (`table_exists=true`). |

Runtime evidence summary:
- `jpsm_process_sale`: persists sale row even when local SMTP delivery fails.
- `jpsm_get_history`: confirms persisted entry for smoke buyer.
- `jpsm_get_user_tier`: resolves tier from current purchase history/domain-tier mapping.
- `mv_search_global`: success with seeded indexed row after signed user-session setup.
- `jpsm_sync_mediavault_index`: endpoint contract validated; credential-dependent sync failure captured.

Note:
- Local runtime lacks production SMTP and valid B2 credentials; email delivery and remote sync failures are expected while endpoint contracts and persistence behavior remain verifiable.

## 2026-02-07 - Phase 2 Runtime Smoke (wp-now + SQLite)
Environment: `@wp-now/wp-now` temporary instance on `http://localhost:8099` (WordPress 6.9.x + SQLite).  
Scope: Runtime validation after Phase 2 data-layer migration.

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | Home/admin/MediaVault HTML responses checked for `Fatal error`, `Warning`, `Parse error`, `Uncaught`, and `Deprecated` markers; none found. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` and rendered `jpsm-mobile-app`. |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` persisted sale row and `jpsm_get_history` returned the new `buyer-phase2@example.com` entry (email transport failed locally, but persistence path passed). |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON response (local `wp_mail` delivery failed as expected in this environment). |
| 5. Access control page loads and returns user tier | Pass | `jpsm_get_user_tier` returned success with `tier=1` and `is_customer=true` for smoke buyer. |
| 6. MediaVault renders and search returns results | Pass | `/?pagename=descargas` returned `200`; `mv_search_global` returned success with one seeded result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` responded JSON (`S3 Error 403` in local env) and `jpsm_get_index_stats` returned success. |

Runtime evidence summary:
- `jpsm_process_sale`: writes into `{prefix}jpsm_sales` and mirrored legacy option.
- `jpsm_get_history`: returns persisted sale entries from data layer.
- `jpsm_get_user_tier`: resolves tier from Phase 2 tables.
- `mv_search_global`: successful query with indexed test row.
- `jpsm_sync_mediavault_index`: endpoint contract verified (credential-dependent sync result).

Note:
- Local runtime has no production SMTP and no valid B2 credentials; email send and real S3 sync are expected to fail there while endpoint contract and persistence behavior still remain verifiable.

## 2026-02-06 - Phase 1 Runtime Smoke (Temporary WP + SQLite)
Environment: WordPress 6.9.x temporary instance on `http://127.0.0.1:8099` with SQLite drop-in.
Scope: Runtime execution of the 7-item smoke checklist after Phase 1 hardening.

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | `home=200`, dashboard `200`, no plugin warnings in runtime request log. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` with authenticated admin session. |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` returned success and `jpsm_get_history` contains the new `buyer-smoke@example.com` entry. |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON success (`Correo reenviado`). |
| 5. Access control page loads and returns user tier | Pass | Access page returned `200`; `jpsm_get_user_tier` returned success with `tier=1` for smoke user. |
| 6. MediaVault renders and search returns results | Pass | MediaVault page returned `200`; `mv_search_global` returned success with one indexed result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` responded with JSON (admin-only path verified); `jpsm_get_index_stats` responded successfully. |

Runtime evidence summary:
- `jpsm_process_sale`: success + persisted entry.
- `jpsm_resend_email`: success response.
- `jpsm_get_user_tier`: success response with expected tier payload.
- `mv_search_global`: success response with one result from seeded local index row.
- `jpsm_sync_mediavault_index`: JSON response observed (`S3 Error 403` in this local env).
- `jpsm_get_index_stats`: success response (`table_exists=true`).

Note:
- In this temporary environment, S3 sync returns `403` due local credential context. This does not block endpoint contract validation, but real B2 credential validation should still be confirmed in staging/production-like infra.

## 2026-02-06 - Initial Attempt (No Runtime WP)
Environment: Local repository only (no running WordPress instance attached at the time).

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Blocked | Runtime WP bootstrap not available in this workspace. |
| 2. Admin dashboard page renders | Blocked | Requires active WP admin session/UI. |
| 3. Register sale flow works and log entry appears | Blocked | Requires runtime AJAX + option persistence in WP. |
| 4. Resend email endpoint responds | Blocked | Requires runtime AJAX and mail subsystem. |
| 5. Access control page loads and returns user tier | Blocked | Requires runtime AJAX + authenticated admin UI. |
| 6. MediaVault renders and search returns results | Blocked | Requires runtime shortcode page + B2 config and index data. |
| 7. Index sync endpoint responds (admin only) | Blocked | Requires runtime WP + index table + credentials. |
