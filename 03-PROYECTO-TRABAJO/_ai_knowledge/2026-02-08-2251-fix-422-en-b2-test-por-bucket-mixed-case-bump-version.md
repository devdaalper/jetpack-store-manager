# Fix: 422 en B2 test por bucket mixed-case + bump version

- Date: 2026-02-08 22:51

## Context

En staging el test B2 devolvia 422 al dar click en "Probar conexion". La causa era validacion demasiado estricta del bucket (solo a-z0-9), pero el bucket real usa mayusculas. Se ajusto validate_b2_config para permitir A-Z y se subio la version a 1.2.1 para bust de cache en assets admin. Se agrego stub de WP_Error para tests y un unit test de validacion.

## What worked

Permitir bucket mixed-case elimino el 422 y deja que el test llegue a S3 para reportar errores reales (AccessDenied/SignatureDoesNotMatch/NoSuchBucket). Bump de version asegura que admin.js nuevo se recargue sin depender de hard refresh. Gates verdes (unit+integration+security gate).

## What failed

Nada relevante.

## Next time

Mantener validaciones "moderadamente estrictas" pero compat con reglas reales del proveedor. Siempre bump de version cuando se cambian assets (admin.js) para evitar cache flakey en WP admin.
