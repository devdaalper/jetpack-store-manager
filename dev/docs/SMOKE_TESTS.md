# JPSM Smoke Tests

## 2026-04-07 - Hotfix consistencia de botones Reproducir en demo
Environment: PHP 8.5.1 + PHPUnit 11.5.51 + Node syntax check + `wp-now` integration.  
Scope: Corregir la intermitencia de `Reproducir` en MediaVault para navegación AJAX de carpetas, aceptando `f.ext` sin punto en `mediavault-client.js` y agregando una guarda para que demo siga pudiendo previsualizar media sin abrir descargas.

| Check | Result | Notes |
|---|---|---|
| 1. JS syntax on modified source file | Pass | `node --check 01-WORDPRESS-SUBIR/jetpack-store-manager/includes/modules/mediavault/assets/js/mediavault-client.js` pasó. |
| 2. Full unit suite | Pass | `composer test` pasó (56 tests, 153 assertions, 15 deprecations). Incluye nueva guarda para extensiones bare (`mp4`, `mp3`) en `MediaVaultClientNoReloadTest`. |
| 3. Full integration smoke | Pass | `composer integration` pasó, incluyendo login MediaVault, search, preview premium inline, bloqueo demo de descarga e index stats/sync. |
| 4. Build parity | Pass | `bash build.sh` regeneró `dist/mediavault-manager.zip` desde `01-WORDPRESS-SUBIR/jetpack-store-manager` con el JS corregido. |

## 2026-03-13 - Hotfix cache-bust automático para MediaVault en móviles
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (pruebas dirigidas).  
Scope: Evitar que usuarios móviles queden atrapados con `mediavault-client.js` viejo tras un hotfix, versionando el asset por `filemtime` en el loader de MediaVault y subiendo `JPSM_VERSION` a `1.2.5` para empujar el release actual.

| Check | Result | Notes |
|---|---|---|
| 1. PHP syntax on modified source files | Pass | `php -l` pasó en `01-WORDPRESS-SUBIR/jetpack-store-manager/includes/modules/mediavault/loader.php` y `jetpack-store-manager.php`. |
| 2. PHP syntax on modified build files | Pass | `php -l` pasó en `03-PROYECTO-TRABAJO/dist/build/jetpack-store-manager/includes/modules/mediavault/loader.php` y `jetpack-store-manager.php`. |
| 3. Targeted regression guards | Pass | `01-WORDPRESS-SUBIR/jetpack-store-manager/vendor/bin/phpunit -c 03-PROYECTO-TRABAJO/phpunit.xml 03-PROYECTO-TRABAJO/tests/unit/MediaVaultPreviewSigningTest.php 03-PROYECTO-TRABAJO/tests/unit/MediaVaultLoaderVersioningTest.php` pasó (3 tests, 8 assertions). |
| 4. Full integration smoke | Fail (environment) | `php scripts/integration/wpnow_endpoints_test.php` volvió a fallar antes de MediaVault con `Dashboard did not render, status=403`, así que no sirvió como evidencia de este hotfix. |

## 2026-03-13 - Hotfix preview directa inline en MediaVault
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (prueba dirigida).  
Scope: Restaurar la reproducción de previsualizaciones directas para tiers con acceso, firmando la URL de preview con `Content-Disposition: inline` sin tocar el bloqueo por tier, el proxy de demo/locked, ni la descarga normal con `attachment`.

| Check | Result | Notes |
|---|---|---|
| 1. PHP syntax on modified source files | Pass | `php -l` pasó en `01-WORDPRESS-SUBIR/jetpack-store-manager/includes/modules/mediavault/class-s3-client.php` y `template-vault.php`. |
| 2. PHP syntax on modified build files | Pass | `php -l` pasó en `03-PROYECTO-TRABAJO/dist/build/jetpack-store-manager/includes/modules/mediavault/class-s3-client.php` y `template-vault.php`. |
| 3. Targeted regression guard | Pass | `01-WORDPRESS-SUBIR/jetpack-store-manager/vendor/bin/phpunit -c 03-PROYECTO-TRABAJO/phpunit.xml 03-PROYECTO-TRABAJO/tests/unit/MediaVaultPreviewSigningTest.php` pasó (2 tests, 4 assertions). |
| 4. Full integration smoke | Fail (environment) | `php scripts/integration/wpnow_endpoints_test.php` arrancó `wp-now`, pero falló temprano con `Dashboard did not render, status=403`, así que no fue evidencia útil para este hotfix. |

