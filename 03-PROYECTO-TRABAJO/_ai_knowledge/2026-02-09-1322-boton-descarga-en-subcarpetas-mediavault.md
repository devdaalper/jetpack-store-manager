# Boton descarga en subcarpetas (MediaVault)

- Date: 2026-02-09 13:22

## Context

Se pidio mostrar boton de descarga para subcarpetas bajo las secciones del sidebar, respetando permisos y sin romper funnel (browse abierto, download gated).

## What worked

Cambiar solo la condicion de render en renderGrid(): en depth>=1 (bajo sidebar) se renderiza mv-folder-download-btn para cada folder item, condicionado a f.can_download del backend. Backend ya hace enforcement en mv_list_folder. composer test/integration/release:verify verdes.

## What failed

N/A (cambio minimo y aislado).

## Next time

Si se requiere consistencia con busqueda global, extender performGlobalSearch para usar can_download por carpeta (hoy solo usa userTier>0). Mantener cambios en mediavault-client.js aislados y siempre correr release:verify.
