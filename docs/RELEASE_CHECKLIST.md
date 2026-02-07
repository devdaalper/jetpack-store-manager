# Release Checklist (JPSM)

This repo uses a two-layer validation:
1) Automated unit tests (fast, no WordPress runtime)
2) Integration smoke (wp-now runtime, real AJAX endpoints)

## Before Refactor / Before Release
1. Run unit tests:
   - `composer test`
2. Run integration smoke:
   - `composer integration`
3. Review docs drift:
   - If endpoints changed: update `docs/ENDPOINTS.md`
   - If storage changed: update `docs/DATA_STORES.md`
4. Confirm guardrails:
   - No new business logic in templates/views.
   - No new direct access to `$_POST`, `$_GET`, cookies in views.

## After Refactor / After Release Candidate
1. Re-run:
   - `composer test`
   - `composer integration`
2. Manual spot-check (only if integration is green):
   - Admin dashboard loads: `/wp-admin/admin.php?page=jetpack-store-manager`
   - MediaVault page renders: `/?pagename=descargas`
   - Sale -> History shows a new entry
   - Tier resolution returns expected tier

## Notes About Local Environment
- Local SMTP may fail: email sending can error while persistence + endpoint contracts still pass.
- Local B2/S3 credentials may be absent: index sync can return 403 while endpoint contracts still pass.

