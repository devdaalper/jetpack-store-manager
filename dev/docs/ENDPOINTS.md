# JPSM Endpoints Inventory

Date: 2026-03-03
Scope: Current endpoint contract after Phase 1 auth/session hardening and Phase 2 data-layer migration.

## Activation Context
- Active module: MediaVault (`/jetpack-store-manager.php:32`).
- Dormant module: Downloader remains disabled in bootstrap (`/jetpack-store-manager.php:31`).
- Active surfaces:
  - WordPress AJAX (`/wp-admin/admin-ajax.php`).
  - MediaVault query-action handlers (`JPSM_MediaVault_UI::handle_ajax` on `init`).
  - Dormant REST routes in downloader module.

## Shared Auth Contract (Phase 1)
- Central helper: `JPSM_Auth` (`/includes/class-jpsm-auth.php`).
- All hardened AJAX endpoints now require nonce + auth via helper.
- Admin endpoints validate privileged auth (`WP admin` or signed admin session, and only where configured, secret key).
- Non-admin access uses signed session token (no raw email trust).
- Security hardening (2026-02-23): frontend admin-panel actions in `[mediavault_vault]` are disabled by default (`jpsm_mediavault_frontend_admin_panel_enabled=false`).

## Response Contract (Phase 4)
- Default response shape remains unchanged for backward compatibility:
  - success: `{ success: true, data: <legacy> }`
  - error: `{ success: false, data: <legacy> }`
- Opt-in v2 response envelope:
  - Send `api_version=2` in request body/query (or `Accept: application/vnd.jpsm.v2+json`).
  - success: `{ success: true, data: { ok, code, message, data } }`
  - error: `{ success: false, data: { ok, code, message, details } }`

## Active AJAX Endpoints (`/wp-admin/admin-ajax.php`)

