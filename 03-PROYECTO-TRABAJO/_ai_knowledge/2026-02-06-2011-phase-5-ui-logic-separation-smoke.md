# Phase 5: UI/Logic separation + smoke

- Date: 2026-02-06 20:11

## Context

Phase 5: se extrajo la agregación pesada del dashboard a servicios y se aisló el render en templates. Se movió Chart.js/login JS inline a archivos en assets y se localizó el payload de charts vía wp_localize_script.

## What worked

1) Crear JPSM_Stats_Service (agregados del dashboard) y JPSM_Access_Service (auth context) redujo lógica en UI. 2) Templates en /templates simplifican class-jpsm-dashboard.php. 3) Charts: pasar datos en jpsm_vars.dashboard_stats + render hook al abrir el tab evitó canvas 0-size. 4) Smoke con wp-now + SQLite validó endpoints sin romper compat.

## What failed

El login de MediaVault intenta setear cookie (jdd_access_token) dentro del template fullscreen después de imprimir HTML (wp_head/doctype), así que Set-Cookie no sale en local (headers ya enviados).

## Next time

Mover el handler de login/guest en MediaVault para que ejecute antes de emitir output (antes de render_full_page), o envolver render_full_page completo en output buffering para permitir setcookie/wp_redirect. En smoke, documentar un helper oficial (temporal) para setear user cookie y removerlo al final.
