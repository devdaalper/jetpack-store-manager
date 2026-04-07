# Sync v2 atomica + Search v2 con guardas de instrumentacion

- Date: 2026-02-28 17:42

## Context

Se implemento doble buffer primary/shadow para indice MediaVault, enriquecimiento de esquema y pipeline de sync con estado de calidad; luego busqueda v2 (name/folder/path normalizados, score y fuzzy leve), UI de salud de indice y contrato de endpoints/instrumentacion preservado y validado con integration tests.

## What worked

Swap de tabla activa via opcion y escribir siempre en tabla inactiva evito degradacion por sync parcial. Mantener eventos search_executed/search_zero_results y query_norm intactos permitio comparabilidad pre/post. Pruebas de contrato en integracion detectan roturas de shape rapidamente.

## What failed

El repo estaba sucio y con cambios paralelos, por lo que no fue viable aislar por git status sin revisar foco por archivos clave. Al inicio la prueba de integracion esperaba forma legacy de mv_search_global y hubo que adaptar unwrap para api_version=2.

## Next time

Agregar smoke automatizado que corra ambos modos legacy y v2 en cada PR y snapshot KPI operativo documentado antes/despues del rollout. Considerar job de validacion de colisiones/consistencia de path_hash en lotes grandes.
