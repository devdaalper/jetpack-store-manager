# Upgrade Guide: JetPack Store Manager -> MediaVault Manager (Option A)

Date: 2026-02-08
Scope: Option A rebrand and productization steps that keep internal storage keys/tables unchanged.

## Summary
This upgrade changes the visible branding to **MediaVault Manager** while keeping the existing plugin slug and storage intact.

## What Changed (Visible)
- Plugin display name: now shows as **MediaVault Manager** in the WordPress Plugins list.
- Admin menu labels: now show **MediaVault Manager** (page slug remains the same).
- New preferred shortcodes (aliases):
  - Manager/Dashboard: `[mediavault_manager]` (alias of `[jetpack_manager]`)
  - MediaVault: `[mediavault_vault]` (alias of `[jpsm_media_vault]`)
- Build artifact name: `dist/mediavault-manager.zip`

## What Did NOT Change (Compatibility)
To avoid breaking existing sites during Option A, these remain unchanged:
- Plugin folder/slug: `jetpack-store-manager/`
- Main plugin file: `jetpack-store-manager.php`
- Internal option keys and tables: all existing `jpsm_*` keys/tables remain the source of truth.
- Existing shortcodes continue to work:
  - `[jetpack_manager]`
  - `[jpsm_media_vault]`
- Admin URLs remain unchanged:
  - `/wp-admin/admin.php?page=jetpack-store-manager`

## Upgrade Steps
1. Backup (hosting-assisted or full WP backup: files + DB).
2. Build or obtain the release ZIP:
   - `./build.sh` -> `dist/mediavault-manager.zip`
3. Install/Update:
   - WP Admin -> Plugins -> Add New -> Upload Plugin (ZIP)
4. Post-upgrade spot checks:
   - Admin menu loads and pages render.
   - Existing pages with old shortcodes still render correctly.
   - Settings values are intact (B2 config, access key, etc.).

## Rollback
- Re-install the previous known-good ZIP of the plugin (same slug).
- Option A does not migrate/rename storage, so rollback does not require data migration.

