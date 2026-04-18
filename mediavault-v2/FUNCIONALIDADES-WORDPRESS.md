# MediaVault WordPress — Auditoría Exhaustiva (Visual + Código)

Fecha: 2026-04-17
Fuentes: Inspección visual en https://jetpackstore.net/descargas/ + análisis de código fuente completo

---

## 1. LAYOUT Y ESTRUCTURA

### 1.1 Estructura general
- Sidebar izquierdo fijo (260px) con navegación y datos de sesión
- Área principal al centro-derecha con contenido dinámico
- Footer sticky con CTA de upgrade
- Shortcodes: `[jpsm_media_vault]` y `[mediavault_vault]`
- Template override: usa template-fullscreen.php (sin chrome de WordPress)
- Cache prevention: DONOTCACHEPAGE, DONOTCACHEOBJECT, X-LiteSpeed-Cache-Control

### 1.2 Sidebar
- Título: "Mi Biblioteca" / "Media Vault"
- Navegación: carpetas raíz con icono 📁 azul (5 carpetas principales)
- Carpeta activa resaltada en naranja
- Hamburger toggle en mobile (posición fija top-left)
- Datos de sesión (abajo):
  - Email del usuario
  - Badge de tier (demo/basic/vip/full)
  - Contador de plays: `🎵 0/15 Restantes`
  - Link "Cerrar Sesión"

### 1.3 Banners informativos
- Banner de bienvenida (naranja claro): "¡Bienvenido! Tienes N reproducciones..." — dismissible con ✕
- Banner demo (naranja oscuro): "Modo Demo: Explora y Reproduce" + botón "Obtener Acceso Completo"

### 1.4 Footer sticky upgrade
- Icono 🎵 + "¿Te gusta lo que escuchas?" + "Obtén descargas ilimitadas"
- Botón naranja "Obtener acceso completo"
- Solo visible para usuarios demo

---

## 2. AUTENTICACIÓN Y SESIONES

### 2.1 Login
- Campo de email solamente (sin contraseña)
- POST con PRG pattern (redirect después de login)
- Cookie: `jdd_access_token` (signed)
- Parámetro `?invitado=1` para acceso guest (email efímero `invitado_UNIQID@example.invalid`)
- Guests nunca se registran como leads

### 2.2 Logout
- Link "Cerrar Sesión" en sidebar
- Limpia cookie de sesión

### 2.3 Auto-upgrade de tier
- Al hacer login, `process_login()` verifica historial de ventas
- Si hay ventas completadas → auto-asigna el tier más alto
- Si no → registra como lead (demo)

---

## 3. SISTEMA DE TIERS (6 niveles)

| Tier | Nombre | Capacidades |
|------|--------|-------------|
| 0 | DEMO | Browse + 15 previews/mes + sin descargas |
| 1 | BASIC | Acceso a carpetas básicas + descargas |
| 2 | VIP_BASIC | VIP + Básico |
| 3 | VIP_VIDEOS | VIP + Videos Musicales |
| 4 | VIP_PELIS | VIP + Películas |
| 5 | FULL | Todo — siempre tiene acceso a todo |

### 3.1 Permisos por carpeta
- Cada carpeta tiene un array de tiers permitidos: `{folder_path: [1, 3, 5]}`
- Herencia recursiva: subcarpetas heredan permisos del padre
- Tier 5 (FULL) bypasea todas las restricciones
- Carpetas sin permisos definidos = abiertas para todos

---

## 4. NAVEGACIÓN DE CARPETAS

### 4.1 Junction auto-drilling
- Funcionalidad OCULTA: auto-navega carpetas wrapper de un solo hijo (hasta 10 niveles)
- Encuentra el nivel "junction" natural (donde hay múltiples carpetas o contenido)
- Mejora la UX del home sin que el usuario note la estructura real de B2

### 4.2 Breadcrumbs
- Ruta: `PAQUETE MUSICAL VIP / JMP0017 MUJERES... / 01 JENNI RIVERA...`
- Cada nivel es clickeable (excepto el último)
- Relativos al junction folder, no a la raíz real de B2

