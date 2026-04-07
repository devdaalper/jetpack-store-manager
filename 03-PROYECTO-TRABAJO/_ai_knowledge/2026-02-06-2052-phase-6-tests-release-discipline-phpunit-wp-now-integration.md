# Phase 6: tests + release discipline (PHPUnit + wp-now integration)

- Date: 2026-02-06 20:52

## Context

Agregue suite de tests (PHPUnit con stubs de WP) y un smoke de integracion reproducible con wp-now; se valido con composer test e integration y se agrego RELEASE_CHECKLIST.md.

## What worked

PHPUnit 11 funciona en PHP 8.5 con bootstrap minimo; el smoke con wp-now valida endpoints reales (venta/historial/tier, MediaVault cookie+302, index stats/sync) sin depender de infraestructura externa.

## What failed

El script de integracion previo quedo corrupto (escapes + restos de patch) y rompia el parseo; PHP 8.5 emite E_DEPRECATED por curl_close() y ensuciaba la salida.

## Next time

Mantener scripts de smoke bajo lint (php -l) en CI local; suprimir E_DEPRECATED solo en harness; si el smoke crece, extraer helpers HTTP/SQLite a un modulo y documentar variables de entorno (puerto/version WP) en RELEASE_CHECKLIST.
