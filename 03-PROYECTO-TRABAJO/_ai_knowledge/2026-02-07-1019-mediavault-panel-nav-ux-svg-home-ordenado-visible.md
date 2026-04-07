# MediaVault: panel nav UX (SVG) + home ordenado visible

- Date: 2026-02-07 10:19

## Context

El panel de navegacion (Back/Forward/Inicio) tenia el boton Inicio en blanco (emoji sin soporte) y la pantalla Inicio podia percibirse rota/ desordenada por cache o por filtros que ocultaban carpetas en niveles superficiales.

## What worked

Reemplazar iconos de acciones por SVG inline (stroke=currentColor) y convertir Inicio en boton con etiqueta. Darle contenedor con fondo tokenizado (var(--mv-surface-hover)) para hacerlo mas visible. Guardar currentDepth y permitir que las carpetas sigan visibles en Inicio/nivel 1 aunque haya filtro Audio/Video activo. Al ir a Inicio, invalidar el cache in-memory de home para evitar ver un orden admin viejo sin recarga.

## What failed

Confiar en emoji para iconos de sistema puede renderizar glyph en blanco segun la fuente (se ve como boton vacio). Permitir que filtros oculten carpetas en Inicio hace que el usuario piense que se trabo o que no existen carpetas.

## Next time

Estandarizar: emojis solo para tipos de archivo; acciones siempre con SVG. Para estados base (Inicio) evitar combinaciones de UI que dejen pantalla vacia (filtros) y definir reglas de invalidacion de cache (por ejemplo al volver a Inicio o tras cambios admin) sin recargar la pagina.