### 4.3 Toolbar de navegación
- ← Back: navega al nivel anterior (history stack interno)
- → Forward: navega adelante en el historial
- 🏠 Inicio: regresa al junction folder
- Sin recarga de página (AJAX + history.pushState)
- AbortController para cancelar requests obsoletos
- Request sequence numbers para ignorar respuestas desordenadas

### 4.4 Sidebar ordering
- Nav_Order class permite al admin definir el orden de carpetas en sidebar
- Carpetas desconocidas se añaden al final, alfabéticamente

---

## 5. VISTAS Y FILTROS

### 5.1 Vista Grid
- Cards 200px min-width, responsive grid
- Carpetas: fondo oscuro + icono 📁 azul + nombre + "Carpeta" + badge
- Archivos audio: fondo oscuro + icono 🎵 + nombre + tamaño/fecha + botón Reproducir
- Archivos video: icono 🎬
- Hover: borde naranja

### 5.2 Vista Lista
- Filas horizontales
- Icono + nombre + metadata a la derecha
- Botón Reproducir (ancho completo) + badge "Solo Premium"
- Mobile: forzada automáticamente (wp_is_mobile → $force_list_view)

### 5.3 Filtros de tipo
- Pills: Todo (default) | 🎵 Audio | 🎬 Video
- Aplica tanto en browse como en búsqueda
- Extensiones audio: mp3, wav, flac, m4a, ogg, aac
- Extensiones video: mp4, mov, mkv, avi, webm, wmv

### 5.4 Filtro BPM
- Dropdown con rangos: "Todos", "90-94", "95-99", "100-109", etc.
- Rango validado server-side: 40-260
- (Eliminado del plan de v2 pero existe en WP)

### 5.5 Ordenamiento
- Dropdown "Nombre (A-Z)" — default
- Persistente vía localStorage

### 5.6 Toggle Grid/Lista
- Dos iconos: cuadrícula (grid) / líneas (lista)
- Persistente vía localStorage

---

## 6. BÚSQUEDA GLOBAL

### 6.1 Comportamiento
- Campo "Buscar..." en header, debounce 500ms
- Busca en TODO el catálogo (no solo carpeta actual)
- Búsqueda fuzzy con normalización de diacríticos
- Botón ✕ para limpiar búsqueda y volver a la vista de carpetas

### 6.2 Resultados agrupados
- **Sección 1: 📁 Carpetas (N)** — carpetas que coinciden
  - Nombre de carpeta + ruta completa en gris
  - Botón "Ver Contenido" + badge "Solo Premium"
- **Sección 2: 🎵 Archivos (N)** — archivos individuales
  - Nombre + tamaño + botones Reproducir/Descargar
- Límite: 100 archivos max
- Paginación: offset-based

### 6.3 Sugerencias de búsqueda
- Autocomplete desde historial de búsquedas (search_query_suggestions)
- Hasta 3 sugerencias alternativas si no hay resultados

### 6.4 Index state reporting
- Los resultados incluyen metadata del índice: `{index_state, last_sync}`
- Muestra si el índice está desactualizado

---

## 7. REPRODUCTOR DE AUDIO/VIDEO

### 7.1 Flujo
1. Clic en "▶ Reproducir"
2. Verifica plays restantes (demo) o genera URL directa (paid)
3. Demo: proxy stream con byte-range cap
4. Paid: presigned URL directa de B2

### 7.2 Player modal
- Audio: fondo oscuro + 🎵 emoji + controles nativos HTML5 + autoplay
- Video: `<video>` con controles nativos + autoplay
- Escape para cerrar
- Título del archivo en header
- Badge de plays restantes (demo)

### 7.3 Límite de 60 segundos (todos los tiers)
- Timer acumulativo (pausar detiene el conteo)
- A los 60s: pausa forzada + overlay "Límite de Vista Previa Alcanzado"
- No se puede reanudar después del límite

### 7.4 Modal de límite alcanzado (demo sin plays)
- Icono ⛔
- "Límite de Pruebas Alcanzado"
- "Has consumido tus 15 reproducciones de cortesía"
- Botón azul "🔒 Desbloquear Acceso Ahora"
- Botón "Cerrar"

