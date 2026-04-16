---
name: mediavault-manager-security-gate
description: "Compuertas y checklist de seguridad para MediaVault Manager (plugin WordPress): secretos/config, endpoints/sesiones, URLs presignadas B2 S3 y validacion del ZIP dist. Usar antes de releases o cambios en settings, auth, MediaVault o build."
---

# MediaVault Manager Security Gate

## Cuando usar esta skill (gatillos)
- Antes de generar/subir un ZIP de release (`dist/*.zip`).
- Si cambias cualquier cosa relacionada con:
  - credenciales Backblaze B2 S3 (Key ID / App Key / bucket / region),
  - pantallas de configuracion (Settings),
  - auth/sesiones/cookies,
  - endpoints AJAX/REST,
  - MediaVault (presigned URLs, downloads, index sync),
  - export/import,
  - empaquetado/build.

## Objetivo
Evitar fugas de secretos/PII y mantener un baseline de seguridad reproducible: "si pasa el gate, se puede distribuir".

## Quick Start (pre-release)
1. Unit tests: `composer test`
2. Integration smoke: `composer integration`
3. Build ZIP: `./build.sh`
4. Security gate:
   - `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh`
   - (Opcional) `--zip dist/<tu-artefacto>.zip`

## Invariantes de seguridad (no negociables)

### Secretos (Backblaze / credenciales / claves)
- Nunca hardcodear credenciales (ni como fallback default en `get_option(..., '<valor>')`).
- Nunca renderizar secretos existentes en HTML o JS (campos `value=` precargados, `wp_localize_script`, etc.).
- No exponer secretos en URL (querystring) ni en logs.
- Preferir constantes en `wp-config.php`. Si se usan `wp_options`, tratarlas como "write-only":
  - si el campo viene vacio en Settings, no sobreescribir,
  - para UI: "Configurado / No configurado" (sin mostrar el valor).

### MediaVault y URLs presignadas (B2 S3)
- El "funnel" (ver/buscar) puede ser abierto, pero NO debe entregar URLs descargables a usuarios sin permiso real.
- Presigned URLs se generan solo bajo auth/nonce y con verificacion de tier + folder permissions.
- "Locks en UI" no cuentan como control real si el backend ya entrego un link directo.

### Endpoints / Auth / Nonces
- Todo endpoint nuevo o modificado debe pasar por el helper central (`JPSM_Auth::authorize_request(...)` o equivalente).
- Endpoints admin: `manage_options` o session admin firmada (no "email en cookie").
- `nopriv` solo cuando sea estrictamente necesario, documentado, y sin secretos en URL.

### Sesiones y cookies
- Cookies firmadas y verificadas server-side.
- `HttpOnly` siempre; `Secure` cuando `is_ssl()`; `SameSite=Lax` como minimo.
- No confiar en email plano en cookie (solo migracion controlada, con upgrade inmediato).

### Datos y privacidad
- No loggear emails/keys/paths sensibles por defecto (solo en debug, y aun asi con redaccion).
- Export/Import: por defecto NO exporta secretos; si se permite, debe ser opt-in con warning fuerte.

## Hot zones (revision manual obligatoria)
- `jetpack-store-manager.php` (bootstrap, constants).
- `includes/class-jpsm-admin.php`, `includes/class-jpsm-admin-views.php` (Settings/UI, localize).
- `includes/class-jpsm-auth.php`, `includes/class-access-manager.php` (auth, sesiones).
- `includes/modules/mediavault/template-vault.php`, `includes/modules/mediavault/class-s3-client.php` (presigned URLs, downloads).
- `scripts/build-plugin.sh` (artefacto dist).

## Gate automatizado (preflight)
- Script principal: `SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh`
- Este gate debe usarse como compuerta antes de distribuir: cualquier finding => exit code != 0.
- Politica de salida: no se "silencian" findings; se corrigen y (si aplica) se agrega una regla para evitar regresiones.

## Documentacion que se actualiza cuando aplique
- `docs/SECURITY.md`
- `docs/CONFIGURATION.md`
- `docs/ENDPOINTS.md`
- `docs/SESSIONS.md`
- `docs/RELEASE_CHECKLIST.md`

## Respuesta ante incidentes (si se detecta fuga)
1. Rotar/revocar credenciales comprometidas (B2 keys, access keys, etc.).
2. Cortar releases hasta que el gate quede verde.
3. Revisar artefactos publicados y caches/CDN si aplican.
4. Agregar una prueba/regla al gate para que no vuelva a pasar.