## 2026-03-10 - Hotfix correo de confirmación interna de ventas
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (prueba dirigida).  
Scope: Restaurar el fallback legacy para confirmaciones internas de venta a `jetpackstore.oficial@gmail.com` cuando `jpsm_notify_emails` está vacío, mantener sincronizado `dist/build`, y agregar una guarda contra regresión.

| Check | Result | Notes |
|---|---|---|
| 1. PHP syntax on modified source files | Pass | `php -l` pasó en `01-WORDPRESS-SUBIR/jetpack-store-manager/includes/class-jpsm-sales.php` y `class-jpsm-admin-views.php`. |
| 2. PHP syntax on modified build files | Pass | `php -l` pasó en `03-PROYECTO-TRABAJO/dist/build/jetpack-store-manager/includes/class-jpsm-sales.php` y `class-jpsm-admin-views.php`. |
| 3. Targeted regression guard | Pass | `vendor/bin/phpunit -c 03-PROYECTO-TRABAJO/phpunit.xml 03-PROYECTO-TRABAJO/tests/unit/SalesEmailNotificationsTest.php` pasó (2 tests, 9 assertions). |
| 4. Full unit suite | Not run | El harness histórico de `03-PROYECTO-TRABAJO/tests/unit` sigue apuntando al layout previo del repo (`../../includes/*`), así que se ejecutó una prueba dirigida para este hotfix. |
| 5. Full integration smoke | Not run | Pendiente ejecución con `wp-now`/WordPress real para validar envío real de correo y endpoints end-to-end. |

## 2026-03-03 - Desktop BPM API + GUI wizard strict flow
Environment: PHP 8.5.1 + PHPUnit 11.5.51 + Python local compile checks.  
Scope: Desktop/API integration: token endpoints, batch import/rollback contract plumbing, desktop GUI strict wizard + rollback control, and restart-safe queue recovery.

| Check | Result | Notes |
|---|---|---|
| 1. PHP syntax on modified plugin files | Pass | `php -l` passed for `class-index-manager.php`, `class-jpsm-admin.php`, `class-jpsm-admin-views.php`. |
| 2. Desktop Python syntax compile | Pass | `python3 -m py_compile desktop-bpm-app/src/bpm_desktop/**/*.py` passed. |
| 3. Admin JS syntax | Pass | `node --check assets/js/admin.js` passed. |
| 4. Index manager unit baseline | Pass (with deprecations) | `vendor/bin/phpunit tests/unit/IndexManagerTest.php` -> 9 tests, 21 assertions, deprecations only. |
| 5. End-to-end WP runtime (token + import + rollback) | Not run | Pending wp-now/browser execution with real token and sample desktop payload. |

## 2026-03-03 - BPM Deep Extraction (Reset + Acoustic Mode)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Complete automatic BPM extraction flow in Synchronizer with deep mode (`mode=deep`), reset endpoint for `auto_*` scan marks, and acoustic estimation path via `ffmpeg`.

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | `php -l` passed for modified PHP files (`class-index-manager.php`, `class-jpsm-admin.php`, `class-jpsm-admin-views.php`). |
| 2. Admin dashboard page renders | Pass (integration baseline) | `php scripts/integration/wpnow_endpoints_test.php` passed end-to-end; admin-authenticated flows remained healthy. |
| 3. Register sale flow works and log entry appears | Pass | Covered by integration step `process_sale + history`. |
| 4. Resend email endpoint responds | Pass | Covered by integration step `resend_email`. |
| 5. Access control page loads and returns user tier | Pass | Covered by integration step `get_user_tier`. |
| 6. MediaVault renders and search returns results | Pass | Covered by integration step `MediaVault login + search`. |
| 7. Index sync endpoint responds (admin only) | Pass | Covered by integration step `index stats + sync`. |

Additional evidence:
- `vendor/bin/phpunit tests/unit/IndexManagerTest.php`: Pass (9 tests, 21 assertions; deprecations only).
- `node --check assets/js/admin.js`: Pass.

