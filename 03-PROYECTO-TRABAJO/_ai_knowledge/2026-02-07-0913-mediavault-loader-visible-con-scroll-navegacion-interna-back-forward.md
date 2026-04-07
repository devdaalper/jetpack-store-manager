# MediaVault: loader visible con scroll + navegacion interna back/forward

- Date: 2026-02-07 09:13

## Context

El loader de carga se quedaba arriba dentro del grid y al hacer scroll el usuario no lo veia, lo que se percibia como bloqueo. Ademas hacia falta un panel interno de navegacion (atras/adelante) para reducir el uso de los controles del navegador y el riesgo de refrescar (que cancela descargas).

## What worked

Mover el loader a un host en la toolbar sticky (#mv-toolbar-status) y que JS inyecte #mv-grid-loading ahi mantiene el indicador siempre visible. Agregar botones internos #mv-nav-back/#mv-nav-forward conectados a History API (pushState/popstate) navega carpetas sin recargar. Preservar mv/navId al hacer replaceState en exitSearchMode evita romper el historial interno.

## What failed

Tener el loader como elemento absoluto dentro de #mv-grid lo hace invisible tras scroll y puede desaparecer cuando renderGrid limpia el DOM. Usar history.replaceState({}) en flujos como limpiar busqueda borra metadata necesaria para navegar de forma consistente.

## Next time

Tratar history.state como contrato en SPAs: cualquier replaceState/pushState debe mantener marcas de modulo (mv/navId/folder). Proveer siempre un host sticky para estados globales (loading/errores) y agregar tests guard para evitar regresiones de UX criticas en modulos protegidos.
