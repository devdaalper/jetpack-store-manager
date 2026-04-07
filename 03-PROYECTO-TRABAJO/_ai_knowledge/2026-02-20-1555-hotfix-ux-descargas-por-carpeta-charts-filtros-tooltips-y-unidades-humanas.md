# Hotfix UX descargas por carpeta: charts, filtros, tooltips y unidades humanas

- Date: 2026-02-20 15:55

## Context

Se corrigió el tab de descargas por carpeta para resolver no-render de gráficas, controles de filtro y ayudas KPI; además se pasó render inicial a unidades humanas MB/GB/TB y se redujo el peso visual de cifras KPI.

## What worked

Re-render diferido de charts al abrir la pestaña evitó canvas en estado oculto; remover optional chaining mejoró compatibilidad JS; versionado por filemtime en assets evitó caché vieja que dejaba UI sin listeners nuevos.

## What failed

Con valores iniciales en bytes crudos (B) la lectura era pobre y podía confundir aunque JS luego reformateara; depender solo de JS para legibilidad no era robusto.

## Next time

Mantener fallback legible en template PHP (formato humano) y asegurar render de gráficos solo cuando el contenedor sea visible; usar versionado por filemtime para assets críticos de dashboard.
