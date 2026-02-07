# JPSM Data Stores Inventory (Phase 0 + Phase 2 Update)

Date: 2026-02-07
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
| `jpsm_access_key` | Shared secret key for admin-like access paths and sync calls. | WordPress settings form (`register_setting`). | Auth/session checks, frontend localized payloads, index sync auth. | `includes/class-jpsm-admin.php:173`, `includes/class-access-manager.php:541`, `includes/class-jpsm-sales.php:115`, `includes/class-jpsm-dashboard.php:120` |
| `jpsm_admin_emails` | List of emails treated as JPSM admins. | No in-repo writer found. | `is_admin()` checks. | `includes/class-access-manager.php:530` |
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
| `jpsm_cloudflare_domain` | CDN domain setting for MediaVault. | Settings form. | Read into runtime constants. | `includes/class-jpsm-admin.php:193`, `jetpack-store-manager.php:43` |
| `jpsm_mediavault_index_version` | Schema/version marker for index table. | `create_table()`. | Not consumed elsewhere in repo. | `includes/modules/mediavault/class-index-manager.php:57` |
| `jpsm_mediavault_last_sync` | Last successful index sync timestamp. | Index sync paths. | Index stats/staleness checks. | `includes/modules/mediavault/class-index-manager.php:94`, `includes/modules/mediavault/class-index-manager.php:259` |
| `mv_folder_<md5>` (transient) | Cached folder structure snapshots. | `set_transient()`. | `get_transient()` lookup for rendering and navigation. | `includes/modules/mediavault/template-vault.php:23`, `includes/modules/mediavault/template-vault.php:34` |
| `jdd_google_api_key` (dormant) | Downloader module API key. | Downloader settings page. | Downloader settings UI/read path. | `includes/modules/downloader/settings.php:16`, `includes/modules/downloader/settings.php:67` |
| `jdd_root_folder_id` (dormant) | Downloader root folder id (currently fixed in UI). | Downloader settings registration. | Downloader module logic (not actively wired). | `includes/modules/downloader/settings.php:17` |

## Custom Tables

| Table | Created By | Schema Summary | Read Paths | Write Paths | References |
|---|---|---|---|---|---|
| `{prefix}jpsm_sales` | `JPSM_Data_Layer::install_schema()` | `id`, `sale_uid` (unique), `sale_time`, `email`, `package`, `region`, `amount`, `currency`, `status`, indexes by `email/sale_time`. | Sales history/dashboard stats, tier/customer inference, resend lookup. | Sale create/update/delete and freeze replace flows through data layer. | `includes/class-jpsm-data-layer.php:54`, `includes/class-jpsm-data-layer.php:130`, `includes/class-jpsm-sales.php:245` |
| `{prefix}jpsm_user_tiers` | `JPSM_Data_Layer::install_schema()` | `email` (PK), `tier`, `updated_at`, index by `tier`. | Tier resolution and admin tier reads. | Tier updates via access manager/data layer. | `includes/class-jpsm-data-layer.php:71`, `includes/class-jpsm-data-layer.php:325`, `includes/class-access-manager.php:103` |
| `{prefix}jpsm_leads` | `JPSM_Data_Layer::install_schema()` | `email` (PK), `registered_at`, `source`, index by `registered_at`. | Leads list/admin reads. | Lead registration via access manager/data layer. | `includes/class-jpsm-data-layer.php:79`, `includes/class-jpsm-data-layer.php:443`, `includes/class-access-manager.php:176` |
| `{prefix}jpsm_play_counts` | `JPSM_Data_Layer::install_schema()` | `email` (PK), `play_count`, `updated_at`. | Demo playback checks and tier payload. | Play logging/increment flows. | `includes/class-jpsm-data-layer.php:87`, `includes/class-jpsm-data-layer.php:574`, `includes/class-access-manager.php:494` |
| `{prefix}jpsm_mediavault_index` | `JPSM_Index_Manager::create_table()` | `id`, `path` (unique), `name`, `folder`, `size`, `extension`, `synced_at`, indexes on `name/folder/extension`. | Search, stats, folder extraction for permissions, debug script. | Batch/full index sync (`sync_batch`, `clear_index`). | `includes/modules/mediavault/class-index-manager.php:31`, `includes/modules/mediavault/class-index-manager.php:202`, `includes/class-access-manager.php:694`, `debug_index.php:5` |
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
- Legacy options are still mirrored as rollback-safe fallback stores and compatibility bridges.
- Folder permissions (`jpsm_folder_permissions`) remain in `wp_options` and are a candidate for a future dedicated table if access-rule complexity grows.
