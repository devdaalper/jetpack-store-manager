---
description: "Instrucciones para el manejo del Motor de Descargas estable (MediaVault)."
---

# PROTEGIDO: Motor de Descargas (MediaVault)

**Estado**: 🟢 ESTABLE / CONGELADO
**Última Verificación**: 2026-02-01
**Propietario**: Usuario (daalper)

## 🚫 RESTRICCIONES
El "Motor de Descargas" (implementado a través del módulo **MediaVault**) se considera completo en cuanto a funcionalidades (feature-complete) y estable.
**NO MODIFIQUE** los siguientes archivos sin una solicitud explícita del usuario y un plan detallado.

### 🔒 Ámbito Protegido (Archivos y Directorios)
1.  **Módulo MediaVault Completo**: 
    - `includes/modules/mediavault/` (Recursivo)
2.  **Clases de Lógica Clave**:
    - `includes/modules/mediavault/class-s3-client.php` (Integración S3/Backblaze)
    - `includes/modules/mediavault/class-traffic-manager.php` (Límites de Descarga/Tráfico)
    - `includes/modules/mediavault/class-index-manager.php` (Indexación/Sincronización de Archivos)
    - `includes/modules/mediavault/template-vault.php` (Renderizador de UI)
    - `includes/modules/mediavault/loader.php` (Punto de Entrada del Módulo)
3.  **Activos Frontend (Assets)**:
    - `includes/modules/mediavault/assets/js/mediavault-client.js` (CRÍTICO: Maneja las interacciones de UI, eventos de reproducción y descarga)

### ⚠️ Código Legado (No Restaurar)
- `includes/modules/downloader/` es un módulo antiguo. Actualmente está **inactivo** (comentado en `jetpack-store-manager.php`). No reactivar ni editar a menos que se esté migrando lógica específica a MediaVault.

## 📝 Reglas de Operación para Agentes
1.  **Solo Lectura por Defecto**: Asuma que estos archivos son de solo lectura.
2.  **Verificación de Regresiones**: Si debe modificar código *relacionado* (ej. `class-jpsm-admin.php`), asegúrese de no romper el shortcode `[jpsm_media_vault]` ni el filtro `template_include` en `mediavault/loader.php`.
3.  **Protocolo de Modificación**:
    - Si se solicita un cambio aquí, primero **cree un respaldo** o asegúrese de que git esté limpio.
    - **Aísle** el cambio. No mezcle refactorizaciones de este módulo con otras tareas.
    - **Verifique** que la generación de "URLs firmadas de S3" (S3 Signed URLs) no se vea afectada.
4.  **No Recargas Programáticas**:
    - **Prohibido** usar `window.location.reload()` / `location.reload()` para "resetear" UI o resolver estados inconsistentes dentro de MediaVault.
    - Motivo: una recarga cancela descargas activas y degrada la experiencia.
    - Alternativa: use refresco suave (AJAX) vía `MediaVaultApp.loadFolder()` / `MediaVaultApp.softRefresh()` y cancele requests en vuelo para evitar condiciones de carrera.
5.  **Browse Rápido (Sin S3 Listing)**:
    - La navegación/listado de carpetas para **UI** debe preferir el índice local (`JPSM_Index_Manager`) para evitar demoras por paginación remota.
    - S3/Backblaze queda reservado para acciones que lo requieren: URLs firmadas (preview/descarga) y descargas masivas (endpoint de descarga de carpeta).
6.  **AJAX Limpio**:
    - Las respuestas `mv_ajax=1` deben ser JSON limpio (sin wrapper HTML) para que `fetch().json()` no falle y no se perciba como "se trabó".
7.  **Loader Siempre Visible (Scroll-Safe)**:
    - El indicador de carga debe ser visible aunque el usuario haya hecho scroll hacia abajo.
    - Host recomendado: `#mv-toolbar-status` (en `.jpsm-mv-toolbar` sticky) y loader inyectado por JS `#mv-grid-loading`.
8.  **Navegación Interna (Back/Forward) Sin Salir de MediaVault**:
    - Proveer botones internos `#mv-nav-back` / `#mv-nav-forward` para navegar carpetas sin depender del navegador.
    - Proveer botón `#mv-nav-home` para volver a la "Pantalla de inicio" (junction view) sin recargar.
    - Iconografía: usar SVG (con `stroke="currentColor"`) para acciones del sistema (Back/Forward/Home); evitar emojis en estos botones (pueden renderizar en blanco según la fuente).
    - Implementación: History API con estados `{ mv: true, navId, folder }` + handler `popstate` que llama `loadFolder()` (AJAX).
    - Deshabilitar `Back` cuando no exista historial interno para evitar salir de la página (y el riesgo de recarga accidental).
9.  **Orden Admin de Carpetas (Sidebar + Pantalla de Inicio)**:
    - El orden de las carpetas del nivel "junction" debe ser definible por admin (drag & drop en WP admin).
    - Fuente de verdad: option `jpsm_mediavault_sidebar_order` (paths normalizados sin leading slash y con trailing `/`).
    - El mismo orden debe aplicarse en:
      - Barra lateral izquierda (lista `.mv-nav-item`).
      - Pantalla de inicio (vista default cuando no hay `folder` en URL).

## 🧪 Verificación Rápida
Si sospecha una regresión:
1.  Vaya a la página de "Descargas" (o página con el shortcode `[jpsm_media_vault]`).
2.  Verifique que la plantilla personalizada "Full Screen" se cargue (omitiendo el encabezado/pie de página del tema).
3.  Intente "Reproducir" o "Descargar" un archivo. Debería generar un enlace válido (no error 403 o 404).
