# Shortcode admin: pestañas + sidebar colapsable

- Date: 2026-02-07 16:11

## Context

Reorganicé la UI del shortcode [jetpack_manager] (templates/jpsm-dashboard.php) para separar Gestión de clientes, Base de datos de clientes y Métricas; además la barra lateral ahora es colapsable en desktop y tipo drawer en móvil. Se añadió panel lateral deslizable con lista de emails (derivado del historial) y se movieron KPIs/gráficas a la pestaña Métricas.

## What worked

1) Implementar navegación por pestañas con IDs claros y activar/ocultar con jpsmOpenTab. 2) Fix clave: añadir CSS .jpsm-tab-content{display:none} para evitar que todas las secciones se rendericen juntas al cargar. 3) Panel de clientes: render por JS desde jpsm_get_history, con filtro por email y drawer móvil. 4) Sidebar principal: colapso icon-only persistido con localStorage + overlay/cierre en móvil.

## What failed

Confiar solo en estilos inline para mostrar la pestaña inicial era insuficiente: las otras pestañas quedaban visibles por defecto y el panel se volvía un scroll infinito. También la UI de bulk-selection podía quedar desincronizada al re-renderizar sin reset.

## Next time

Estandarizar patrón de tabs: CSS base oculto + JS controla visibilidad. Evitar innerHTML sin escape en nuevos componentes (se agregó escapeHtml en lista/tablas; faltaría extenderlo al resto del dashboard si se endurece seguridad). Considerar extraer componentes (customer list/table) a funciones/módulos separados para reducir tamaño de jpsm-app.js.