### 7.5 Streaming proxy (demo)
- Token de vida corta (5 min transient)
- Bound a email + sesión
- Byte-range capped:
  - Audio: 5 MB
  - Video: 50 MB
  - Images: 2 MB
- Customizable vía filter: `jpsm_mediavault_preview_max_bytes`
- Implementación curl con streaming (no buffering) + fallback wp_remote_get

---

## 8. DESCARGAS

### 8.1 Descarga de archivo individual (paid)
- Botón "Descargar" genera presigned URL on-demand
- URL nunca pre-cargada en HTML (seguridad)
- Content-Disposition: attachment
- Cloudflare rewrite opcional

### 8.2 Descarga de carpeta completa (paid)
- File System Access API (Chrome/Edge only)
- `showDirectoryPicker()` → usuario selecciona carpeta local
- Descarga secuencial con:
  - Progress bar (bytes, %, velocidad, ETA)
  - Pause/Resume
  - Cancel
  - Error collection por archivo
  - Estructura de carpetas recreada localmente
- Panel de descargas flotante y minimizable
- Auto-remove después de 30s de completado

### 8.3 Verificación de acceso
- Cada presigned URL verifica:
  1. Sesión activa
  2. Tier > 0 (demo bloqueado)
  3. Acceso a la carpeta específica
- Analytics: `download_file_granted` / `download_file_denied`

### 8.4 Bandwidth limit
- 53.6 GB/día por usuario
- Tracked en tabla `wp_jpsm_traffic_log`

---

## 9. ANALYTICS DE COMPORTAMIENTO

### 9.1 Eventos trackeados
| Evento | Datos | Cuándo |
|--------|-------|--------|
| `search_executed` | query, result_count | Búsqueda exitosa |
| `search_zero_results` | query | Sin resultados |
| `download_file_click` | path | Click en descargar |
| `download_file_granted` | path, bytes | Descarga permitida |
| `download_file_denied` | path, reason | Descarga bloqueada |
| `download_folder_click` | path | Click en descargar carpeta |
| `download_folder_granted` | path, files_count, bytes | Carpeta permitida |
| `download_folder_denied` | path, reason | Carpeta bloqueada |
| `download_folder_completed` | path, files, bytes_observed | Descarga terminada |
| `preview_direct_opened` | path | Preview de paid user |
| `preview_proxy_streamed` | path, bytes_observed | Preview de demo user |

### 9.2 Dimensiones
- tier, region, device_class (mobile/desktop)
- session_id_hash, user_id_hash (PII hasheado)
- source_screen (siempre "mediavault_vault")

### 9.3 Reportes admin
- Reporte mensual con deltas MoM/YoY
- Reporte de transferencias (volumen de bytes)
- Export CSV
- Daily rollup vía cron

### 9.4 Mobile notice analytics
- Eventos: shown, dismissed, continue_anyway
- Rolling window de 90 días
- Almacenado en wp_option separada

---

## 10. FUNCIONALIDADES OCULTAS (solo en código)

### 10.1 Desktop App API
- Token auth para importación batch de BPM desde app de escritorio
- Endpoints: `desktop_issue_token_ajax`, `desktop_revoke_token_ajax`, `desktop_api_health_ajax`
- Token hasheado en wp_options con timestamps de creación y último uso

### 10.2 FFmpeg Auto-BPM Detection
- Análisis de audio server-side vía FFmpeg
- Batch de 25 archivos, sample de 256 KiB, 45 segundos de análisis
- Tabla de overrides persistente (sobrevive re-syncs)

### 10.3 CSV BPM Import
- Upload de CSV con columnas path + BPM
- Almacenado en tabla de overrides separada

### 10.4 Dual-Table Index (Atomic Swap)
- Dos tablas: primary + shadow
- Sync escribe en la inactiva, al terminar swap atómico
- Zero-downtime re-indexing

### 10.5 CORS Auto-Fix
- Parámetro `?fix_cors` trigger habilita CORS en el bucket

