# Phase 4: Contrato API v2 + REST piloto

- Date: 2026-02-06 19:07

## Context

Se implementó Phase 4 agregando JPSM_API_Contract (envelope v2 opt-in, validación de campos requeridos, códigos/status) y se migraron handlers AJAX existentes a usarlo sin romper el shape legacy. Se añadió un endpoint REST piloto /jpsm/v1/status.

## What worked

El enfoque opt-in (api_version=2) permitió estandarizar códigos/errores sin forzar cambios inmediatos en el frontend. Smoke runtime en wp-now validó tanto flows críticos como el nuevo contrato v2.

## What failed

El endpoint REST inicialmente falló por permisos (cookies WP no aplican en REST sin nonce), y hubo que habilitar auth alternativa via JPSM_Auth/secret key para pruebas.

## Next time

Para próximos endpoints REST, definir desde el inicio una estrategia de auth (nonce, application passwords o key signed) y agregar un script de smoke que cubra redirect /wp-json/... -> /wp-json/.../.
