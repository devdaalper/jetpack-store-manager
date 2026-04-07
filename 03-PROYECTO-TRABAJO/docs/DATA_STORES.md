# JPSM Data Stores Inventory (Phase 0 + Phase 2 Update)

Date: 2026-03-03
Scope: `wp_options`, custom DB tables, and other persisted stores used by this plugin.

## Options (`wp_options`)

| Key / Pattern | Purpose | Writers | Readers | References |
|---|---|---|---|---|
| `jpsm_sales_log` | Legacy mirror/fallback store for sales history. Primary store moved to `{prefix}jpsm_sales` in Phase 2. | Mirrored writes from `JPSM_Data_Layer` + legacy fallback paths. | Legacy fallback reads when table missing/unavailable. | `includes/class-jpsm-data-layer.php:21`, `includes/class-jpsm-data-layer.php:130`, `includes/class-jpsm-sales.php:245` |
| `jpsm_lifetime_stats` | Lifetime counters and revenue summary. | `update_persistent_stats()` and migration path in `get_persistent_stats()`. | Dashboard stats access. | `includes/class-jpsm-sales.php:469`, `includes/class-jpsm-sales.php:504`, `includes/class-jpsm-sales.php:545` |
| `jpsm_user_tiers` | Legacy mirror/fallback store for explicit tier assignments. Primary store moved to `{prefix}jpsm_user_tiers`. | Mirrored writes from `JPSM_Data_Layer::set_user_tier()` + legacy fallback paths. | Legacy fallback reads when table missing/unavailable. | `includes/class-jpsm-data-layer.php:22`, `includes/class-jpsm-data-layer.php:361`, `includes/class-access-manager.php:27` |
| `jpsm_folder_permissions` | Folder -> allowed tiers map. | `set_folder_allowed_tiers()`, folder update endpoints. | Access checks and folder admin views. | `includes/class-access-manager.php:28`, `includes/class-access-manager.php:384`, `includes/class-access-manager.php:387`, `includes/class-access-manager.php:313` |
| `jpsm_demo_play_counts` | Legacy mirror/fallback store for demo play counters. Primary store moved to `{prefix}jpsm_play_counts`. | Mirrored writes from `JPSM_Data_Layer::increment_play_count()` + legacy fallback paths. | Legacy fallback reads when table missing/unavailable. | `includes/class-jpsm-data-layer.php:24`, `includes/class-jpsm-data-layer.php:574`, `includes/class-access-manager.php:29` |
| `jpsm_leads_list` | Legacy mirror/fallback store for lead registry. Primary store moved to `{prefix}jpsm_leads`. | Mirrored writes from `JPSM_Data_Layer::register_lead()` + legacy fallback paths. | Legacy fallback reads when table missing/unavailable. | `includes/class-jpsm-data-layer.php:23`, `includes/class-jpsm-data-layer.php:443`, `includes/class-access-manager.php:30` |
| `jpsm_data_layer_schema_version` | Phase 2 schema version marker for `JPSM_Data_Layer`. | `JPSM_Data_Layer::install_schema()`. | Bootstrap guard for table create/upgrade. | `includes/class-jpsm-data-layer.php:18`, `includes/class-jpsm-data-layer.php:40`, `includes/class-jpsm-data-layer.php:96` |
| `jpsm_data_layer_migration_state` | Per-entity migration state (`sales`, `tiers`, `leads`, `plays`). | `JPSM_Data_Layer::mark_migrated()`. | Migration idempotency checks. | `includes/class-jpsm-data-layer.php:19`, `includes/class-jpsm-data-layer.php:624`, `includes/class-jpsm-data-layer.php:785` |
| `jpsm_access_key` | Shared secret key for privileged access paths (secret-key requests) and signed admin sessions. | WordPress settings form (`register_setting`). | `JPSM_Auth` access key checks and Settings UI. | `includes/class-jpsm-admin.php:214`, `includes/class-jpsm-auth.php:238`, `includes/class-jpsm-admin-views.php:461` |
| `jpsm_wp_admin_only_mode` | Opt-in hardening: restrict privileged actions to WP admins only; disables secret-key auth + signed admin sessions from keys. | Settings form. | `JPSM_Auth`. | `includes/class-jpsm-admin.php`, `includes/class-jpsm-auth.php`, `includes/class-jpsm-admin-views.php` |
| `jpsm_allow_get_key` | Legacy compatibility toggle: accept secret key from URL querystring (`?key=...`). Default off. | Settings form. | `JPSM_Auth`. | `includes/class-jpsm-admin.php`, `includes/class-jpsm-auth.php`, `includes/class-jpsm-admin-views.php` |
| `jpsm_reply_to_email` | Reply-To email for outgoing sale emails. | WordPress settings form (`register_setting`). | Mail headers for `wp_mail`. | `includes/class-jpsm-admin.php:218`, `includes/class-jpsm-sales.php:42`, `includes/class-jpsm-admin-views.php:473` |
| `jpsm_notify_emails` | List of admin recipients for sale confirmation notifications. | WordPress settings form (`register_setting`). | Sale processing notifications. | `includes/class-jpsm-admin.php:222`, `includes/class-jpsm-sales.php:115`, `includes/class-jpsm-admin-views.php:473` |
| `jpsm_admin_emails` | Legacy list of emails previously treated as MediaVault admins. Kept for compatibility, but does not grant privileges by itself. | Settings form. | Legacy `is_admin()` helper only. | `includes/class-jpsm-admin.php`, `includes/class-access-manager.php`, `includes/class-jpsm-admin-views.php` |
| `jpsm_whatsapp_number` | WhatsApp number used for upgrade CTAs in MediaVault (digits only). | Settings form. | MediaVault UI injection (`MV_USER_DATA`). | `includes/class-jpsm-admin.php`, `includes/modules/mediavault/template-vault.php`, `includes/class-jpsm-admin-views.php` |
| `jpsm_email_template_basic` | Email template for basic package. | Settings form. | Sale/resend template resolution. | `includes/class-jpsm-admin.php:163`, `includes/class-jpsm-sales.php:193`, `includes/class-jpsm-sales.php:375` |
| `jpsm_email_template_vip` | Legacy VIP template fallback key. | Settings form. | Legacy/fallback use only. | `includes/class-jpsm-admin.php:164` |
| `jpsm_email_template_full` | Email template for full package. | Settings form. | Sale/resend template resolution. | `includes/class-jpsm-admin.php:165`, `includes/class-jpsm-sales.php:193` |
| `jpsm_email_template_vip_videos` | Email template for VIP Videos subtype. | Settings form. | Sale subtype path. | `includes/class-jpsm-admin.php:168`, `includes/class-jpsm-sales.php:201` |
| `jpsm_email_template_vip_pelis` | Email template for VIP Pelis subtype. | Settings form. | Sale subtype path. | `includes/class-jpsm-admin.php:169`, `includes/class-jpsm-sales.php:201` |
| `jpsm_email_template_vip_basic` | Email template for VIP Basic subtype. | Settings form. | Sale subtype path. | `includes/class-jpsm-admin.php:170`, `includes/class-jpsm-sales.php:201` |
| `jpsm_price_mxn_*` | Price matrix for MXN (`basic`, `vip_videos`, `vip_pelis`, `vip_basic`, `full`). | Settings form. | `get_entry_price()` fallback and freeze logic. | `includes/class-jpsm-admin.php:176`, `includes/class-jpsm-sales.php:163` |
| `jpsm_price_usd_*` | Price matrix for USD (`vip_videos`, `vip_pelis`, `vip_basic`, `full`). | Settings form. | `get_entry_price()` fallback and freeze logic. | `includes/class-jpsm-admin.php:183`, `includes/class-jpsm-sales.php:163` |
| `jpsm_b2_key_id` | B2 key id setting. | Settings form. | Read into runtime constants. | `includes/class-jpsm-admin.php:189`, `jetpack-store-manager.php:35` |
| `jpsm_b2_app_key` | B2 application key setting. | Settings form. | Read into runtime constants. | `includes/class-jpsm-admin.php:190`, `jetpack-store-manager.php:37` |
| `jpsm_b2_bucket` | B2 bucket setting. | Settings form. | Read into runtime constants. | `includes/class-jpsm-admin.php:191`, `jetpack-store-manager.php:39` |
| `jpsm_b2_region` | B2 region setting. | Settings form. | Read into runtime constants. | `includes/class-jpsm-admin.php:192`, `jetpack-store-manager.php:41` |
| `jpsm_bpm_ffmpeg_path` | Optional custom `ffmpeg` binary path for deep acoustic BPM extraction. | Option read in `resolve_ffmpeg_binary()` (no UI field yet). | Used by BPM batch extractor when `mode=deep`. | `includes/modules/mediavault/class-index-manager.php` |
| `jpsm_desktop_api_token_hash` | Hash (`wp_hash_password`) del token de integración Desktop BPM. | `desktop_issue_token_ajax` rota valor; `desktop_revoke_token_ajax` lo elimina. | Verificación de bearer token en endpoints desktop (`verify_desktop_token`). | `includes/modules/mediavault/class-index-manager.php` |
| `jpsm_desktop_api_token_created_at` | Timestamp de emisión del token desktop actual. | `desktop_issue_token_ajax`. | UI admin / auditoría operativa. | `includes/modules/mediavault/class-index-manager.php` |
| `jpsm_desktop_api_token_last_used_at` | Timestamp del último uso exitoso del token desktop API. | `verify_desktop_token` (en cada request válida). | Diagnóstico operacional en rotación de credenciales. | `includes/modules/mediavault/class-index-manager.php` |
| `jpsm_cloudflare_domain` | CDN domain setting for MediaVault. | Settings form. | Read into runtime constants. | `includes/class-jpsm-admin.php:193`, `jetpack-store-manager.php:43` |
| `jpsm_mediavault_index_version` | Schema/version marker for MediaVault index tables. | `create_table()`. | Upgrade/compat checks. | `includes/modules/mediavault/class-index-manager.php` |
| `jpsm_mediavault_index_active_table` | Active index pointer (`primary` or `shadow`) for atomic sync swaps. | `sync_batch()` finalization. | Search/browse/stats reads (`get_table_name('active')`). | `includes/modules/mediavault/class-index-manager.php` |
| `jpsm_mediavault_sync_state` | Last/ongoing sync state (`sync_id`, status, target table, quality counters, token). | `sync_batch()` on each batch. | Sync status widgets + stats API. | `includes/modules/mediavault/class-index-manager.php` |
| `jpsm_mediavault_last_sync` | Last **completed** index sync timestamp (only after successful full swap). | `sync_batch()` completion. | Index stats/staleness checks and UI alerts. | `includes/modules/mediavault/class-index-manager.php` |
| `mv_folder_<md5>` (transient) | Cached folder structure snapshots. | `set_transient()`. | `get_transient()` lookup for rendering and navigation. | `includes/modules/mediavault/template-vault.php:23`, `includes/modules/mediavault/template-vault.php:34` |
| `mv_preview_<token>` (transient) | Short-lived preview proxy token payload (path + email + byte cap). | `mv_get_preview_url`. | `mv_stream_preview`. | `includes/modules/mediavault/template-vault.php` |
| `jdd_google_api_key` (dormant) | Downloader module API key. | Downloader settings page. | Downloader settings UI/read path. | `includes/modules/downloader/settings.php:16`, `includes/modules/downloader/settings.php:67` |
| `jdd_root_folder_id` (dormant) | Downloader root folder id (currently fixed in UI). | Downloader settings registration. | Downloader module logic (not actively wired). | `includes/modules/downloader/settings.php:17` |