| Action | nopriv | Handler | Auth / Nonce | Inputs | Output | Side Effects |
|---|---|---|---|---|---|---|
| `jpsm_process_sale` | Yes | `JPSM_Sales::process_sale_ajax` | Nonce required: `jpsm_nonce` or `jpsm_process_sale_nonce` or `jpsm_sales_nonce`; auth via admin/signed-admin/secret-key. | `nonce`, `client_email`/`email`, `package_type`/`package`, `region`, optional `vip_subtype`. | JSON success/error. | Sends emails, writes `{prefix}jpsm_sales` via `JPSM_Data_Layer` (legacy mirror: `jpsm_sales_log`), updates `jpsm_lifetime_stats`. |
| `jpsm_delete_log` | Yes | `JPSM_Sales::delete_log_ajax` | Nonce required: `jpsm_nonce` or `jpsm_sales_nonce`; auth via admin/signed-admin/secret-key. | `nonce`, `id`. | JSON success/error. | Deletes from `{prefix}jpsm_sales` (legacy mirror fallback enabled). |
| `jpsm_delete_all_logs` | Yes | `JPSM_Sales::delete_all_logs_ajax` | Nonce required: `jpsm_nonce` or `jpsm_sales_nonce`; auth via admin/signed-admin/secret-key. | `nonce`. | JSON success/error. | Clears `{prefix}jpsm_sales` and mirrored legacy sales option. |
| `jpsm_delete_bulk_log` | Yes | `JPSM_Sales::delete_bulk_log_ajax` | Nonce required: `jpsm_nonce` or `jpsm_sales_nonce`; auth via admin/signed-admin/secret-key. | `nonce`, `ids[]`. | JSON success/error. | Bulk delete in `{prefix}jpsm_sales` (legacy mirror fallback enabled). |
| `jpsm_resend_email` | Yes | `JPSM_Sales::resend_email_ajax` | Nonce required: `jpsm_nonce` or `jpsm_sales_nonce`; auth via admin/signed-admin/secret-key. | `nonce`, `id`. | JSON success/error. | Re-sends email using log entry/template. |
| `jpsm_freeze_prices` | No | `JPSM_Sales::freeze_prices_ajax` | Nonce required: `jpsm_nonce` or `jpsm_sales_nonce`; admin auth only (no secret-key path). | `nonce`. | JSON success/error. | Mutates historical price fields in `{prefix}jpsm_sales` (and mirrored legacy sales option). |
| `jpsm_get_history` | Yes | `JPSM_Sales::get_history_ajax` | Nonce required: `jpsm_nonce` or `jpsm_sales_nonce`; auth via admin/signed-admin/secret-key. | `nonce`. | JSON success/error with log array. | Reads `{prefix}jpsm_sales` with legacy fallback. |
| `jpsm_record_finance_settlement` | Yes | `JPSM_Finance::record_settlement_ajax` | Nonce required: `jpsm_nonce` or `jpsm_finance_nonce`; auth via admin/secret-key. | `nonce`, `market`, `channel`, `settlement_date`, `gross_amount`, `fee_amount`, `net_amount`, optional `fx_rate`, optional `net_amount_mxn`, optional `bank_account`, optional `external_ref`, optional `notes`, optional `sale_uids[]`. | JSON success/error. | Writes `{prefix}jpsm_finance_settlements` and optional `{prefix}jpsm_finance_settlement_items`; blocks re-linking ventas ya conciliadas. |
| `jpsm_record_finance_expense` | Yes | `JPSM_Finance::record_expense_ajax` | Nonce required: `jpsm_nonce` or `jpsm_finance_nonce`; auth via admin/secret-key. | `nonce`, `expense_date`, `category`, `amount`, `currency`, optional `fx_rate`, optional `amount_mxn`, optional `vendor`, optional `description`, optional `account_label`, optional `notes`. | JSON success/error. | Writes `{prefix}jpsm_finance_expenses`. |
| `jpsm_delete_finance_settlement` | Yes | `JPSM_Finance::delete_settlement_ajax` | Nonce required: `jpsm_nonce` or `jpsm_finance_nonce`; auth via admin/secret-key. | `nonce`, `settlement_uid`. | JSON success/error. | Deletes settlement header and cascades its item rows from finance tables. |
| `jpsm_delete_finance_expense` | Yes | `JPSM_Finance::delete_expense_ajax` | Nonce required: `jpsm_nonce` or `jpsm_finance_nonce`; auth via admin/secret-key. | `nonce`, `expense_uid`. | JSON success/error. | Deletes one row from `{prefix}jpsm_finance_expenses`. |
| `jpsm_login` | Yes | `JPSM_Access_Manager::login_ajax` | Nonce required: `jpsm_nonce` or `jpsm_login_nonce`; key is validated server-side; creates signed admin session. | `nonce`, `key`. | JSON success/error. | Sets signed `jpsm_auth_session`. |
| `jpsm_logout` | Yes | `JPSM_Access_Manager::logout_ajax` | Nonce required: `jpsm_nonce` or `jpsm_logout_nonce`. | `nonce`. | JSON success/error. | Clears `jpsm_auth_session`. |
| `jpsm_get_user_tier` | No | `JPSM_Access_Manager::get_user_tier_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`, `email` (GET). | JSON success/error. | Reads tier/customer/play state from Phase 2 tables (`jpsm_user_tiers`, `jpsm_sales`, `jpsm_play_counts`) with legacy fallback. |
| `jpsm_update_user_tier` | No | `JPSM_Access_Manager::update_user_tier_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`, `email`, `tier`. | JSON success/error. | Writes `{prefix}jpsm_user_tiers` (legacy mirror fallback enabled). |
| `jpsm_get_folders` | No | `JPSM_Access_Manager::get_folders_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`. | JSON success/error. | Reads folder permissions + indexed folders. |
| `jpsm_update_folder_tier` | No | `JPSM_Access_Manager::update_folder_tier_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`, `folder`, `tiers[]` or legacy `tier`. | JSON success/error. | Writes `jpsm_folder_permissions`. |
| `jpsm_get_leads` | No | `JPSM_Access_Manager::get_leads_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`. | JSON success/error. | Reads `{prefix}jpsm_leads` with legacy fallback. |
| `jpsm_log_play` | Yes | `JPSM_Access_Manager::log_play_ajax` | Nonce required: `jpsm_nonce` or `jpsm_mediavault_nonce`; auth allows signed user session or admin auth. | `nonce`, optional `email` (session identity enforced for non-admin). | JSON success/error. | Writes `{prefix}jpsm_play_counts` (legacy mirror fallback enabled). |
| `jpsm_mv_get_sidebar_folders` | No | `JPSM_MediaVault_Nav_Order::ajax_get_sidebar_folders` | Nonce required: `jpsm_mediavault_nonce` or `jpsm_nonce` or `jpsm_access_nonce`; admin auth required. | `nonce`. | JSON `{ junction_folder, folders, saved_order }`. | Reads junction folder listing (index-first) and current saved order option. |
| `jpsm_mv_save_sidebar_order` | No | `JPSM_MediaVault_Nav_Order::ajax_save_sidebar_order` | Nonce required: `jpsm_mediavault_nonce` or `jpsm_nonce` or `jpsm_access_nonce`; admin auth required. | `nonce`, `order[]`. | JSON success/error. | Writes option `jpsm_mediavault_sidebar_order` (normalized + validated). |
| `jpsm_mv_reset_sidebar_order` | No | `JPSM_MediaVault_Nav_Order::ajax_reset_sidebar_order` | Nonce required: `jpsm_mediavault_nonce` or `jpsm_nonce` or `jpsm_access_nonce`; admin auth required. | `nonce`. | JSON `{ junction_folder, folders, saved_order }`. | Deletes option `jpsm_mediavault_sidebar_order`. |
| `jpsm_get_index_stats` | Yes | `JPSM_Index_Manager::get_stats_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; auth via admin/signed-admin/secret-key. | `nonce`. | JSON success/error with index stats (`active_table`, `stale`, `quality`, `sync_state`). | Reads active index table pointer + sync metadata. |
| `jpsm_test_b2_connection` | No | `JPSM_Config::test_b2_connection_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; admin auth required. | `nonce`. | JSON success/error. | Performs lightweight B2 S3 connectivity check (no DB writes). |
| `jpsm_sync_mediavault_index` | Yes | `JPSM_Index_Manager::sync_mediavault_index_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; auth via admin/signed-admin/secret-key. | `nonce`, optional `next_token`. | JSON success/error with batch sync state + quality counters (`scanned/inserted/updated/skipped_invalid/errors`) and swap metadata (`target_table/active_table`). | Writes to inactive index table and swaps active pointer only when sync completes. |
| `jpsm_auto_detect_bpm_batch` | No | `JPSM_Index_Manager::auto_detect_bpm_batch_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; admin auth required. | `nonce`, optional `limit` (1-100), optional `mode` (`deep` default, `meta` for tags-only). | JSON success/error with batch counters (`scanned`, `detected`, `no_bpm`, `unsupported`, `errors`, `remaining`, `done`, `mode`). | Runs software extraction for pending audio rows (`bpm=0`): MP3 ID3 `TBPM` + optional acoustic estimation via `ffmpeg` in deep mode; writes overrides + updates index BPM fields. |
| `jpsm_reset_auto_bpm_scan_marks` | No | `JPSM_Index_Manager::reset_auto_bpm_scan_marks_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; admin auth required. | `nonce`. | JSON success/error with reset counters (`primary`, `shadow`, `total`). | Clears transient `bpm_source` scan marks (`auto_*`) on zero-BPM rows so the extractor can re-run full backlog. |
| `jpsm_import_bpm_csv` | No | `JPSM_Index_Manager::import_bpm_csv_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; admin auth required. | `nonce`, `bpm_csv` file upload with columns `path` + `bpm` (or aliases `file_path/object_path/file`, `tempo`). | JSON success/error with processed/upserted/invalid counters and applied rows by table alias. | Upserts `{prefix}jpsm_mediavault_bpm_overrides` and updates `bpm/bpm_source` in index tables by `path_hash`. |
| `jpsm_desktop_issue_token` | No | `JPSM_Index_Manager::desktop_issue_token_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; admin auth required. | `nonce`. | JSON success/error; success includes one-time plaintext `token` + `created_at`. | Rotates desktop API token; persists hash only (`wp_options`). |
| `jpsm_desktop_revoke_token` | No | `JPSM_Index_Manager::desktop_revoke_token_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; admin auth required. | `nonce`. | JSON success/error (`revoked=true`). | Revokes active desktop API token and clears token metadata options. |
| `jpsm_desktop_api_health` | Yes | `JPSM_Index_Manager::desktop_api_health_ajax` | **Bearer token required** (`Authorization: Bearer <token>`). No nonce. | JSON body optional (`api_version=2` accepted). | JSON success/error (`ok`, `server_time`). | Stateless health check for desktop app preflight. |
| `jpsm_import_bpm_batch_api` | Yes | `JPSM_Index_Manager::import_bpm_batch_api_ajax` | **Bearer token required** (`Authorization: Bearer <token>`). No nonce. | JSON body: `batch_id`, `profile` (`fast|balanced|max_coverage`), `rows[]` = `{path,bpm,source,confidence,analyzed_at}`. | JSON success/error with `processed_rows`, `upserted`, `invalid_rows`, `manual_protected`, `duplicate_batch`, `applied`, `batch_version`. | Idempotent import by `batch_id` + payload hash; writes overrides, updates indexes, and persists row-level audit batch data. |
| `jpsm_rollback_bpm_batch_api` | Yes | `JPSM_Index_Manager::rollback_bpm_batch_api_ajax` | **Bearer token required** (`Authorization: Bearer <token>`). No nonce. | JSON body: `batch_id`. | JSON success/error with `rolled_back_rows` + `applied` counters. | Reverts one imported batch exactly using row-level audit (`old_bpm`/`old_source`), and marks batch as `rolled_back`. |
| `jpsm_track_behavior_event` | Yes | `JPSM_Behavior_Service::track_behavior_event_ajax` | Nonce required (`jpsm_nonce` or `jpsm_mediavault_nonce` or `jpsm_access_nonce`) + signed user session or admin auth. | `event_name`, optional `event_uuid`, `event_time`, `query_norm`, `result_count`, `object_type`, `object_path_norm`, `status`, `files_count`, `bytes_authorized`, `bytes_observed`, segmentation fields. | JSON success/error (v2 envelope supported). | Writes raw behavior telemetry in `{prefix}jpsm_behavior_events` (dedupe by `event_uuid`). |
| `jpsm_get_behavior_report` | Yes | `JPSM_Behavior_Service::get_behavior_report_ajax` | Nonce required (`jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce` or `jpsm_sales_nonce`) + privileged admin auth. | `month`, `tier`, `region`, `device_class`. | JSON monthly behavior report (summary + keywords + downloads + segment matrix, MoM/YoY deltas). | Reads daily rollups from `{prefix}jpsm_behavior_daily` and may trigger rollup warm-up. |
| `jpsm_export_behavior_csv` | Yes | `JPSM_Behavior_Service::export_behavior_csv_ajax` | Same auth contract as `jpsm_get_behavior_report`. | `month`, `tier`, `region`, `device_class`. | CSV stream download. | Exports monthly behavior report slices for external analysis. |
| `jpsm_get_transfer_report` | Yes | `JPSM_Behavior_Service::get_transfer_report_ajax` | Same auth contract as `jpsm_get_behavior_report`. | `month`, `window` (`month|prev_month|rolling_90d|lifetime`), `tier`, `region`, `device_class`. | JSON transfer report (`window`, `kpis`, `demand_kpis`, series 90d/mensual/lifetime, `top_folders_month`, `top_folders_source`, `top_folders_quality`, `coverage`). | Reads daily rollups + counts de cobertura en raw; ejecuta backfill aproximado autorizado (idempotente) desde `jpsm_folder_download_events` + índice local; aplica fallback legacy global para top carpetas cuando la capa primaria no alcanza. |
| `jpsm_export_transfer_csv` | Yes | `JPSM_Behavior_Service::export_transfer_csv_ajax` | Same auth contract as `jpsm_get_behavior_report`. | `month`, `window`, `tier`, `region`, `device_class`. | CSV stream download. | Exporta el mismo corte del reporte de transferencia con secciones `meta_extended`, `demand_kpis` y `top_folders_window`. |
| `mv_search_global` | Yes | `JPSM_MediaVault_UI::handle_ajax` | Nonce required (`jpsm_nonce` or `jpsm_mediavault_nonce` or `jpsm_access_nonce`) + signed user session. | `nonce`, `query`, optional `type`, optional `bpm_min/bpm_max`, optional `api_version=2`, optional `offset/limit` (GET). | Legacy: JSON list (no presigned URLs). v2: `{ items, meta, suggestions }` with `bpm` metadata per file item. | Reads active local index; results remain visibility-funnel (tier lock applies only to download actions). |

