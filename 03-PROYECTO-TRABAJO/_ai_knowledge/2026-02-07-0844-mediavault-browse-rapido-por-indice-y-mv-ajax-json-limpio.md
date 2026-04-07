# mediavault-browse-rapido-por-indice-y-mv_ajax-json-limpio

- Date: 2026-02-07 08:44

## Context

Mejorar agilidad percibida/real: la navegacion de carpetas a veces tardaba mucho. Causas: (1) listado de carpetas via S3 (paginacion remota) y (2) mv_ajax podia contaminarse con wrapper HTML del template fullscreen, rompiendo/alentando el parse JSON. Se cambio el browse UI a usar el indice local (DB) para listados y se corto el template fullscreen para mv_ajax, devolviendo JSON limpio.

## What worked

JPSM_Index_Manager::list_folder_structure() deriva carpetas/archivos inmediatos desde la tabla indice (portable SQLite/MySQL) y JPSM_MediaVault_UI::get_folder_structure() lo usa como fuente primaria con fallback a S3 cacheado. includes/modules/mediavault/template-fullscreen.php ahora evita imprimir wrapper HTML en mv_ajax=1, haciendo fetch().json() confiable. Se agrego check de integracion para mv_ajax y siguen pasando composer test + composer integration.

## What failed

Depender de listados remotos en navegacion frecuente introduce latencia variable y 'a veces' se siente congelado. En endpoints AJAX, mezclar HTML con JSON es una fuente silenciosa de fallas/percepcion de bloqueo.

## Next time

En UIs tipo explorador: separar 'browse metadata' (index local) de 'acciones' (URLs firmadas/descargas). Para cualquier modo AJAX, asegurar respuestas puras (JSON sin wrapper) y agregar un test de integracion que lo valide. Evitar caches gigantes accidentales (transients) y medir item_count correctamente.
