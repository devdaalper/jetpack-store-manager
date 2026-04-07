# Desktop BPM GUI + API token/batch rollback y robustez admin-ajax

- Date: 2026-03-04 00:11

## Context

Se completó integración desktop separada en apps/bpm-desktop con GUI por wizard estricto, SQLite/Keychain, pipeline BPM (TBPM metadata -> reglas path -> acústico), publicación CSV/API, rollback desde GUI y endpoints/token/batches/rollback en plugin con auditoría de lotes.

## What worked

Alinear admin-ajax con bearer token usando action en querystring y body JSON funcionó bien; agregar recuperación de filas processing tras cierre inesperado evitó atascos; pruebas rápidas (php -l, py_compile, node --check, phpunit IndexManager) confirmaron estabilidad base.

## What failed

No se ejecutó E2E completo contra wp-now + servidor real con token y dataset grande en este cierre; ffmpeg embebido .app y validación de performance masiva quedan pendientes operativos.

## Next time

Agregar suite de integración dedicada para endpoints desktop (health/import/rollback), prueba de reanudación forzada y benchmark por perfil; empaquetar ffmpeg dentro de build .app para cero pasos manuales.
