# Phase A5: release discipline (one-command gate + versioned assets)

- Date: 2026-02-08 11:56

## Context

Implementacion de A5: proceso de release repetible y bloqueante. Se agrego composer script release:verify (unit + integration + security gate + build ZIP), se actualizaron RELEASE_CHECKLIST/DEPLOYMENT para desplegar solo dist/mediavault-manager.zip, y se removio cache-busting por time() en MediaVault assets (ahora usa JPSM_VERSION, con bust solo en WP_DEBUG).

## What worked

1) Unificar compuertas con composer release:verify evita omisiones. 2) Mover instrucciones a docs para que el despliegue sea siempre desde dist ZIP. 3) Usar versionado estable de assets (JPSM_VERSION) mejora caching en produccion sin perder DX (WP_DEBUG agrega bust).

## What failed

No hubo fallas; el unico riesgo era tocar mediavault/loader.php (zona protegida), mitigado con cambio minimo + gates verdes.

## Next time

Agregar una regla al security gate para alertar si se reintroduce wp_enqueue_script(..., time()) en runtime. Considerar un workflow de CI ligero (unit + scan_tree) para detectar fugas antes de build.