## 2026-02-28 - MediaVault Sync v2 (Atomic) + Search v2 + Instrumentation Guard
Environment: PHP 8.5.1 + PHPUnit 11.5.x (unit tests) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Atomic double-buffer sync (`primary/shadow`), enriched index metadata, stale/empty quality indicators in sync UI, search v2 payload (`items/meta/suggestions`) with type forwarding and fuzzy fallback, and regression checks to preserve behavior telemetry contract.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed (37 tests, 94 assertions, 4 deprecations). |
| Integration smoke | Pass | `COMPOSER_PROCESS_TIMEOUT=900 composer integration` passed, including new checks for `mv_search_global` v2 shape (`items/meta/suggestions` + `meta.index_state`) and behavior report summary keys (`search_total`, `search_zero_results_total`, `search_zero_results_rate`). |
| Instrumentation compatibility | Pass | Existing events (`search_executed`, `search_zero_results`) and `query_norm` pipeline remain unchanged; added integration guard verifies report payload structure did not regress. |
| Pre/Post KPI comparability snapshot | Pending (operational) | Production/staging window snapshot (`search_total`, `search_zero_results_total`, `search_zero_results_rate`, top queries) should be captured on the same month window before/after rollout for business comparison. |

## 2026-02-23 - Security Hotfix (Bloqueo Panel Admin en `[mediavault_vault]`)
Environment: PHP 8.5.1 + PHPUnit 11.5.x (unit tests).  
Scope: Mitigar exposición de funciones administrativas en frontend MediaVault (`Panel Admin` flotante, acciones `mv_*` admin y visibilidad de flags admin en `MV_USER_DATA`).

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed (37 tests, 94 assertions, 4 deprecations). |
| Integration smoke | Fail (environment) | `composer integration` failed because `wp-now` did not become ready on `http://localhost:8099`. |
| Security gate (repo + ZIP) | Pass | `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh` passed (`0 findings`). |
| Manual MediaVault frontend smoke | Not run | Pending browser validation in página con shortcode `[mediavault_vault]`: confirmar ausencia total del botón/panel admin y respuestas `403 forbidden` en acciones admin `mv_*`. |

## 2026-02-20 - Folder Downloads UX Hotfix (Gráficas + Filtros + Tooltips + Unidades humanas)
Environment: PHP 8.5.1 + PHPUnit 11.5.x (unit tests) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Fix non-working controls in "Descargas por carpeta" (chart render on visible tab, filter actions, KPI help interactions), reduce KPI value visual weight, and show MB/GB/TB in initial server render and top folders.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed (37 tests, 94 assertions). |
| Integration smoke | Pass | `COMPOSER_PROCESS_TIMEOUT=900 composer integration` passed (incluye endpoint de transfer report y export CSV). |
| Manual dashboard smoke | Not run | Pendiente validación visual/click real en browser del tab "Descargas por carpeta" (charts, filtros, `?` y glosario). |

## 2026-02-20 - Folder Downloads Hotfix (Restauración + Fallback + Ventanas)
Environment: PHP 8.5.1 + PHPUnit 11.5.x (unit tests) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Restore folder-demand visibility mid-month, add transfer `window` support (`month|prev_month|rolling_90d|lifetime`), hybrid fallback to legacy folder events, quality/source flags, and demand KPIs in dashboard + CSV.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed (37 tests), incluyendo `BehaviorServiceTest`, `DataLayerTransferTest` y nuevo `DataLayerFolderDemandTest`. |
| Integration smoke | Pass | `php scripts/integration/wpnow_endpoints_test.php` passed con validación de `jpsm_get_transfer_report` en 4 ventanas y `jpsm_export_transfer_csv` con secciones nuevas (`meta_extended`, `demand_kpis`, `top_folders_window`). |
| Manual dashboard smoke | Not run | Pendiente QA visual del tab "Descargas por carpeta" (atajos de ventana, badge exacto/aproximado, top carpetas y KPI de demanda). |

## 2026-02-19 - Transfer Analytics (Bytes + Temporalidad + Cobertura)
Environment: PHP 8.5.1 + PHPUnit 11.5.x (unit tests) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Add transfer telemetry (`bytes_authorized`, `bytes_observed`), transfer report/export endpoints, MediaVault instrumentation for folder/file/preview flows, transfer dashboard widgets/charts, and authorized-only historical backfill.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed (incluye `BehaviorServiceTest` y nuevo `DataLayerTransferTest` para normalización de bytes). |
| Integration smoke | Pass | `composer integration` passed (incluye contratos `jpsm_get_transfer_report` y `jpsm_export_transfer_csv`). |
| Manual dashboard smoke | Not run | Pendiente QA visual de tab "Descargas por carpeta" para gráficas diaria/mensual/lifetime y filtros por segmento. |

