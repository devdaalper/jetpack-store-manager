# Hotfix: B2 errores + tier en venta + login host-safe

- Date: 2026-02-08 14:37

## Context

Fixes para staging/prod: (1) mejor señal de errores al probar conexion B2 S3 (parse XML Code/Message + hint, y enviar header Host para firma mas estable), (2) admin.js ahora muestra mensajes reales aunque el backend responda 4xx/5xx (jQuery .fail parsea JSON), (3) al registrar una venta se persiste el tier/entitlement inmediatamente, y (4) login de MediaVault redirige a REQUEST_URI relativo para no perder cookie en mismatch www/non-www. Se actualizaron docs (SMOKE_TESTS, TROUBLESHOOTING, SESSIONS) y se corrio release gate.

## What worked

1) Mostrar Code/Message (AccessDenied/SignatureDoesNotMatch/NoSuchBucket) hace el debugging de B2 instantaneo sin exponer secretos. 2) Cambiar redirect post-login a path relativo evita el bug de cookie perdida al cambiar host. 3) Persistir tier tras venta elimina confusion en permisos y acelera la primera entrada al vault. 4) composer release:verify paso (unit+integration+security gate + ZIP).

## What failed

composer integration fallo una vez con WP login 500 por estado/puerto de wp-now; al limpiar el proceso y reintentar paso.

## Next time

Hacer que el harness de integracion use un puerto aleatorio o detecte/termine procesos wp-now previos para evitar flakes. Reusar un helper comun de escape/errores en admin JS para evitar futuros sinks XSS.
