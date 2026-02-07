# JPSM Endpoints Inventory

Date: 2026-02-07
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
| `jpsm_login` | Yes | `JPSM_Access_Manager::login_ajax` | Nonce required: `jpsm_nonce` or `jpsm_login_nonce`; key is validated server-side; creates signed admin session. | `nonce`, `key`. | JSON success/error. | Sets signed `jpsm_auth_session`. |
| `jpsm_logout` | Yes | `JPSM_Access_Manager::logout_ajax` | Nonce required: `jpsm_nonce` or `jpsm_logout_nonce`. | `nonce`. | JSON success/error. | Clears `jpsm_auth_session`. |
| `jpsm_get_user_tier` | No | `JPSM_Access_Manager::get_user_tier_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`, `email` (GET). | JSON success/error. | Reads tier/customer/play state from Phase 2 tables (`jpsm_user_tiers`, `jpsm_sales`, `jpsm_play_counts`) with legacy fallback. |
| `jpsm_update_user_tier` | No | `JPSM_Access_Manager::update_user_tier_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`, `email`, `tier`. | JSON success/error. | Writes `{prefix}jpsm_user_tiers` (legacy mirror fallback enabled). |
| `jpsm_get_folders` | No | `JPSM_Access_Manager::get_folders_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`. | JSON success/error. | Reads folder permissions + indexed folders. |
| `jpsm_update_folder_tier` | No | `JPSM_Access_Manager::update_folder_tier_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`, `folder`, `tiers[]` or legacy `tier`. | JSON success/error. | Writes `jpsm_folder_permissions`. |
| `jpsm_get_leads` | No | `JPSM_Access_Manager::get_leads_ajax` | Nonce required: `jpsm_nonce` or `jpsm_access_nonce` or `jpsm_mediavault_nonce`; admin auth required. | `nonce`. | JSON success/error. | Reads `{prefix}jpsm_leads` with legacy fallback. |
| `jpsm_log_play` | Yes | `JPSM_Access_Manager::log_play_ajax` | Nonce required: `jpsm_nonce` or `jpsm_mediavault_nonce`; auth allows signed user session or admin auth. | `nonce`, optional `email` (session identity enforced for non-admin). | JSON success/error. | Writes `{prefix}jpsm_play_counts` (legacy mirror fallback enabled). |
| `jpsm_get_index_stats` | Yes | `JPSM_Index_Manager::get_stats_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; auth via admin/signed-admin/secret-key. | `nonce`. | JSON success/error with index stats. | Reads index table and sync metadata. |
| `jpsm_sync_mediavault_index` | Yes | `JPSM_Index_Manager::sync_mediavault_index_ajax` | Nonce required: `jpsm_nonce` or `jpsm_index_nonce`; auth via admin/signed-admin/secret-key. | `nonce`, optional `next_token`. | JSON success/error with batch sync state. | Writes/truncates index table, updates sync metadata. |
| `mv_search_global` | Yes | `JPSM_MediaVault_UI::handle_ajax` | Nonce required (`jpsm_nonce` or `jpsm_mediavault_nonce` or `jpsm_access_nonce`) + signed user session. | `nonce`, `query`, optional `type` (GET). | JSON success/error. | Reads local index and generates presigned URLs. |

## REST API (Phase 4 Pilot)

| Route | Method | Auth | Output |
|---|---|---|---|
| `/wp-json/jpsm/v1/status/` (or `?rest_route=/jpsm/v1/status`) | GET | Admin via WP (`manage_options`) OR JPSM privileged auth via `key` (secret key). | JSON `{ ok: true, code: \"status_ok\", message, data }` |

## Active MediaVault Query Endpoints (via `init`)

All listed actions below require nonce (`jpsm_nonce` or `jpsm_mediavault_nonce` or `jpsm_access_nonce`) enforced at handler entry.

| Query Action / Flag | Method | Auth | Inputs | Output | Side Effects |
|---|---|---|---|---|---|
| `action=mv_list_folder` | GET | Signed user session + folder access rules. | `nonce`, `folder`. | JSON file list. | S3 listing + signed URLs. |
| `action=mv_search_global` | GET | Signed user session + folder filter by tier. | `nonce`, `query`, optional `type`. | JSON search list. | Reads local index + signs URLs. |
| `action=mv_sync_index` | GET/POST | Privileged admin auth via `JPSM_Auth::is_admin_authenticated(true)`. | `nonce`. | JSON success/error. | Syncs index + clears folder cache transients. |
| `action=mv_index_stats` | GET | Privileged admin auth via `JPSM_Auth::is_admin_authenticated(true)`. | `nonce`. | JSON stats. | Reads index stats. |
| `action=mv_get_presigned_url` | GET | Signed user session. | `nonce`, `path`. | JSON URL payload. | Presigned URL generation. |
| `action=mv_get_user_meta` | GET | Current signed session email must be JPSM admin. | `nonce`, `email`. | JSON metadata. | Reads tier/plays/customer state. |
| `action=mv_update_tier` | POST | Current signed session email must be JPSM admin. | `nonce`, `email`, `tier`. | JSON success/error. | Writes `{prefix}jpsm_user_tiers` (legacy mirror fallback enabled). |
| `action=mv_get_folders` | GET | Current signed session email must be JPSM admin. | `nonce`. | JSON map. | Reads folder permissions. |
| `action=mv_update_folder` | POST | Current signed session email must be JPSM admin. | `nonce`, `folder`, `tier`. | JSON success/error. | Writes folder permissions. |
| `action=mv_get_leads` | GET | Current signed session email must be JPSM admin. | `nonce`. | JSON list. | Reads `{prefix}jpsm_leads` with legacy fallback. |
| `mv_ajax=1` | GET | Signed user session (plus nonce requirement). | `nonce`, page context, optional `folder`. | JSON page payload. | Generates signed URLs for rendered files. |

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
- MediaVault browse/search -> Presigned URL -> Download: now protected by nonce + signed session checks.
- Index sync -> Search performance: sync/stats endpoints now use centralized privileged auth checks.
