# MediaVault: orden admin de carpetas + pantalla de inicio en frontend

- Date: 2026-02-07 09:42

## Context

Se requeria que el admin pudiera definir (drag & drop) el orden de las carpetas del nivel junction (las mismas que aparecen en la barra lateral izquierda) y que ese orden tambien se reflejara en una pantalla de inicio (default) en el panel principal del frontend para mejorar navegacion movil. Ademas, la pantalla de inicio debe ser un estado navegable via Back/Forward interno (History API) sin recargas.

## What worked

Guardar el orden como option simple (`jpsm_mediavault_sidebar_order`) y aplicar el orden en dos lugares: sidebar (`$sidebar_folders`) y home screen (cuando `folder` viene vacío). Agregar endpoints admin (`jpsm_mv_get_sidebar_folders`, `jpsm_mv_save_sidebar_order`, `jpsm_mv_reset_sidebar_order`) con nonce + auth y UI drag&drop con `jquery-ui-sortable`. En frontend, eliminar el auto-redirect al primer folder y devolver el listado del junction como 'Inicio'. Mantener `current_depth` junction-relativo para que el cliente renderice botones consistentes (Ver vs Descargar).

## What failed

Si el frontend auto-redirige al primer folder cuando falta `folder`, no existe un 'home' al que regresar y el orden de sidebar no tiene un equivalente en el contenido principal. En tests/stubs, `update_option` sin signature compatible con WP (3er param) puede generar warnings si no se ajusta el bootstrap.

## Next time

Para navegadores tipo explorador: separar 'vista inicial' (home/junction) de 'carpeta actual', y tratar configuraciones admin (orden) como fuente de verdad unica aplicada a todos los puntos de entrada (sidebar + home + AJAX). Mantener contratos de estado (`folder`, `current_depth`) consistentes entre PHP y JS para evitar UI divergente. Acompanarlo con endpoints documentados y smoke/integration checks.