## 2026-02-16 - Behavior Analytics (Keywords + Downloads + MoM/YoY)
Environment: PHP 8.5.1 + PHPUnit 11.5.x (unit tests) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Add behavior telemetry (`jpsm_track_behavior_event`), monthly report/export endpoints, MediaVault passive instrumentation (search and download granted/denied), and dashboard behavior tab filters.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed (includes `BehaviorServiceTest` coverage for keyword normalization/redaction, hash stability, device detection, and month parsing). |
| Integration smoke | Pass | `composer integration` passed (asserts valid/invalid `jpsm_track_behavior_event` contract and `jpsm_get_behavior_report` payload shape, plus existing MediaVault regression checks). |
| Manual dashboard smoke | Not run | Pending visual QA of behavior tab filters and CSV export in a browser session. |

## 2026-02-13 - Cloudflare Download Domain Activation (Private B2 + Worker Proxy)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Activate runtime usage of `jpsm_cloudflare_domain` for MediaVault download URLs while preserving existing entitlement rules and signed-session behavior.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed (includes new Cloudflare domain normalize/rewrite coverage in `ConfigValidationTest`). |
| Integration smoke | Pass | `composer integration` passed (asserts premium `mv_get_presigned_url` returns Cloudflare host and guest still receives `requires_premium`). |
| Manual UI smoke (Cloudflare Worker) | Not run | Pending real infra validation with workers.dev/custom domain in staging/production-like environment. |

## 2026-02-08 - Phase A0 Secret Hygiene (Security Gate + wp-now integration)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Remove hardcoded credentials/owner data, make secrets write-only in Settings UI, and validate that release artifacts do not leak secrets or dev files.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed. |
| Integration smoke | Pass | `composer integration` passed (endpoints + MediaVault login/search + index stats/sync). |
| Security gate (repo + ZIP) | Pass | `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build` passed. |

## 2026-02-08 - Phase A1 Configuration (Email Settings + B2 Connection Test)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Add central config validation, admin-only B2 connectivity test (`jpsm_test_b2_connection`), and configurable email behavior (Reply-To + admin notifications).

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed. |
| Integration smoke | Pass | `composer integration` passed (includes `jpsm_test_b2_connection` JSON contract check). |
| Security gate (repo + ZIP) | Pass | `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build` passed. |

## 2026-02-08 - Phase A2 Rebrand (MediaVault Manager)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Visible rebrand only (Option A): update plugin display branding, add preferred shortcode aliases, and rename the release ZIP artifact.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed. |
| Integration smoke | Pass | `composer integration` passed. |
| Security gate (repo + ZIP) | Pass | `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build` passed (ZIP: `dist/mediavault-manager.zip`). |

## 2026-02-08 - Phase A3 Hardening (Sessions, Endpoints, Presigned URLs)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Remove presigned URL leakage from browse/search payloads, gate download URL issuance by entitlement, add preview proxy for locked/demo, tighten admin auth, and disable secret-key-in-URL by default.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed. |
| Integration smoke | Pass | `composer integration` passed (asserts: `mv_ajax` + `mv_search_global` do not include `url`; guest cannot obtain `mv_get_presigned_url`). |
| Security gate (repo + ZIP) | Pass | `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build` passed. |

## 2026-02-08 - XSS Hardening Pass (Remaining UI Sinks)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Escape remaining DOM-injected strings in MediaVault + Manager JS, harden `MV_USER_DATA` inline JSON emission against `</script>` injection, and escape minor PHP outputs (tier badge + file extension).

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed. |
| Integration smoke | Pass | `composer integration` passed (updated nonce extraction to support `MV_USER_DATA` JSON). |
| Security gate (repo + ZIP) | Pass | `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build` passed (ZIP: `dist/mediavault-manager.zip`). |

## 2026-02-08 - Phase A4 Onboarding + Operations
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Add Setup wizard + health checks, avoid hardcoded page slugs for MediaVault routing (use page ID option or shortcode detection), and add buyer-facing install/troubleshooting docs.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed. |
| Integration smoke | Pass | `composer integration` passed. |
| Security gate (repo + ZIP) | Pass | `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build` passed (ZIP: `dist/mediavault-manager.zip`). |

## 2026-02-08 - Phase A5 Release Discipline
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Make release process repeatable and blocking (one-command gate), ensure deployment uses only `dist/mediavault-manager.zip`, and remove production cache-busting by `time()` for MediaVault assets (use versioned caching; debug-only busting).

