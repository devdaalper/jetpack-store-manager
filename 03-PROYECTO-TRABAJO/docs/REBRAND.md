# Rebrand Notes: MediaVault Manager (Option A)

Date: 2026-02-08
Scope: Rebrand the product to "MediaVault Manager" without breaking existing installs.

## Goals
- Make the plugin presentable and general-purpose for buyers.
- Remove owner-specific branding from user-facing UI.
- Keep full functionality: Manager + MediaVault (Backblaze B2 via S3 only).

## Option A Policy (Compatibility First)
During Option A, we keep internal identifiers stable to avoid breaking:
- Plugin slug/folder stays `jetpack-store-manager/`
- Main file stays `jetpack-store-manager.php`
- Text domain stays `jetpack-store-manager`
- Storage keys/tables/cookies remain `jpsm_*` and existing session/cookie names

This means:
- A site can upgrade in-place with no data loss.
- Existing pages/posts keep rendering (old shortcodes still work).

## User-Facing Branding
We update:
- Plugin display name (Plugins list)
- Admin menu labels
- Headings and copy that reference the old brand
- Provide new preferred shortcodes:
  - `[mediavault_manager]` for the Manager/Dashboard
  - `[mediavault_vault]` for MediaVault

## Build Artifact Naming
The production ZIP is named:
- `dist/mediavault-manager.zip`

But the internal folder inside the ZIP remains:
- `jetpack-store-manager/`

This is intentional for Option A.

## Follow-Up (Option B)
After Option A is complete and stable, Option B will define:
- Storage v2 keys/tables/cookies with an idempotent migrator
- Optional controlled slug rename plan (high-risk in WordPress; must be verified + rollbackable)

Reference: `docs/PRODUCTIZATION_PLAN.md` (Phase B0).

