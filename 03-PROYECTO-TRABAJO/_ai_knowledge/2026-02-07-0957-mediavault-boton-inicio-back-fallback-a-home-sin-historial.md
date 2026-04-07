# MediaVault: boton Inicio + back fallback a home (sin historial)

- Date: 2026-02-07 09:57

## Context

Se necesitaba volver a la pantalla de inicio (junction view) en cualquier momento. En deep links (cuando abres directo una carpeta) el historial interno no contiene Home, por lo que el boton Atras quedaba deshabilitado y no habia forma segura (sin recarga) de regresar.

## What worked

Agregar un boton Inicio en la toolbar (mv-nav-home) que navega a folder vacio via AJAX + History API. Ajustar la logica de botones para que Atras, cuando el historial interno esta en indice 0 pero el folder actual no esta vacio, haga fallback a Inicio en vez de intentar history.back() (que podria salir de MediaVault).

## What failed

Confiar solo en el historial del navegador para regresar a Home falla en deep links: no hay entrada previa y el boton Atras debe seguir siendo util sin arriesgar salir de la pagina.

## Next time

Para UIs SPA dentro de WordPress: siempre proveer una accion explicita y segura para volver a un estado base (Home) sin recargar, y en controles Back/Forward manejar el caso de deep link con fallback controlado para no salir del modulo protegido.