| Check | Result | Notes |
|---|---|---|
| Release gate | Pass | `composer release:verify` passed (includes unit + integration + security gate + ZIP build). |

## 2026-02-08 - Hotfix: B2 Diagnostics + Sale Entitlements + MediaVault Login Redirect
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Improve B2 connectivity error signal (parse S3 XML errors + add Host header for stricter signature stability), make admin UI display server error messages even on non-2xx responses, persist user tier immediately after registering a sale, and keep MediaVault login redirects on the same host to avoid losing the session cookie on www/non-www mismatches.

| Check | Result | Notes |
|---|---|---|
| Unit tests | Pass | `composer test` passed. |
| Integration smoke | Pass | `composer integration` passed. |
| Security gate (repo + ZIP) | Pass | `bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh --build` passed (ZIP: `dist/mediavault-manager.zip`). |

## 2026-02-08 - Hotfix: B2 SignatureDoesNotMatch (ListObjects path-style)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Fix potential signature validation mismatch on some S3-compatible providers (Backblaze B2) by signing bucket-level ListObjects requests as `/<bucket>` (no trailing slash) instead of `/<bucket>/`, and bump plugin version for reliable rollout.

| Check | Result | Notes |
|---|---|---|
| Release gate | Pass | `composer release:verify` passed (includes unit + integration + security gate + ZIP build). |

## 2026-02-07 - Phase 6 Automated Smoke (PHPUnit + wp-now integration)
Environment: PHP 8.5.1 + PHPUnit 11.5.51 (unit tests) and `@wp-now/wp-now` on `http://localhost:8099` (integration).  
Scope: Phase 6 validation of unit tests + integration checks (real AJAX endpoints + MediaVault cookie session).

| Check | Result | Notes |
|---|---|---|
| Unit: Domain model + access rules | Pass | `composer test` passed (12 tests / 32 assertions). |
| Integration: Dashboard renders + nonces extracted | Pass | `jpsm_vars` extracted from dashboard HTML and nonces present. |
| Integration: Sale -> history -> tier | Pass | `jpsm_process_sale`, `jpsm_get_history` (v2 envelope), `jpsm_get_user_tier` verified. |
| Integration: Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON (delivery may fail locally). |
| Integration: MediaVault login cookie + search | Pass | Login POST returned `302`, cookie set, `mv_search_global` returned seeded result. |
| Integration: Index stats + sync responds | Pass | `jpsm_get_index_stats` returned expected payload; sync returned JSON (remote 403 acceptable locally). |

Note:
- PHP 8.5 reports `curl_close()` as deprecated; integration script suppresses `E_DEPRECATED` to keep output signal-focused.

## 2026-02-07 - MediaVault UX (Loader Visible + Back/Forward Panel)
Environment: PHP 8.5.1 + PHPUnit (unit) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Make the loading indicator visible even after scroll (sticky toolbar host) and provide in-app Back/Forward buttons (History API) to reduce accidental browser refresh risk.

| Check | Result | Notes |
|---|---|---|
| Unit: MV nav/loader guards + no reload | Pass | `composer test` passed (16 tests / 46 assertions). |
| Integration: core endpoints + MediaVault AJAX browse | Pass | `composer integration` passed; MV AJAX contracts remain stable. |

## 2026-02-07 - MediaVault Nav Order (Admin Drag & Drop + Home Screen)
Environment: PHP 8.5.1 + PHPUnit (unit) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Add admin-configured ordering for the sidebar folders and make the default MediaVault view a home screen showing those folders in the same order.

| Check | Result | Notes |
|---|---|---|
| Unit: nav ordering helpers | Pass | `composer test` passed (19 tests / 53 assertions). |
| Integration: core endpoints + MediaVault AJAX browse | Pass | `composer integration` passed. |

## 2026-02-07 - MediaVault Nav Panel UX (Inicio Visible + Shallow Filter Safety)
Environment: PHP 8.5.1 + PHPUnit (unit) and `@wp-now/wp-now` integration on `http://localhost:8099`.  
Scope: Make the in-app navigation panel more discoverable and ensure the "Inicio" control is always visible (SVG icons instead of emoji); keep folders visible on Inicio/nivel 1 even when Audio/Video filters are active.

| Check | Result | Notes |
|---|---|---|
| Unit: MV nav/loader guards + no reload | Pass | `composer test` passed. |
| Integration: core endpoints + MediaVault AJAX browse | Pass | `composer integration` passed. |

