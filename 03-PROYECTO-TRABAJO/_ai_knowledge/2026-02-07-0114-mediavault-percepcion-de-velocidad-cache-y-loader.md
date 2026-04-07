# mediavault-percepcion-de-velocidad-cache-y-loader

- Date: 2026-02-07 01:14

## Context

Usuarios percibian que MediaVault 'se trababa' cuando la navegacion tarda (S3 list_objects puede paginar y demorar). Se mejoro el rendimiento percibido y real en sesiones: cache en memoria para payloads de carpetas, loader con spinner + mensaje si tarda, y salida de busqueda restaura snapshot del DOM sin red.

## What worked

Cache en memoria (TTL corto + LRU) evita repetir listados lentos al ir/volver o limpiar busqueda. Loader claro reduce incertidumbre en cargas lentas sin recargar la pagina (descargas siguen vivas). Snapshot DOM para exitSearchMode hace el 'clear search' instantaneo.

## What failed

Solo bajar opacidad/pointerEvents sin indicador genera sensacion de bloqueo. Repetir fetches identicos en navegacion (back/forward) desperdicia tiempo cuando el backend depende de listings remotos paginados.

## Next time

Para UIs que dependen de IO remoto: implementar stale-while-revalidate o cache de sesion (TTL corto), abortar requests en vuelo, y siempre mostrar un loader explicito con mensaje de 'esto puede tardar' a partir de un umbral. Si la fuente es remota y paginada, considerar paginacion/infinite-scroll o listar desde un indice local para evitar esperas largas.
