# MediaVault v2 — Configuración de Servicios

## Supabase

| Campo | Valor |
|-------|-------|
| **Organización** | JetPack Store |
| **Proyecto** | MediaVault |
| **Plan** | Free ($0/mes) |
| **Región** | East US (Ohio) — us-east-2 |
| **Project ID** | `pmrhnjkxibonfvikalqw` |
| **URL** | `https://pmrhnjkxibonfvikalqw.supabase.co` |
| **Publishable Key** | `sb_publishable_o0MzGq_Q2ye38AUXR0_t9A_nsVpyvm2` |
| **Secret Key** | (en `.env.local`, nunca commitear) |
| **Dashboard** | https://supabase.com/dashboard/project/pmrhnjkxibonfvikalqw |

### Base de datos
- **13 tablas** creadas via SQL Editor (migrations 001 + 002)
- **5 RLS policies** activas (profiles, play_counts, file_index, folder_permissions, behavior_events)
- **Trigger** `on_auth_user_created` → auto-crea perfil en registro
- **Config seed** en `app_config`: active_index_version=1, cloudflare_domain, whatsapp_number, sidebar_order

### Auth
- Método: **Magic Link** (email OTP)
- Callback URL: `{APP_URL}/auth/callback`
- Session: cookies httpOnly via @supabase/ssr

---

## Vercel

| Campo | Valor |
|-------|-------|
| **Team** | Draio Peña's projects |
| **Plan** | Hobby (gratis) |
| **Proyecto** | mediavault |
| **Repo GitHub** | devdaalper/jetpack-store-manager |
| **Root Directory** | `mediavault-v2` |
| **Framework** | Next.js |
| **Dashboard** | https://vercel.com/draio-penas-projects/mediavault |
| **URL producción** | https://mediavault-teal.vercel.app |
| **Project ID** | `prj_nE1TjmvOhenSYH44NOYsZKGWozIe` |

### Environment Variables (configurar en Vercel Dashboard)

```
NEXT_PUBLIC_SUPABASE_URL=https://pmrhnjkxibonfvikalqw.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=sb_publishable_o0MzGq_Q2ye38AUXR0_t9A_nsVpyvm2
SUPABASE_SERVICE_ROLE_KEY=<secret, copiar de Supabase API Keys>
B2_KEY_ID=<tu Backblaze Key ID>
B2_APP_KEY=<tu Backblaze App Key>
B2_REGION=us-west-004
B2_BUCKET=<tu bucket name>
```

---

## GitHub

| Campo | Valor |
|-------|-------|
| **Repo** | devdaalper/jetpack-store-manager |
| **Branch** | main |
| **Carpeta mediavault-v2** | `mediavault-v2/` (proyecto Next.js) |
| **Carpeta plugin WP** | `plugin/` (WordPress, legado) |
| **CI** | `.github/workflows/ci.yml` (lint + typecheck + test + build) |

---

## Backblaze B2

| Campo | Valor |
|-------|-------|
| **Bucket** | Recursos-JetPackStore |
| **Región** | us-west-004 |
| **Key ID** | `005d454a99b9dc6000000000d` (Master Key) |
| **App Key** | (en `.env.local` y Vercel env vars, nunca commitear) |
| **Región real** | `us-east-005` (NO us-west-004) |

---

## DNS (Hostinger)

| Registro | Tipo | Valor | Propósito |
|----------|------|-------|-----------|
| `jetpackstore.net` | A | (Hostinger IP actual) | WordPress producción |
| `app.jetpackstore.net` | CNAME | `cname.vercel-dns.com` | MediaVault v2 (nuevo) |

---

## Archivos locales importantes

| Archivo | Propósito | ¿Se commitea? |
|---------|-----------|---------------|
| `mediavault-v2/.env.local` | Secrets (keys, passwords) | **NO** |
| `mediavault-v2/.env.example` | Template de variables | Sí |
| `mediavault-v2/CONFIG.md` | Este documento | Sí |
| `mediavault-v2/supabase/migrations/` | Schema SQL | Sí |
| `mediavault-v2/scripts/migrate-from-wordpress.ts` | Migración de datos | Sí |
