# Phase 2 Migration and Rollback Notes

Date: 2026-02-07  
Scope: Data-layer migration for sales, explicit user tiers, leads, and demo play counts.

## Migration Script (Implemented)

Migration is executed by plugin bootstrap:

- `jetpack-store-manager.php` -> `jpsm_init()` -> `JPSM_Data_Layer::bootstrap()`.
- `JPSM_Data_Layer::bootstrap()` runs:
  1. `install_schema()` (table create/upgrade via `dbDelta`).
  2. `run_migrations()` (one-time legacy options -> tables).

Tracked options:

- `jpsm_data_layer_schema_version`
- `jpsm_data_layer_migration_state`

Entities migrated:

- `jpsm_sales_log` -> `{prefix}jpsm_sales`
- `jpsm_user_tiers` -> `{prefix}jpsm_user_tiers`
- `jpsm_leads_list` -> `{prefix}jpsm_leads`
- `jpsm_demo_play_counts` -> `{prefix}jpsm_play_counts`

## Safety Model

- All core reads/writes now route through `JPSM_Data_Layer` in sales/access/dashboard flows.
- Legacy options remain mirrored on writes to preserve rollback safety.
- If a target table is missing, methods fall back to legacy options.
- Migration state is not marked as done when migration is skipped due missing table.

## Verification Checklist

1. Schema version exists in options (`jpsm_data_layer_schema_version`).
2. Migration state exists in options (`jpsm_data_layer_migration_state`).
3. Tables exist:
   - `{prefix}jpsm_sales`
   - `{prefix}jpsm_user_tiers`
   - `{prefix}jpsm_leads`
   - `{prefix}jpsm_play_counts`
4. A sale written through AJAX appears in `{prefix}jpsm_sales`.
5. Legacy mirror options still update for compatibility.

## Rollback Plan

Rollback is no-data-loss because legacy options are still mirrored.

1. Backup DB before rollback.
2. Disable/avoid table usage by dropping or renaming Phase 2 tables:
   - `{prefix}jpsm_sales`
   - `{prefix}jpsm_user_tiers`
   - `{prefix}jpsm_leads`
   - `{prefix}jpsm_play_counts`
3. Keep legacy options (`jpsm_sales_log`, `jpsm_user_tiers`, `jpsm_leads_list`, `jpsm_demo_play_counts`) as the active source.
4. Optional: clear migration-state option (`jpsm_data_layer_migration_state`) after rollback.
5. Re-run smoke checks to confirm fallback behavior.

## Known Runtime Caveat (Local Smoke)

- In local smoke (`wp-now`), email-related calls can return `wp_mail` failure.
- This does not block persistence validation for sales/tier/access data-layer behavior.
