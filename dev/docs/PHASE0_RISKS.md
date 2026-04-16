# JPSM Phase 0 Risk Summary

Date: 2026-02-06
Method: Static repository audit focused on endpoints, stores, and sessions.

## Prioritized Findings

| Priority | Finding | Why It Matters | Evidence |
|---|---|---|---|
| P1 | Sensitive B2 fallback credentials are hardcoded in bootstrap defaults. | If DB options are empty, runtime falls back to embedded keys. This increases credential exposure and environment coupling risk. | `jetpack-store-manager.php:35`, `jetpack-store-manager.php:37` |
| P1 | `mv_index_stats` endpoint has no auth check in handler. | Index metadata can be read without capability/session checks when endpoint is reachable. | `includes/modules/mediavault/template-vault.php:212` |
| P1 | Session model relies on weak/unsigned tokens. | `jdd_access_token` stores raw email and drives identity; `jpsm_auth_session` is deterministic MD5 of shared key + static salt. | `includes/class-access-manager.php:519`, `includes/class-access-manager.php:575`, `includes/class-access-manager.php:537` |
| P1 | Several access endpoints lack nonce/capability checks in handlers. | For logged-in contexts (and in some cases nopriv), handlers trust request parameters directly for read/write operations. | `includes/class-access-manager.php:595`, `includes/class-access-manager.php:618`, `includes/class-access-manager.php:662`, `includes/class-access-manager.php:653`, `includes/class-access-manager.php:638` |
| P2 | Secret key is localized to frontend/admin JS payloads. | Client-side exposure expands attack surface and complicates secret rotation. | `includes/class-jpsm-admin.php:219`, `includes/class-jpsm-dashboard.php:144` |
| P2 | Endpoint contract mismatch for folder permission updates. | Handler expects `tiers`, while one client sends `tier`; this can write empty permissions unintentionally. | `includes/class-access-manager.php:666`, `assets/js/jpsm-app.js:500` |
| P2 | Very large mixed-concern file in MediaVault (`template-vault.php`). | Endpoint handling, auth checks, HTML, CSS, and login/session logic in one place raise regression risk and slow safe changes. | `includes/modules/mediavault/template-vault.php:10`, `includes/modules/mediavault/template-vault.php:52`, `includes/modules/mediavault/template-vault.php:402` |
| P2 | Dormant downloader loader is unsafe if re-enabled. | Loader references missing file and hard `die()` call, which would break runtime if module is activated. | `includes/modules/downloader/loader.php:12`, `includes/modules/downloader/loader.php:14`, `jetpack-store-manager.php:30` |
| P3 | Debug script directly loads WordPress and queries index table. | Useful for diagnostics but should not be web-exposed in production paths. | `debug_index.php:1`, `debug_index.php:5` |

## High-Risk File Locations (Map)
- `/includes/modules/mediavault/template-vault.php`
- `/includes/class-access-manager.php`
- `/includes/class-jpsm-sales.php`
- `/includes/class-jpsm-dashboard.php`
- `/jetpack-store-manager.php`
- `/includes/modules/downloader/loader.php` (dormant, but dangerous to enable as-is)

## Immediate Guardrails for Phase 1
1. Centralize auth and nonce checks for all mutation/read-sensitive endpoints.
2. Replace raw email cookie with signed token and unified validator.
3. Remove secret key from frontend-localized payloads.
4. Move MediaVault query endpoints out of view/template class into dedicated controller/service.
