# MediaVault Manager - Security

Date: 2026-02-08
Scope: Security rules for this WordPress plugin (Manager + MediaVault) with Backblaze B2 S3 only.

## Goals
- Prevent leakage of secrets (B2 credentials, access keys) in code, UI, JS, logs, or release artifacts.
- Ensure endpoints are protected by nonce + authorization.
- Ensure MediaVault visibility funnel does not allow unauthorized downloads.

## Secret Handling Policy

### What counts as a secret
- Backblaze B2 S3 credentials:
  - Key ID
  - Application Key
- Dashboard access key (`jpsm_access_key`)
- Any future API tokens/SMTP creds

### Rules
- Never hardcode secrets (including as `get_option(..., '<fallback>')` defaults).
- Never render stored secrets into HTML/JS:
  - No `<input value="...">` prefilled from `get_option(...)`
  - No `wp_localize_script` with secret values
- Never accept secrets via URL querystring by default.
  - Legacy exception (opt-in): `jpsm_allow_get_key=1` re-enables `?key=...` (not recommended).
  - Preferred transport: POST body `key` or HTTP header `X-JPSM-Key`.
- Prefer storing secrets as constants in `wp-config.php`.
  - Options can be used, but UI must be write-only and safe against accidental wipes.

### Rotation policy
If a secret ever existed in the repository history or a distributed ZIP:
1. Revoke/rotate the secret immediately (assume compromise).
2. Cut releases until the security gate is clean.
3. Document the incident and add a detection rule to prevent recurrence.

## Endpoint and Session Rules
- All sensitive endpoints must require:
  - Nonce validation
  - Authorization (WP capability or signed admin session)
- Sessions must be signed and verified server-side.
- Cookies must be `HttpOnly` and `Secure` when `is_ssl()` is true.

## MediaVault Download Rules (Security Boundary)
- Visibility funnel is allowed (browse/search/preview can be shown to all tiers).
- Downloads must be enforced server-side:
  - Tier 0 (demo) cannot download.
  - Paid tiers can download only when `JPSM_Access_Manager::user_can_access(...)` allows it.
- Never return presigned download URLs to users who are not entitled to download that path.
  - For demo/locked content preview: use the preview proxy (`mv_get_preview_url` -> `mv_stream_preview`) which is byte-capped and token+session-bound.

## WP Admins Only Mode (Opt-in)
Option:
- `jpsm_wp_admin_only_mode` (0/1)

When enabled:
- Only WP admins (`manage_options`) are considered privileged.
- Secret-key auth and signed admin sessions created from secret keys are disabled.

## Release Gates (Blocking)
Before distributing a release ZIP:
1. `composer release:verify`

This runs:
- `composer test`
- `composer integration`
- `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build`

## Incident Response Checklist
1. Identify what leaked (secret vs PII vs content URLs).
2. Rotate/revoke impacted credentials.
3. Disable affected endpoints/flows temporarily if needed.
4. Publish a fixed build and confirm the gate passes.
5. Add regression detection (gate rule + smoke assertion).