## Custom Tables

| Table | Created By | Schema Summary | Read Paths | Write Paths | References |
|---|---|---|---|---|---|
| `{prefix}jpsm_sales` | `JPSM_Data_Layer::install_schema()` | `id`, `sale_uid` (unique), `sale_time`, `email`, `package`, `region`, `amount`, `currency`, `status`, indexes by `email/sale_time`. | Sales history/dashboard stats, tier/customer inference, resend lookup. | Sale create/update/delete and freeze replace flows through data layer. | `includes/class-jpsm-data-layer.php:54`, `includes/class-jpsm-data-layer.php:130`, `includes/class-jpsm-sales.php:245` |
| `{prefix}jpsm_finance_settlements` | `JPSM_Data_Layer::install_schema()` | `id`, `settlement_uid` (unique), `settlement_date`, `market`, `channel`, `currency`, `gross_amount`, `fee_amount`, `net_amount`, `fx_rate`, `net_amount_mxn`, `sales_count`, bank/reference/notes, timestamps. | Finance admin overview, liquidation history, operating net received KPIs. | `JPSM_Finance::record_settlement_ajax` via data layer create/delete flows. | `includes/class-jpsm-data-layer.php`, `includes/class-jpsm-finance.php`, `includes/class-jpsm-admin-views.php` |
| `{prefix}jpsm_finance_settlement_items` | `JPSM_Data_Layer::install_schema()` | `id`, `item_uid` (unique), `settlement_uid`, optional `sale_uid`, sale snapshot fields (`sale_time/email/package/region`), `gross_amount`, `fee_amount`, `net_amount`, `currency`. | Basic reconciliation of sales already linked to a settlement; prevents duplicate conciliation. | Rebuilt on each finance settlement save; deleted alongside parent settlement. | `includes/class-jpsm-data-layer.php`, `includes/class-jpsm-finance.php` |
| `{prefix}jpsm_finance_expenses` | `JPSM_Data_Layer::install_schema()` | `id`, `expense_uid` (unique), `expense_date`, `category`, `vendor`, `description`, `amount`, `currency`, `fx_rate`, `amount_mxn`, `account_label`, notes/status, timestamps. | Finance admin overview and recent expense ledger. | `JPSM_Finance::record_expense_ajax` and delete flow. | `includes/class-jpsm-data-layer.php`, `includes/class-jpsm-finance.php`, `includes/class-jpsm-admin-views.php` |
| `{prefix}jpsm_user_tiers` | `JPSM_Data_Layer::install_schema()` | `email` (PK), `tier`, `updated_at`, index by `tier`. | Tier resolution and admin tier reads. | Tier updates via access manager/data layer. | `includes/class-jpsm-data-layer.php:71`, `includes/class-jpsm-data-layer.php:325`, `includes/class-access-manager.php:103` |
| `{prefix}jpsm_leads` | `JPSM_Data_Layer::install_schema()` | `email` (PK), `registered_at`, `source`, index by `registered_at`. | Leads list/admin reads. | Lead registration via access manager/data layer. | `includes/class-jpsm-data-layer.php:79`, `includes/class-jpsm-data-layer.php:443`, `includes/class-access-manager.php:176` |
| `{prefix}jpsm_play_counts` | `JPSM_Data_Layer::install_schema()` | `email` (PK), `play_count`, `updated_at`. | Demo playback checks and tier payload. | Play logging/increment flows. | `includes/class-jpsm-data-layer.php:87`, `includes/class-jpsm-data-layer.php:574`, `includes/class-access-manager.php:494` |
| `{prefix}jpsm_folder_download_events` | `JPSM_Data_Layer::install_schema()` | `id`, `folder_path`, `folder_name`, `downloaded_at`, indexes on `folder_path/downloaded_at`. | Frontend manager demand metrics (Top 30 folders). | Logged from MediaVault folder-download endpoint (`mv_list_folder`) after successful authorized requests. | `includes/class-jpsm-data-layer.php`, `includes/modules/mediavault/template-vault.php`, `includes/class-jpsm-stats-service.php` |
| `{prefix}jpsm_behavior_events` | `JPSM_Data_Layer::install_schema()` | `id`, `event_uuid` (unique), `event_time`, `event_name`, `session_id_hash`, `user_id_hash`, segmentation fields (`tier/region/device_class`), keyword/path fields (`query_norm`, `object_path_norm`), `status`, `files_count`, **transfer fields** (`bytes_authorized`, `bytes_observed`), `meta_json`. | Behavior + transfer report services (keywords, top descargas, series/coverage de transferencia) and rollup jobs. | `jpsm_track_behavior_event` endpoint, passive MediaVault backend hooks, frontend click/completion telemetry. | `includes/class-jpsm-data-layer.php`, `includes/class-jpsm-behavior-service.php`, `includes/modules/mediavault/template-vault.php`, `includes/modules/mediavault/assets/js/mediavault-client.js` |
| `{prefix}jpsm_behavior_daily` | `JPSM_Data_Layer::install_schema()` | Daily aggregate table: `day_date`, `metric_key`, `dimension_hash`, dimensions (`query_norm/object_path_norm/tier/region/device_class`), `metric_count`, **transfer sums** (`metric_bytes_authorized`, `metric_bytes_observed`). Unique per (`day_date`,`metric_key`,`dimension_hash`). | Monthly behavior reads + transfer reads (`90d`, mensual, lifetime, top carpetas, cobertura). | Daily cron rollup, on-demand warm-up rebuilds, and one-time transfer backfill aproximado (authorized-only). | `includes/class-jpsm-data-layer.php`, `includes/class-jpsm-behavior-service.php` |
| `{prefix}jpsm_mediavault_index` + `{prefix}jpsm_mediavault_index_shadow` | `JPSM_Index_Manager::create_table()` | Atomic double-buffer schema: `path`, `path_hash` (unique), normalized text fields (`path_norm/name_norm/folder_norm`), `media_kind`, `bpm`, `bpm_source`, `last_modified`, `etag`, `depth`, plus legacy fields (`name/folder/size/extension/synced_at`). | Active table powers search, browse folder extraction, permissions helpers, BPM filters, and stats. | Batch sync writes to inactive table and swaps active pointer on success; sync computes inferred BPM from path/name patterns for audio files. | `includes/modules/mediavault/class-index-manager.php`, `includes/class-access-manager.php`, `includes/modules/mediavault/template-vault.php` |
| `{prefix}jpsm_mediavault_bpm_overrides` | `JPSM_Index_Manager::create_table()` | `id`, `path_hash` (unique), `path`, `bpm`, `source`, `updated_at`, indexes by `path`/`bpm`. | BPM override reads during sync (`resolve_bpm_for_object`) and CSV apply-to-index pass. | Admin CSV import endpoint (`jpsm_import_bpm_csv`) upserts persistent overrides (`source=manual_csv`). | `includes/modules/mediavault/class-index-manager.php`, `includes/class-jpsm-admin.php`, `assets/js/admin.js` |
| `{prefix}jpsm_mediavault_bpm_batches` | `JPSM_Index_Manager::create_table()` | `id`, `batch_id` (unique), `payload_hash`, `profile`, `status`, `metrics_json`, `created_by`, `created_at`, `rolled_back_at`. | Desktop batch idempotency checks (`get_bpm_batch`) y auditoría de estado. | Desktop API import/rollback (`insert_bpm_batch`, `update_bpm_batch_status`). | `includes/modules/mediavault/class-index-manager.php` |
| `{prefix}jpsm_mediavault_bpm_batch_rows` | `JPSM_Index_Manager::create_table()` | `id`, `batch_id`, `path_hash`, `path`, `old_bpm`, `new_bpm`, `old_source`, `new_source`, `confidence`, `applied_at`. | Rollback exacto por lote (`get_bpm_batch_rows`) + trazabilidad fila a fila. | Insertado por `jpsm_import_bpm_batch_api` durante upsert aplicado. | `includes/modules/mediavault/class-index-manager.php` |
| `{prefix}mediavault_logs` | `JPSM_Traffic_Manager::install_table()` | `id`, `user_id`, `file_name`, `file_size`, `download_date`, `ip_address`. | Daily usage checks. | Download logging. | `includes/modules/mediavault/class-traffic-manager.php:13`, `includes/modules/mediavault/class-traffic-manager.php:55`, `includes/modules/mediavault/class-traffic-manager.php:74` |
| `{prefix}jdd_stats` (dormant) | `jdd_install_stats_table()` | `id`, `folder_id`, `folder_name`, `downloaded_at`. | Downloader analytics page. | REST track endpoint via `jdd_track_download()`. | `includes/modules/downloader/analytics.php:9`, `includes/modules/downloader/analytics.php:66`, `includes/modules/downloader/api.php:176` |

