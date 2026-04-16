# MediaVault Manager - Productization Plan (Option A then Option B)

Date: 2026-02-08
Scope: Make this plugin installable on any WordPress site without leaking owner data, while preserving all current functionality (Manager + MediaVault) and limiting storage to Backblaze B2 via S3 API.

## Non-Negotiables
- No secrets hardcoded in code or shipped artifacts (repo or `dist/*.zip`).
- No secrets rendered into HTML/JS (including password fields with prefilled `value=`).
- No destructive DB operations in Option A (no drops, no mass deletes).
- Fail safe: missing critical config must disable the dependent feature and show a clear admin notice.
- Backblaze B2 S3 only (no multi-provider expansion during productization).

## Definitions
- Option A: Ship a general plugin while keeping existing internal keys/tables (`jpsm_*`) for compatibility.
- Option B: Post-Option A cleanup: storage v2 + internal rename + optional slug rename with verified migration and rollback.

## Release Gates (Required)
- `composer test`
- `composer integration`
- Build ZIP via `./build.sh`
- Security gate (repo + ZIP):
  - `bash ../../03-PROYECTO-TRABAJO/SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build`

## Phases (Option A)

### A0. Secret Hygiene (Blocker)
Status: Completed (2026-02-08). Evidence: `docs/SMOKE_TESTS.md` (Phase A0).

Goal: remove all owner-specific credentials/defaults and prevent future leaks.

Tasks
1. Remove hardcoded fallbacks for B2 credentials and any owner domains/emails.
2. Settings UI becomes write-only for secrets (never display existing values).
3. Eliminate secret localization to JS payloads.
4. Add/confirm rotation guidance for any previously exposed credentials.

Deliverables
- `docs/SECURITY.md` (secret handling + rotation checklist).
- `docs/CONFIGURATION.md` (where config lives: constants vs options).
- Update `docs/RELEASE_CHECKLIST.md` to include the security gate as blocking.

Definition of Done
1. Security gate passes (repo + `dist/*.zip`).
2. A clean install without config does not operate with "embedded credentials"; it shows config-required notices.

### A1. Configuration System (Installable For Any Customer)
Status: Completed (2026-02-08; code + automated gates). Evidence: `docs/SMOKE_TESTS.md` (Phase A1).

Goal: onboarding-ready config without code changes by the buyer.

Tasks
1. Central config service (single source of truth, strict validation).
2. Admin settings:
   - B2 S3: key id, app key, region, bucket
   - Optional: CDN download domain (only if actually used)
   - Email: reply-to + admin notification email(s)
3. "Test connection" button (admin-only, nonce protected).

Deliverables
- `docs/CONFIGURATION.md` expanded with validation rules and B2 permission checklist.

Definition of Done
1. New WP install can configure and use Manager + MediaVault without custom edits.
2. Existing install keeps working after setting values once.

### A2. Rebrand To "MediaVault Manager" (Visible Branding Only)
Status: Completed (2026-02-08). Evidence: `docs/SMOKE_TESTS.md` (Phase A2), `docs/UPGRADE_GUIDE.md`, `docs/REBRAND.md`.

Goal: rename the product while not breaking current installs.

Approach
- Change visible branding everywhere.
- Keep internal storage keys/tables/cookies unchanged during Option A.

Tasks
1. Plugin headers, menus, UI labels, docs updated to "MediaVault Manager".
2. Shortcodes:
   - new shortcodes (preferred)
   - old shortcodes remain as aliases with deprecation note (no breakage)
3. Remove references to owner brand/domains from UI copy.

Deliverables
- `docs/UPGRADE_GUIDE.md` (what changes after update).
- `docs/REBRAND.md` (visible vs internal naming, and future cutover plan).

Definition of Done
1. WordPress UI shows "MediaVault Manager" while functionality remains identical.

### A3. Hardening (Sessions, Endpoints, Presigned URLs)
Status: Completed (2026-02-08). Evidence: `docs/SMOKE_TESTS.md` (Phase A3), `docs/ENDPOINTS.md`, `docs/SESSIONS.md`.

Goal: secure-by-default behavior without losing features.

Tasks
1. Endpoints: strict auth+nonce for any sensitive read/write.
2. Sessions: signed cookies; no trust in raw email; admin checks centralized.
3. MediaVault: presigned URL policy:
   - browsing/search results can be visible for funnel
   - but downloadable presigned URLs must not be returned unless user is entitled
4. Remove dangerous query actions or lock them behind admin auth + nonce.
5. Config: add an opt-in "WP admins only" mode that disables any secret-key auth paths where feasible (keep backward compatibility by default).

Deliverables
- Update `docs/ENDPOINTS.md`, `docs/SESSIONS.md`.
- Add smoke assertions for "demo cannot obtain presigned download URLs".

Definition of Done
1. Demo/invitado cannot extract direct download links.
2. Admin retains full capability: sync, permissions, sales, resend, etc.

### A4. Onboarding + Operations
Status: Completed (2026-02-08). Evidence: `docs/SMOKE_TESTS.md` (Phase A4), `docs/INSTALL.md`, `docs/TROUBLESHOOTING.md`.

Goal: make the plugin self-serve for buyers and resilient in common hosting setups.

Tasks
1. First-run notices/wizard.
2. Do not hardcode page slugs (e.g. `descargas`); detect shortcode or allow admin selection.
3. Health checks panel (config valid, index size, last sync, B2 connectivity).

Deliverables
- `docs/INSTALL.md`
- `docs/TROUBLESHOOTING.md`

Definition of Done
1. Buyer can install and complete setup without developer help.

### A5. Release Discipline
Status: Completed (2026-02-08). Evidence: `docs/SMOKE_TESTS.md` (Phase A5), `docs/RELEASE_CHECKLIST.md`, `docs/DEPLOYMENT.md`.

Goal: prevent regression and accidental leaks.

Tasks
1. Security gate must be in release checklist and is blocking.
2. Keep `dist/` build as the only deployment artifact.

Deliverables
- Updated `docs/RELEASE_CHECKLIST.md`, `docs/DEPLOYMENT.md`.

Definition of Done
1. Repeatable release process, including security gates.

## Phase (Option B) - Mandatory Follow-Up (Do Not Skip)

### B0. Storage v2 + Internal Rename + Optional Slug Rename
Goal: clean internal naming and reduce legacy friction without losing data.

Tasks
1. Define v2 keys/tables/cookies (e.g., `mvm_*`) and new storage layout.
2. Implement idempotent migrator from `jpsm_*` to v2.
3. Verification: counts + sampling + checksum-like checks.
4. Rollback strategy: keep read path for v1 until v2 is verified and explicitly enabled.
5. Optional: plugin slug/folder rename with a controlled upgrade plan (WordPress treats slug changes as "new plugin" unless handled carefully).

Deliverables
- `docs/STORAGE_V2_PLAN.md` (design, migration, verification, rollback).

Definition of Done
1. Migration can run safely on production-like data and is verifiable.
2. Rollback path is documented and tested.
