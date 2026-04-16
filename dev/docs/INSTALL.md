# MediaVault Manager - Installation

This plugin is designed to run on any WordPress site with **Backblaze B2 (S3 API)** as the only storage backend.

## 1. Install
1. Upload and activate the plugin ZIP (`dist/mediavault-manager.zip`) in WordPress.
2. Confirm the admin menu shows **MediaVault Manager**.

## 2. Configure Backblaze B2 (S3)
1. Go to: **MediaVault Manager -> Configuración**.
2. Fill:
   - `Key ID`
   - `Application Key`
   - `Bucket Name`
   - `Region`
3. Click **Probar conexión**.

Security notes:
- Secrets are **write-only**: the UI will never display existing keys.
- Do not place secrets in URLs (the plugin defaults to blocking `?key=...`).

## 3. Create Pages (No Hardcoded Slugs)
1. Go to: **MediaVault Manager -> Setup**.
2. Click **Crear / Detectar páginas automáticamente**.

This will create (or detect) pages containing:
- MediaVault: `[mediavault_vault]` (alias of `[jpsm_media_vault]`)
- Manager (optional frontend): `[mediavault_manager]` (alias of `[jetpack_manager]`)

You can also select page IDs manually at:
- **MediaVault Manager -> Configuración -> Páginas (Onboarding)**

## 4. First Sync (Index)
1. Go to: **MediaVault Manager -> Sincronizador B2**
2. Run the index sync and confirm the index stats update.

## 5. Verify
- MediaVault page loads and can browse/search (visibility funnel).
- Tier 0 (demo) can preview but cannot download.
- Paid tiers can download only what they are entitled to.

## 6. Optional: Cloudflare download domain (private bucket)
1. Deploy the Worker proxy using:
   - `scripts/cloudflare/b2-download-proxy-worker.js`
2. Configure `jpsm_cloudflare_domain` in:
   - **MediaVault Manager -> Configuración -> Cloudflare Domain**
3. Start with workers.dev, then move to custom subdomain.

Full guide:
- `docs/CLOUDFLARE_WORKER_SETUP.md`
