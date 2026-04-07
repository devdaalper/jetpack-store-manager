# Factibilidad BPM en MediaVault (catalogo y filtros)

- Date: 2026-03-02 18:27

## Context

Se evaluo si el plugin puede incluir BPM por cancion y permitir clasificacion/filtrado por BPM en MediaVault. Se revisaron index schema, pipeline de sync B2, endpoint mv_search_global y filtros de frontend.

## What worked

Detectar puntos de extension en la tabla de indice, endpoint de busqueda y pills de filtro. Confirmar con codigo que hoy solo se persisten path/name/size/extension/media_kind y que el listado S3 paginado no trae BPM.

## What failed

No existe fuente actual de BPM en el flujo de sync; asumir deteccion automatica sin metadata externa seria riesgoso y costoso en WordPress/PHP.

## Next time

Definir desde inicio la fuente de BPM (metadata en objeto, sidecar JSON o carga manual) y modelar bpm como entero indexado con backfill asinc para no afectar tiempos de sync/busqueda.