## Other Persistent Stores

### WordPress Posts/Meta/Taxonomy (dormant downloader domain)
- CPT: `jdd_catalog_item`.
- Taxonomy: `jdd_tag`.
- Meta keys: `_jdd_drive_folder_id`, `_jdd_image_1`, `_jdd_image_2`.

Refs: `includes/modules/downloader/catalog.php:10`, `includes/modules/downloader/catalog.php:28`, `includes/modules/downloader/catalog.php:60`, `includes/modules/downloader/catalog.php:129`.

### Browser Storage
- `localStorage['mv_view']` is used by MediaVault client to persist list/grid preference.

Ref: `includes/modules/mediavault/assets/js/mediavault-client.js:1140`.

## Phase 2 Data Layer Status
- Completed: sales, explicit user tiers, leads, and demo play counts now persist in dedicated tables through `JPSM_Data_Layer`.
- Added analytics support for folder-download demand in `{prefix}jpsm_folder_download_events` (historical Top 30 in manager frontend).
- Added behavior telemetry layer:
  - raw events in `{prefix}jpsm_behavior_events` (keywords + download behavior + segmentation),
  - daily rollups in `{prefix}jpsm_behavior_daily` for monthly MoM/YoY reporting and CSV export.
- Added transfer telemetry:
  - raw bytes (`bytes_authorized`, `bytes_observed`) in behavior events,
  - daily transfer sums in behavior daily,
  - authorized-only backfill (`download_folder_granted_backfill`) from legacy folder events + local index sizes.
- Added BPM persistence for MediaVault catalog:
  - index tables now include `bpm` + `bpm_source` for audio search/filter payloads,
  - persistent manual/imported corrections in `{prefix}jpsm_mediavault_bpm_overrides`,
  - override values are re-applied after CSV import and respected in future sync batches,
  - automatic software extraction batches can mark pending rows as analyzed (`bpm_source` scan states) and persist detections from MP3 ID3 `TBPM`,
  - deep mode can estimate BPM acoustically (`auto_acoustic_ffmpeg`) and supports re-run via reset endpoint that clears transient `auto_*` marks,
  - desktop integration adds tokenized API import with idempotent batch log (`{prefix}jpsm_mediavault_bpm_batches`) and reversible row audit (`{prefix}jpsm_mediavault_bpm_batch_rows`).
- Legacy options are still mirrored as rollback-safe fallback stores and compatibility bridges.
- Folder permissions (`jpsm_folder_permissions`) remain in `wp_options` and are a candidate for a future dedicated table if access-rule complexity grows.
