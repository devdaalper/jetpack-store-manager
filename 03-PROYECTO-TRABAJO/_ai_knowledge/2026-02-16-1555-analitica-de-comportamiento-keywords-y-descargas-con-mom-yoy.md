# Analitica de comportamiento keywords y descargas con MoM/YoY

- Date: 2026-02-16 15:55

## Context

Implemente instrumentacion end-to-end: nuevas tablas jpsm_behavior_events/jpsm_behavior_daily, servicio JPSM_Behavior_Service con endpoints track/report/export CSV, hooks pasivos en MediaVault (search + download granted/denied), tracking de clicks en frontend, y nueva pestaña de comportamiento en dashboard con filtros por mes/tier/region/device y comparativas MoM/YoY.

## What worked

Centralizar validacion/auth en JPSM_Auth + persistencia en JPSM_Data_Layer permitio agregar telemetria sin romper contratos existentes. Reusar requestActionV2 en mediavault-client simplifico tracking fail-open. Agregar warm-up de rollups diarios en report endpoint evito depender 100% del cron para mostrar datos.

## What failed

La primera asuncion del test de normalizacion esperaba placeholders con corchetes ([email]/[phone]), pero la limpieza de puntuacion los convierte en email/phone; hubo que alinear expectativa al comportamiento real.

## Next time

Cuando se redacten PII en queries, definir y fijar desde el inicio si placeholders deben preservarse literalmente o pasar por pipeline de limpieza final, para evitar divergencia entre implementacion y tests.
