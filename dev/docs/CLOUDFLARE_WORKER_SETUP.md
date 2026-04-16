# Cloudflare Worker Setup for Private B2 Downloads

Date: 2026-02-13
Scope: Route MediaVault download URLs through Cloudflare while keeping Backblaze B2 bucket private.

## Architecture
- MediaVault still generates B2 presigned URLs server-side.
- Plugin rewrites only the URL origin to `jpsm_cloudflare_domain`.
- Cloudflare Worker receives the request and forwards it to `https://s3.<region>.backblazeb2.com` preserving path/query.

## 0. Prerequisites
- Existing B2 S3 config already working in plugin.
- Cloudflare account with Workers enabled.
- `wrangler` CLI installed (`npm i -g wrangler`).

## 1. Deploy fast on workers.dev (phase 1)
1. Create a Worker project and copy the template:
   - `scripts/cloudflare/b2-download-proxy-worker.js`
2. Set env vars:
   - `B2_REGION=us-west-004` (or your region)
   - `B2_BUCKET=<your bucket>`
   - `CORS_ALLOW_ORIGIN=*` (phase 1; tighten later)
3. Deploy and get URL:
   - `https://<worker>.<subdomain>.workers.dev`
4. In WordPress config:
   - Set `jpsm_cloudflare_domain` to that workers.dev origin.
5. Validate with a premium user:
   - Single file download
   - Folder download (browser fetch/CORS)
   - Preview direct mode

## 2. Move domain DNS to Cloudflare (phase 2)
1. Add your zone in Cloudflare.
2. Import and verify all DNS records before switching nameservers:
   - `A/AAAA/CNAME`
   - `MX`
   - `TXT` (SPF, DKIM, DMARC)
3. Change nameservers at your registrar to the Cloudflare-assigned pair.
4. Wait until zone status is `Active`.

## 3. Promote to custom subdomain (phase 3)
1. In Worker settings, add a custom domain (example: `downloads.example.com`).
2. Update plugin option:
   - `jpsm_cloudflare_domain=https://downloads.example.com`
3. Purge Cloudflare cache and run smoke checks again.

## 4. Rollback
- Clear `jpsm_cloudflare_domain` in settings and save.
- Plugin falls back to direct B2 presigned URLs immediately.

## Guardrails
- Keep bucket private.
- Worker must reject unsigned URLs (`X-Amz-Signature` required).
- Worker must enforce path prefix `/<bucket>/`.
- Do not alter query params (`X-Amz-*`) during forwarding.
- Do not use cache rules that ignore query string in this phase.

## Quick verification checklist
- `mv_get_presigned_url` response has Cloudflare host.
- `mv_get_preview_url` direct mode has Cloudflare host.
- `mv_list_folder` file URLs have Cloudflare host.
- Demo users still get `requires_premium` for download URL endpoint.

## Official references
- Backblaze + Cloudflare Workers: https://www.backblaze.com/docs/cloud-storage-use-cloudflare-workers-with-backblaze-b2
- Backblaze pricing: https://www.backblaze.com/cloud-storage/pricing
- Workers limits: https://developers.cloudflare.com/workers/platform/limits/
- Worker routes/custom domains: https://developers.cloudflare.com/workers/configuration/routing/routes/
