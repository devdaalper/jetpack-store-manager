# MediaVault Manager - Configuration

Date: 2026-02-13
Scope: Runtime configuration required to operate Manager + MediaVault using Backblaze B2 via S3 API.

## Required: Backblaze B2 (S3 API)

MediaVault requires:
- `jpsm_b2_key_id` (Key ID)
- `jpsm_b2_app_key` (Application Key)
- `jpsm_b2_region` (e.g. `us-west-004`)
- `jpsm_b2_bucket` (bucket name)

### Recommended storage for secrets
Prefer constants in `wp-config.php`:

```php
define('JPSM_B2_KEY_ID', '...');   // Key ID
define('JPSM_B2_APP_KEY', '...');  // Application Key
define('JPSM_B2_REGION', 'us-west-004');
define('JPSM_B2_BUCKET', 'my-bucket');
```

If a constant is defined, it overrides the corresponding option.

### Using the Settings UI
The plugin registers options:
- `jpsm_b2_key_id` (write-only UI)
- `jpsm_b2_app_key` (write-only UI)
- `jpsm_b2_region`
- `jpsm_b2_bucket`
- `jpsm_cloudflare_domain` (optional)

Important:
- Secret fields are intentionally NOT prefilled.
- Submitting an empty secret field keeps the existing stored value (prevents accidental wipes).

## Optional: Download Domain (CDN)
Option:
- `jpsm_cloudflare_domain` (example: `https://downloads.example.com`)

Validation rules:
- Accepted format: origin only (`https://host` or `https://host:port`).
- If scheme is omitted, runtime assumes `https://`.
- Rejected: `http://`, user/pass, path (`/foo`), query (`?x=1`), fragment (`#x`).
- Invalid values are sanitized to empty string (safe fallback to direct B2 URLs).

Runtime behavior:
- When `jpsm_cloudflare_domain` is empty/invalid: downloads use direct Backblaze B2 presigned URLs.
- When valid: these endpoints return URLs rewritten to the Cloudflare host:
  - `action=mv_get_presigned_url`
  - `action=mv_get_preview_url` (only `mode=direct`)
  - `action=mv_list_folder` (folder download file list)
- Protected preview proxy (`action=mv_stream_preview`) keeps server-to-server B2 fetch (no rewrite).

Operational note:
- For private buckets, the Cloudflare domain must point to a Worker proxy that forwards signed B2 requests.
- Setup guide: `docs/CLOUDFLARE_WORKER_SETUP.md`

## Manager Access Key (Dashboard)
Option:
- `jpsm_access_key` (write-only UI)

Notes:
- This is the key used for the "Dashboard login" flow (separate from WordPress admin login).
- The UI never shows the current value; leaving it blank preserves the stored key.

## Security Toggles (Recommended Defaults)
Options:
- `jpsm_wp_admin_only_mode` (0/1)
- `jpsm_allow_get_key` (0/1)

Defaults:
- `jpsm_wp_admin_only_mode=0` (allows the signed admin session login flow via dashboard key)
- `jpsm_allow_get_key=0` (do not accept secret keys from URL querystrings)

Notes:
- If `jpsm_wp_admin_only_mode=1`, privileged actions are restricted to WP admins (`manage_options`) only and secret-key auth is disabled.
- If you must support legacy requests that send `?key=...`, set `jpsm_allow_get_key=1` (not recommended). Prefer POST body or header `X-JPSM-Key`.

## Email (Sales Notifications)
Options:
- `jpsm_reply_to_email` (optional)
- `jpsm_notify_emails` (optional; list)

Defaults:
- If `jpsm_reply_to_email` is empty/invalid, the plugin uses WordPress `admin_email` as `Reply-To`.
- If `jpsm_notify_emails` is empty, the plugin sends sale confirmations to WordPress `admin_email`.

Notes:
- These values are not secrets, but they are operational data and should not be hardcoded.
- The Settings UI accepts one email per line (or comma/space separated). The option is stored as an array.

## MediaVault Frontend Admin Emails (Legacy)
Option:
- `jpsm_admin_emails` (array of email strings)

Notes:
- This option is kept for backward compatibility but **does not grant admin privileges by itself**.
- Privileged actions are controlled by WP admin (`manage_options`) or signed admin session (dashboard login).

## MediaVault Upgrade Contact (WhatsApp)
Option:
- `jpsm_whatsapp_number` (digits only; international format recommended)

Notes:
- Used for upgrade CTAs inside MediaVault (locks/teasers).
- If empty, the UI falls back to WordPress `admin_email` via `mailto:` when possible.

## Backblaze Permissions Checklist (Operational)
The B2 Application Key should be scoped to:
- The intended bucket only (least privilege).
- Required actions:
  - List objects (for browsing/sync)
  - Get objects (for downloads/presigned URLs)

Keep keys unique per environment (staging vs production).
