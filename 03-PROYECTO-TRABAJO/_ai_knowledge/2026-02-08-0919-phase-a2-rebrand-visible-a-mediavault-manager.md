# Phase A2: Rebrand visible a MediaVault Manager

- Date: 2026-02-08 09:19

## Context

Se completo la Phase A2 (Option A): rebrand visible del plugin (headers, menus, headings y copy), se agregaron shortcodes preferidos ([mediavault_manager], [mediavault_vault]) manteniendo los antiguos como alias, y se renombro el ZIP de release a dist/mediavault-manager.zip sin cambiar el slug/carpeta interna (jetpack-store-manager).

## What worked

Mantener el slug interno estable mientras se cambia el branding visible; asegurar que los hooks que detectan shortcodes (enqueue_frontend_assets y template_include/template_redirect) soporten ambos tags; actualizar build+deployment docs y validar con composer test + composer integration + security gate (repo + ZIP).

## What failed

Ninguna; el unico incidente fue un patch que duplico ZIP_PATH y se corrigio de inmediato.

## Next time

En Option B, planear el rename interno (slug/keys) como migracion idempotente con rollback; en Option A, siempre documentar que el ZIP name puede diferir del folder interno para evitar confusion.
