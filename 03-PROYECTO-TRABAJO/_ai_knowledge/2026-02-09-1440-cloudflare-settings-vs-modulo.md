# Cloudflare: settings vs modulo

- Date: 2026-02-09 14:40

## Context

Audite el repo para ver si existe modulo/integracion Cloudflare y si esta activa. Solo encontre setting opcional jpsm_cloudflare_domain y constante JPSM_CLOUDFLARE_DOMAIN; no hay dependencias ni usos runtime.

## What worked

Buscar por 'cloudflare' y por uso de la constante/opcion; revisar entrypoint del plugin y settings UI.

## What failed

Asumir que el 'domain' se usaba para reescribir URLs: en el codigo no hay consumidores; es solo un placeholder.

## Next time

Si se implementa CDN Cloudflare, agregar un unico punto de generacion de URLs (helper) y tests que verifiquen que jpsm_cloudflare_domain cambia la URL final; documentar 'activo' como 'option no vacia + usada en builder'.
