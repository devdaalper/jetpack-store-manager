# MediaVault: separar visibilidad (funnel) vs permisos de descarga

- Date: 2026-02-06 22:56

## Context

Requisito de conversion: todos los usuarios (tier 0-5) deben poder ver y previsualizar TODO el contenido para incentivar upgrades. El bug fue que el sidebar y la busqueda global estaban filtrando carpetas/archivos segun permisos de descarga, rompiendo discovery.

## What worked

1) Quitar el filtro de sidebar por can_access_folder. 2) Quitar el filtro por tier en mv_search_global. 3) Reaplicar applyFolderLocks despues de renderizar resultados de busqueda para que los locks/CTAs se apliquen igual. 4) Documentar la regla en docs/standards/permission-lock.md y actualizar docs/ENDPOINTS.md.

## What failed

Mezclar dos conceptos: permisos de descarga (restriccion) y visibilidad/navegacion (funnel). Cuando se usa el mismo check para ambos, se oculta contenido que deberia servir como teaser y se reduce conversion.

## Next time

Tratar la visibilidad como una capa distinta: browse/search/listado nunca se filtra por tier; solo las acciones de descarga se gatean. Documentar la regla y agregar una verificacion de smoke/manual: 'un usuario de tier bajo ve carpetas/archivos bloqueados y recibe CTA de upgrade, pero no puede descargar'.