### 10.6 Folder Cover Images
- Metadata personalizada para imágenes de portada de carpetas
- Hook: `jpsm_mediavault_folder_covers`

### 10.7 Mobile Desktop Recommendation
- Modal sugiriendo instalar la app de escritorio (solo mobile)
- Detecta `window.jetpackDesktopApp`

### 10.8 WhatsApp Integration
- Links de upgrade redirigen a WhatsApp con mensaje pre-formateado

### 10.9 Search Suggestions (Autocomplete)
- `search_query_suggestions()` devuelve hasta 3 sugerencias

---

## 11. SEGURIDAD

- Nonce validation en todos los AJAX endpoints
- Path normalization: rechaza `../` y null bytes
- Presigned URLs nunca en payloads de browse/search
- Preview tokens con binding a email + sesión + TTL 5 min
- Output escaping: `mvEscapeHtml()` y `mvEscapeAttr()` (incluye backticks)
- Error sanitization: nunca expone respuestas raw de S3
- Demo play count: server-side only (no manipulable por cliente)
- Range header capping en proxy streaming

---

## 12. PERFORMANCE

- Folder cache en WordPress transients (5 min TTL, max 500 items)
- Client-side folder cache (in-memory Map)
- AbortController para cancelar requests obsoletos
- Request sequence numbers para ignorar respuestas desordenadas
- Debounce de búsqueda (500ms)
- Bulk insert en sync (no row-by-row)
- Path hash UNIQUE index en DB
- Streaming proxy con curl (no buffering) + flush()

---

## 13. RESPONSIVE / MOBILE

- Breakpoint: 768px
- Mobile: sidebar oculto + hamburger toggle
- Mobile: fuerza vista lista ($force_list_view = wp_is_mobile())
- Mobile: touch targets más grandes
- Mobile: recomendación de app de escritorio

---

## 14. CONFIGURACIÓN ADMIN

### Opciones en WordPress (wp_options)
- `jpsm_user_tiers` — asignación email→tier
- `jpsm_folder_permissions` — folder→[tiers]
- `jpsm_demo_play_counts` — conteo de plays por email
- `jpsm_leads_list` — leads registrados
- `jpsm_cloudflare_domain` — dominio CDN
- `jpsm_access_key` — clave admin
- `jpsm_mediavault_page_id` — ID de página WP
- Precios por paquete (MXN/USD)
- Templates de email por paquete (HTML)
- WhatsApp number
- Sidebar order
- FFmpeg path
- Desktop API token

---

## 15. GAPS: Lo que v2 necesita implementar

| Funcionalidad | Prioridad | Estado en v2 |
|---------------|-----------|-------------|
| Junction auto-drilling | Alta | ❌ No implementado |
| Vista lista toggle | Alta | ❌ Solo grid |
| Botones Back/Forward | Alta | ❌ Solo Home |
| Filtros Audio/Video en browse | Alta | ⚠️ Solo en search |
| Dropdown ordenamiento | Media | ❌ |
| Footer sticky upgrade | Alta | ❌ |
| Banners bienvenida/demo | Media | ❌ |
| Búsqueda con carpetas agrupadas | Alta | ⚠️ Solo archivos |
| Search suggestions/autocomplete | Baja | ❌ |
| Sidebar: badge tier + plays + logout | Alta | ⚠️ Parcial |
| Streaming proxy (demo) | Alta | ❌ Usa presigned URLs cortas |
| Folder cover images | Baja | ❌ |
| Mobile hamburger sidebar | Alta | ❌ |
| Mobile force list view | Alta | ❌ |
| WhatsApp upgrade links | Media | ✅ Componente existe |
| Desktop app recommendation | Baja | ❌ |
| Download progress panel | Media | ✅ Hook + componente existen |
| CORS auto-fix | Baja | ❌ |
| Guest access (?invitado=1) | Baja | ❌ |
| Cloudflare URL rewriting | Media | ✅ En código |
| Bandwidth daily limit | Media | ❌ No enforced |
| Folder download (File System API) | Alta | ✅ Hook existe |
