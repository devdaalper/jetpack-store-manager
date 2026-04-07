# Activacion Cloudflare en URLs de descarga MediaVault

- Date: 2026-02-13 17:25

## Context

Se activo runtime real de jpsm_cloudflare_domain para reescribir origen de URLs firmadas en mv_get_presigned_url, mv_get_preview_url(direct) y mv_list_folder; se agrego sanitizacion estricta de origen HTTPS, pruebas unitarias/integracion y guias de Worker+DNS.

## What worked

Centralizar normalize+rewrite en JPSM_Config permitio fallback seguro a B2 directo y cambios minimos en template-vault. La prueba de integracion con host esperado en mv_get_presigned_url detecta regresiones de wiring Cloudflare sin tocar reglas de tier.

## What failed

El primer assert de integracion asumio payload URL en data.url y fallo con envelope v2; hubo que soportar data.data.url para contrato mixto.

## Next time

Cuando un endpoint usa JPSM_API_Contract con api_version=2, escribir asserts duales (legacy y v2) desde el inicio para evitar falsos negativos y mantener compatibilidad.
