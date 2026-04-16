# MediaVault Manager - Troubleshooting

## Setup Wizard / Pages

### MediaVault does not render full screen
- Ensure the page contains `[mediavault_vault]` (or legacy `[jpsm_media_vault]`).
- Alternatively set the page ID in **Configuración -> Páginas (Onboarding)**.

### MediaVault login loops / always returns to the login screen
Common causes:
- Full-page cache (Hostinger/LiteSpeed, etc.) is serving a cached anonymous version and does not vary by our custom session cookie (`jdd_access_token`).
  - Purge the cache and exclude the MediaVault page URL from caching.
  - If your cache plugin supports it, add `jdd_access_token` to "Do Not Cache Cookies".
- www vs non-www mismatch (cookie set on one host, page loads on the other).
  - Always use the same domain as configured in WordPress:
    - **Settings -> General -> WordPress Address (URL)**
    - **Settings -> General -> Site Address (URL)**

### Guest/Demo links do nothing
- Ensure the request is on the MediaVault page (shortcode or selected page ID).
- Guest flow uses `?invitado=1` and then redirects to a clean URL after setting a signed session cookie.

## Backblaze B2 (S3) Configuration

### "MediaVault no está configurado"
- Go to **Configuración -> MediaVault (Backblaze B2)** and set:
  - Key ID, Application Key, Bucket, Region.
- Use **Probar conexión** to validate.

### B2 connectivity fails
Common causes:
- Wrong region (B2 regions look like `us-west-004`).
- App key lacks permissions for the bucket (must allow listing + reading).
- Bucket name mismatch.
- If the UI shows an S3 error code, use it to narrow down quickly:
  - `AccessDenied`: key permissions or bucket restriction mismatch.
  - `SignatureDoesNotMatch` / `AuthorizationHeaderMalformed`: region/key mismatch (or Key ID/Application Key swapped).
  - `NoSuchBucket`: bucket name/region mismatch.

## Cloudflare Download Domain

### Cloudflare domain is saved but download URL still shows backblaze host
- Check the exact setting in **Configuración -> Cloudflare Domain**:
  - Must be origin-only (`https://host` or `https://host:port`).
  - Any path/query/fragment is rejected and sanitized to empty.
- Confirm endpoint behavior using Network tab:
  - `mv_get_presigned_url`
  - `mv_get_preview_url` (direct mode)
  - `mv_list_folder` (folder downloads)

### Download URL uses Cloudflare host but request fails with 403
- Most common: Worker is not forwarding full signed query string (`X-Amz-*`).
- Ensure Worker blocks unsigned requests but forwards signed ones unchanged.
- Ensure Worker route/domain points to the same B2 region/bucket configured in plugin.

### Folder download fails from browser but single-file download works
- Folder flow uses `fetch()` and needs CORS headers from Worker.
- Ensure Worker responds to `OPTIONS` and includes:
  - `Access-Control-Allow-Origin`
  - `Access-Control-Allow-Methods: GET, HEAD, OPTIONS`
  - `Access-Control-Allow-Headers` including `Range`
  - `Access-Control-Expose-Headers` including `Content-Range`, `Content-Disposition`

### After moving DNS to Cloudflare, mail or other services break
- Re-check imported DNS records in Cloudflare before/after nameserver switch:
  - `MX`, `SPF (TXT)`, `DKIM`, `DMARC`, and any API subdomains.
- Cloudflare setup guide: `docs/CLOUDFLARE_WORKER_SETUP.md`

## Index / Search

### Search returns no results
- Run the index sync in **Sincronizador B2**.
- Check **Setup -> Health Checks**:
  - Index table exists
  - Index stale status
  - Total items

### Index sync errors
- In local/dev environments, B2 credentials are often missing. Confirm config first.
- Ensure only WP admins (or authorized admin session) can run sync.

## Permissions / Locks

### Users can see locked content but cannot download
This is expected: browsing/preview acts as a conversion funnel. Downloads are enforced server-side.

### Paid user cannot download something they should
- Verify folder permission rules in **Control de Accesos**.
- Check the folder path normalization (trailing `/`) and that the rule exists for the correct path.

## Security

### Presigned URLs appear in HTML/JS
This should not happen. Downloads must be resolved on-demand.
- Run the release checks from `01-WORDPRESS-SUBIR/jetpack-store-manager` before release:
  - `composer test`
  - `composer integration`
  - `bash ../../03-PROYECTO-TRABAJO/SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build`
