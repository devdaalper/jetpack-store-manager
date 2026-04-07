# Hotfix seguridad: desactivar panel admin en mediavault_vault

- Date: 2026-02-23 00:32

## Context

Se bloqueó exposición del Panel Admin en frontend MediaVault: no se renderiza en [mediavault_vault], acciones mv_* admin devuelven 403 por defecto y flags admin quedan deshabilitados en MV_USER_DATA.

## What worked

Aplicar deny-list temprana por action en handle_ajax y gate central por feature flag evitó depender de ocultamiento visual. PHPUnit y security_gate dieron señal rápida de no regresión de contrato base.

## What failed

composer integration no pudo completar porque wp-now no levantó en localhost:8099, así que faltó evidencia de integración end-to-end en este entorno.

## Next time

Mantener paneles admin fuera de superficies customer-facing por defecto (opt-in explícito), y agregar test de regresión que afirme 403 en mv_get_leads/mv_get_user_meta desde frontend.
