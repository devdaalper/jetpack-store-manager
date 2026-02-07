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

## 🧪 Verificación Rápida
Si sospecha una regresión:
1.  Vaya a la página de "Descargas" (o página con el shortcode `[jpsm_media_vault]`).
2.  Verifique que la plantilla personalizada "Full Screen" se cargue (omitiendo el encabezado/pie de página del tema).
3.  Intente "Reproducir" o "Descargar" un archivo. Debería generar un enlace válido (no error 403 o 404).
