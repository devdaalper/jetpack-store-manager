# Phase A4: onboarding (pages) + setup wizard + health checks

- Date: 2026-02-08 11:37

## Context

Implementacion de A4: eliminar slug hardcodeado (descargas) en MediaVault loader, agregar opciones de page_id para MediaVault/Manager, agregar submenu Setup con wizard y health checks, y docs INSTALL/TROUBLESHOOTING. Mantener compatibilidad via deteccion de shortcode.

## What worked

1) En loader.php: detectar pagina por shortcode o por option jpsm_mediavault_page_id. 2) En admin: submenu Setup + admin_post para crear/detectar pages de forma idempotente. 3) Docs para compradores: INSTALL + TROUBLESHOOTING. 4) Gates: composer test/integration + security gate.

## What failed

Nada relevante; principal riesgo era romper el routing por remover is_page("descargas"), mitigado por option + shortcode.

## Next time

Extender setup wizard con pruebas interactivas (B2 test, index sync) y enlaces directos a pages creadas. En el futuro, agregar una comprobacion automatizada que alerte si alguien reintroduce is_page("descargas") u otros slugs hardcodeados en MediaVault.