## 2026-02-07 - Phase 5 Runtime Smoke (wp-now + SQLite)
Environment: `@wp-now/wp-now` temporary instance on `http://localhost:8099` (WordPress 6.9.x + SQLite).  
Scope: Runtime validation after Phase 5 UI/logic separation (services + templates + extracted JS).

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | `home=200`, admin pages `200`, MediaVault `200`; no `Fatal error`, `Parse error`, `Warning`, `Uncaught`, or `Deprecated` markers in response HTML. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` and rendered `jpsm-mobile-app` (dashboard template + localized chart payload). |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` persisted sale row; `jpsm_get_history` returned `buyer-phase5@example.com` entry (local mail failure expected). |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON response (local `wp_mail` failure expected). |
| 5. Access control page loads and returns user tier | Pass | Access page returned `200`; `jpsm_get_user_tier` returned success with `tier=4` (`vip_pelis`) for smoke buyer. |
| 6. MediaVault renders and search returns results | Pass | `/?pagename=descargas` returned `200`; `mv_search_global` returned success with one seeded result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` returned JSON (`S3 Error 403` in local env) and `jpsm_get_index_stats` returned success (`table_exists=true`). |

Note:
- Local runtime lacks production SMTP and valid B2 credentials; email delivery and remote sync failures are expected while endpoint contracts and persistence behavior remain verifiable.

## 2026-02-07 - Phase 4 Runtime Smoke (wp-now + SQLite)
Environment: `@wp-now/wp-now` temporary instance on `http://localhost:8099` (WordPress 6.9.x + SQLite).  
Scope: Runtime validation after Phase 4 API consistency work (v2 response envelope + REST pilot route).

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | Home/admin/MediaVault pages returned `200`; no `Fatal error`, `Parse error`, `Warning`, `Uncaught`, or `Deprecated` markers in response HTML. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` and rendered `jpsm-mobile-app`. |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` persisted sale row and `jpsm_get_history` included `buyer-phase4@example.com` entry (local mail failure expected). |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON response (local `wp_mail` failure expected). |
| 5. Access control page loads and returns user tier | Pass | Access page returned `200`; `jpsm_get_user_tier` returned success with `tier=4` (`vip_pelis`) for smoke buyer. |
| 6. MediaVault renders and search returns results | Pass | `/?pagename=descargas` returned `200`; `mv_search_global` returned success with one seeded result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` returned JSON (`S3 Error 403` in local env) and `jpsm_get_index_stats` returned success (`table_exists=true`). |

Contract checks (Phase 4):
- `api_version=2` envelope validated for `jpsm_get_history` and `jpsm_get_user_tier` (payload keys: `ok`, `code`, `message`, `data`).
- REST pilot route validated via `?rest_route=/jpsm/v1/status&key=...` returning `{ ok: true, code: \"status_ok\" }`.

Note:
- Local runtime lacks production SMTP and valid B2 credentials; email delivery and remote sync failures are expected while endpoint contracts and persistence behavior remain verifiable.

## 2026-02-07 - Phase 3 Runtime Smoke (wp-now + SQLite)
Environment: `@wp-now/wp-now` temporary instance on `http://localhost:8099` (WordPress 6.9.x + SQLite).  
Scope: Runtime validation after Phase 3 domain-model consolidation.

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | Home/admin/MediaVault pages returned `200`; no `Fatal error`, `Parse error`, `Warning`, `Uncaught`, or `Deprecated` markers in response HTML. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` and rendered `jpsm-mobile-app`. |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` response reported local mail failure, but `jpsm_get_history` included `buyer-phase3@example.com` with the new sale entry. |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON response (local `wp_mail` failure expected in this environment). |
| 5. Access control page loads and returns user tier | Pass | Access page returned `200`; `jpsm_get_user_tier` returned success with `tier=2` (`vip_basic`) for smoke buyer. |
| 6. MediaVault renders and search returns results | Pass | `/?pagename=descargas` returned `200`; `mv_search_global` returned success with one seeded result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` returned JSON (`S3 Error 403` in local env) and `jpsm_get_index_stats` returned success (`table_exists=true`). |

Runtime evidence summary:
- `jpsm_process_sale`: persists sale row even when local SMTP delivery fails.
- `jpsm_get_history`: confirms persisted entry for smoke buyer.
- `jpsm_get_user_tier`: resolves tier from current purchase history/domain-tier mapping.
- `mv_search_global`: success with seeded indexed row after signed user-session setup.
- `jpsm_sync_mediavault_index`: endpoint contract validated; credential-dependent sync failure captured.

Note:
- Local runtime lacks production SMTP and valid B2 credentials; email delivery and remote sync failures are expected while endpoint contracts and persistence behavior remain verifiable.

## 2026-02-07 - Phase 2 Runtime Smoke (wp-now + SQLite)
Environment: `@wp-now/wp-now` temporary instance on `http://localhost:8099` (WordPress 6.9.x + SQLite).  
Scope: Runtime validation after Phase 2 data-layer migration.

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | Home/admin/MediaVault HTML responses checked for `Fatal error`, `Warning`, `Parse error`, `Uncaught`, and `Deprecated` markers; none found. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` and rendered `jpsm-mobile-app`. |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` persisted sale row and `jpsm_get_history` returned the new `buyer-phase2@example.com` entry (email transport failed locally, but persistence path passed). |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON response (local `wp_mail` delivery failed as expected in this environment). |
| 5. Access control page loads and returns user tier | Pass | `jpsm_get_user_tier` returned success with `tier=1` and `is_customer=true` for smoke buyer. |
| 6. MediaVault renders and search returns results | Pass | `/?pagename=descargas` returned `200`; `mv_search_global` returned success with one seeded result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` responded JSON (`S3 Error 403` in local env) and `jpsm_get_index_stats` returned success. |

Runtime evidence summary:
- `jpsm_process_sale`: writes into `{prefix}jpsm_sales` and mirrored legacy option.
- `jpsm_get_history`: returns persisted sale entries from data layer.
- `jpsm_get_user_tier`: resolves tier from Phase 2 tables.
- `mv_search_global`: successful query with indexed test row.
- `jpsm_sync_mediavault_index`: endpoint contract verified (credential-dependent sync result).

Note:
- Local runtime has no production SMTP and no valid B2 credentials; email send and real S3 sync are expected to fail there while endpoint contract and persistence behavior still remain verifiable.

## 2026-02-06 - Phase 1 Runtime Smoke (Temporary WP + SQLite)
Environment: WordPress 6.9.x temporary instance on `http://127.0.0.1:8099` with SQLite drop-in.
Scope: Runtime execution of the 7-item smoke checklist after Phase 1 hardening.

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Pass | `home=200`, dashboard `200`, no plugin warnings in runtime request log. |
| 2. Admin dashboard page renders | Pass | `/wp-admin/admin.php?page=jetpack-store-manager` returned `200` with authenticated admin session. |
| 3. Register sale flow works and log entry appears | Pass | `jpsm_process_sale` returned success and `jpsm_get_history` contains the new `buyer-smoke@example.com` entry. |
| 4. Resend email endpoint responds | Pass | `jpsm_resend_email` returned JSON success (`Correo reenviado`). |
| 5. Access control page loads and returns user tier | Pass | Access page returned `200`; `jpsm_get_user_tier` returned success with `tier=1` for smoke user. |
| 6. MediaVault renders and search returns results | Pass | MediaVault page returned `200`; `mv_search_global` returned success with one indexed result (`smoke-track.mp3`). |
| 7. Index sync endpoint responds (admin only) | Pass | `jpsm_sync_mediavault_index` responded with JSON (admin-only path verified); `jpsm_get_index_stats` responded successfully. |

Runtime evidence summary:
- `jpsm_process_sale`: success + persisted entry.
- `jpsm_resend_email`: success response.
- `jpsm_get_user_tier`: success response with expected tier payload.
- `mv_search_global`: success response with one result from seeded local index row.
- `jpsm_sync_mediavault_index`: JSON response observed (`S3 Error 403` in this local env).
- `jpsm_get_index_stats`: success response (`table_exists=true`).

Note:
- In this temporary environment, S3 sync returns `403` due local credential context. This does not block endpoint contract validation, but real B2 credential validation should still be confirmed in staging/production-like infra.

## 2026-02-07 - Admin UI Fix (Manage Users Search)
Environment: Code change only (no runtime WordPress instance attached in this session).
Scope: Ensure the "Gestionar Usuarios" search does not get stuck on `Buscando...` when the AJAX call fails or returns invalid JSON.

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Not run | Requires runtime WP. |
| 2. Admin dashboard page renders | Not run | Requires runtime WP. |
| 3. Register sale flow works and log entry appears | Not run | Requires runtime WP. |
| 4. Resend email endpoint responds | Not run | Requires runtime WP. |
| 5. Access control page loads and returns user tier | Not run | Requires runtime WP. |
| 6. MediaVault renders and search returns results | Not run | Requires runtime WP. |
| 7. Index sync endpoint responds (admin only) | Not run | Requires runtime WP. |

Local evidence:
- `composer test`: Pass.
- Admin JS change: access-control user search now handles `.fail()` and shows an error instead of hanging.

## 2026-02-07 - MediaVault Download Buttons (Folder Permission Normalization)
Environment: PHP 8.5.1 + PHPUnit (unit) and `@wp-now/wp-now` integration on `http://localhost:8099`.
Scope: Fix cases where download buttons/locked state were incorrect for paid users due to folder-permission values being stored/treated as a single-tier list (instead of "minimum tier").

| Check | Result | Notes |
|---|---|---|
| Unit: access folder permissions normalization | Pass | Added unit coverage for singleton-array expansion + trailing-slash key matching. |
| Integration: core endpoints + MediaVault search | Pass | `composer integration` passed (cookie + `mv_search_global` still OK). |

Notes:
- Demo download blocking remains intact (demo users still cannot download).

## 2026-02-07 - MediaVault Funnel Visibility (Browse All, Lock Downloads)
Environment: PHP 8.5.1 + PHPUnit (unit) and `@wp-now/wp-now` integration on `http://localhost:8099`.
Scope: All tiers can browse + preview all content (conversion funnel). Only downloads are gated by tier/folder locks.

| Check | Result | Notes |
|---|---|---|
| Unit: core suite | Pass | `composer test` passed. |
| Integration: core endpoints + MediaVault search | Pass | `composer integration` passed (cookie + `mv_search_global` still OK). |

Notes:
- Sidebar navigation no longer hides folders based on download tier; locked items still show upgrade CTA.

## 2026-02-07 - MediaVault Sidebar Active Highlight (Always On)
Environment: PHP 8.5.1 + PHPUnit (unit) and `@wp-now/wp-now` integration on `http://localhost:8099`.
Scope: Ensure the left navigation always highlights the current folder, including when navigating via AJAX (no full page reload) and when some links omit a trailing `/`.

| Check | Result | Notes |
|---|---|---|
| Unit: core suite | Pass | `composer test` passed. |
| Integration: core endpoints + MediaVault search | Pass | `composer integration` passed. |

## 2026-02-07 - MediaVault No Reload (Search Clear Soft Restore)
Environment: PHP 8.5.1 + PHPUnit (unit) and `@wp-now/wp-now` integration on `http://localhost:8099`.
Scope: Clearing the search must NOT trigger a full page reload (reload cancels in-progress downloads). Use AJAX-only soft restore.

| Check | Result | Notes |
|---|---|---|
| Unit: core suite | Pass | `composer test` passed. |
| Integration: core endpoints + MediaVault search | Pass | `composer integration` passed. |
| Guard: no `location.reload` in MediaVault module | Pass | Search clear exits via `exitSearchMode()` + `loadFolder()` (AJAX) and `safeReload()` is soft refresh only. |

Notes:
- UX perf: exit-from-search now restores a DOM snapshot instantly (no network) and folder navigation uses an in-memory cache + clear loading indicator to reduce the "se trabo" perception on slow S3 listings.
- Perf core: folder browsing now prefers the local index for listing (avoids remote S3 pagination in UI) and `mv_ajax=1` returns clean JSON (no fullscreen wrapper HTML), preventing JSON parse stalls.

## 2026-02-06 - Initial Attempt (No Runtime WP)
Environment: Local repository only (no running WordPress instance attached at the time).

| Check | Result | Notes |
|---|---|---|
| 1. Plugin loads without PHP warnings or fatal errors | Blocked | Runtime WP bootstrap not available in this workspace. |
| 2. Admin dashboard page renders | Blocked | Requires active WP admin session/UI. |
| 3. Register sale flow works and log entry appears | Blocked | Requires runtime AJAX + option persistence in WP. |
| 4. Resend email endpoint responds | Blocked | Requires runtime AJAX and mail subsystem. |
| 5. Access control page loads and returns user tier | Blocked | Requires runtime AJAX + authenticated admin UI. |
| 6. MediaVault renders and search returns results | Blocked | Requires runtime shortcode page + B2 config and index data. |
| 7. Index sync endpoint responds (admin only) | Blocked | Requires runtime WP + index table + credentials. |
