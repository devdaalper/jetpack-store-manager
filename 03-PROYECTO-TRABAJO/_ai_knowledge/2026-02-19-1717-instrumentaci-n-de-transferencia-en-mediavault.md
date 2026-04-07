# Instrumentación de transferencia en MediaVault

- Date: 2026-02-19 17:17

## Context

Se implementó analítica de transferencia end-to-end: schema bytes authorized/observed, reporte+CSV, instrumentación folder/file/preview, dashboard con series 90d/mensual/lifetime y pruebas unit/integración.

## What worked

Mantener fail-open en tracking evitó riesgo UX; reutilizar behavior_daily permitió KPIs y series sin recalcular raw en cada request; tests de integración validaron endpoints nuevos y flujos críticos.

## What failed

El repo ya venía con worktree muy sucio y archivos untracked, lo que aumenta riesgo de mezclar cambios y dificulta revisión aislada.

## Next time

Antes del rollout productivo, aislar cambios en rama limpia y exigir checklist de cobertura (observed vs authorized) con validación mensual contra proveedor de egress.