## REST API (Phase 4 Pilot)

| Route | Method | Auth | Output |
|---|---|---|---|
| `/wp-json/jpsm/v1/status/` (or `?rest_route=/jpsm/v1/status`) | GET | Admin via WP (`manage_options`) OR JPSM privileged auth via secret key (prefer `X-JPSM-Key` header; `?key=...` is opt-in via `jpsm_allow_get_key`). | JSON `{ ok: true, code: \"status_ok\", message, data }` |

## Active MediaVault Query Endpoints (via `init`)

All JSON actions below require nonce (`jpsm_nonce` or `jpsm_mediavault_nonce` or `jpsm_access_nonce`) enforced at handler entry.
The preview stream proxy (`mv_stream_preview`) is byte-capped and is protected by a short-lived token + signed user session (no nonce).

| Query Action / Flag | Method | Auth | Inputs | Output | Side Effects |
|---|---|---|---|---|---|
| `action=mv_list_folder` | GET | Signed user session + download permission rules (tier + folder perms). | `nonce`, `folder`. | JSON file list (`name`, `path`, `size`, `url`). | S3 listing + signed URLs (download path) + passive analytics event in `{prefix}jpsm_folder_download_events`; emits behavior events (`download_folder_granted`/`download_folder_denied`) incluyendo `bytes_authorized`. |
| `action=mv_search_global` | GET | Signed user session (no tier filtering; visibility funnel). | `nonce`, `query`, optional `type`, optional `bpm_min/bpm_max`, optional `api_version=2`, optional `offset/limit`. | Legacy list (no URLs) or v2 payload (`items/meta/suggestions`) when requested; each file result may include `bpm` + `bpm_source`. | Reads active index; locks are applied in UI, downloads resolved on-demand; emits `search_executed` and `search_zero_results` telemetry. |
| `action=mv_sync_index` | GET/POST | Disabled in customer-facing `mediavault_vault` frontend (returns 403 `forbidden` unless feature flag is explicitly enabled). | `nonce`. | JSON error by default. | No-op by default in frontend context. |
| `action=mv_index_stats` | GET | Disabled in customer-facing `mediavault_vault` frontend (returns 403 `forbidden` unless feature flag is explicitly enabled). | `nonce`. | JSON error by default. | No-op by default in frontend context. |
| `action=mv_get_presigned_url` | GET/POST | Signed user session + entitlement check (tier>0 + `user_can_access`). | `nonce`, `path` (prefer POST). | JSON `{ url }`. | Presigned **download** URL generation (only when entitled) + behavior events (`download_file_granted`/`download_file_denied`), `download_file_granted` enriquecido con `bytes_authorized` vía índice local. |
| `action=mv_get_preview_url` | GET/POST | Signed user session. | `nonce`, `path` (prefer POST). | JSON `{ url, mode, remaining_plays }`. | For demo/locked: issues short-lived proxy token (no direct S3 URL). For entitled: may return direct presigned URL + emits `preview_direct_opened`. |
| `action=mv_stream_preview` | GET | Signed user session + short-lived preview token bound to session email. | `token`, optional `Range` header. | Partial content bytes. | Streams a capped byte-range for preview (prevents full download extraction for locked/demo) + emits `preview_proxy_streamed` con `bytes_observed` exactos. |
| `action=mv_get_user_meta` | GET | Disabled in customer-facing `mediavault_vault` frontend (returns 403 `forbidden` unless feature flag is explicitly enabled). | `nonce`, `email`. | JSON error by default. | No-op by default in frontend context. |
| `action=mv_update_tier` | POST | Disabled in customer-facing `mediavault_vault` frontend (returns 403 `forbidden` unless feature flag is explicitly enabled). | `nonce`, `email`, `tier`. | JSON error by default. | No-op by default in frontend context. |
| `action=mv_get_folders` | GET | Disabled in customer-facing `mediavault_vault` frontend (returns 403 `forbidden` unless feature flag is explicitly enabled). | `nonce`. | JSON error by default. | No-op by default in frontend context. |
| `action=mv_update_folder` | POST | Disabled in customer-facing `mediavault_vault` frontend (returns 403 `forbidden` unless feature flag is explicitly enabled). | `nonce`, `folder`, `tier`. | JSON error by default. | No-op by default in frontend context. |
| `action=mv_get_leads` | GET | Disabled in customer-facing `mediavault_vault` frontend (returns 403 `forbidden` unless feature flag is explicitly enabled). | `nonce`. | JSON error by default. | No-op by default in frontend context. |
| `mv_ajax=1` | GET | Signed user session (plus nonce requirement). | `nonce`, page context, optional `folder`. | JSON page payload (no URLs). | Browsing payload (visibility funnel; downloads resolved on-demand). |

## Dormant REST Endpoints (Downloader)

Routes are still dormant while downloader loader remains disabled.

| Route | Method | Permission Callback | Behavior |
|---|---|---|---|
| `/wp-json/jdd/v1/catalog` | GET | `JPSM_Access_Manager::check_current_session()` | Returns catalog metadata from `jdd_catalog_item`. |
| `/wp-json/jdd/v1/track` | POST | `JPSM_Access_Manager::check_current_session()` | Writes download record in `jdd_stats`. |
| `/wp-json/jdd/v1/sync` | POST | `current_user_can('manage_options')` | Syncs/archives catalog CPT entries. |

## Core Flow Contract (Current)
- Sale -> Email -> Log -> Access: maintained with centralized nonce+auth in sales AJAX handlers.
- Login/Session -> Tier resolution: now based on signed cookies validated server-side.
- MediaVault browse/search -> Presigned URL -> Download: browse/search are visibility-first; downloads are gated by locks (UI) and download endpoints.
- Index sync -> Search performance: sync/stats endpoints now use centralized privileged auth checks.
