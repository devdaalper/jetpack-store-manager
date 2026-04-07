# mediavault-sin-recargas-al-limpiar-busqueda

- Date: 2026-02-06 23:48

## Context

MediaVault: al limpiar la barra de busqueda global se disparaba una recarga (safeReload -> window.location.reload) que cancelaba descargas. Se reemplazo por salida de modo busqueda via AJAX y refresco suave, se agrego cancelacion de requests en vuelo y un guard test para evitar reintroducir location.reload. Tambien se elimino window.location.reload del login template (PRG con redirect + fallback location.replace).

## What worked

exitSearchMode() restaura la vista previa llamando loadFolder() con mv_ajax=1 sin recargar. safeReload() ahora llama MediaVault.softRefresh() (AJAX). AbortController + request sequence evita condiciones de carrera (respuestas viejas) y mejora agilidad. Se agrego test unitario que garantiza que el modulo MediaVault no contiene location.reload. composer test + composer integration pasaron.

## What failed

Usar window.location.reload como 'reset' de UI oculto es fragil: borra estado, rompe procesos largos (descargas) y enmascara falta de manejo de estado (modo busqueda, force-list-view) y de cancelacion de requests.

## Next time

En cualquier proyecto con procesos largos en pagina (descargas, uploads, reproductores), prohibir recargas programaticas como mecanismo de recuperacion. Modelar estado explicito (sesion de busqueda), usar refresco suave (AJAX), cancelar requests en vuelo, y agregar guardas (tests + estandares) para que la regla no se rompa con cambios futuros.
