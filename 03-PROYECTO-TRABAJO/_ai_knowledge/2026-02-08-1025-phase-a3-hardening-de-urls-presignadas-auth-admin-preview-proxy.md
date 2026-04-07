# Phase A3: hardening de URLs presignadas + auth admin + preview proxy

- Date: 2026-02-08 10:25

## Context

Se implemento la Phase A3 del plan de productizacion: quitar URLs presignadas de browse/search/HTML, mover descargas a resolucion on-demand con chequeo de entitlement, endurecer auth admin (sin admin por email), deshabilitar key en GET por defecto, y agregar un proxy de preview byte-capped para demo/locked.

## What worked

1) mv_ajax + mv_search_global ahora regresan metadata sin url. 2) mv_get_presigned_url ahora valida sesion + tier>0 + user_can_access antes de firmar. 3) mv_get_preview_url + mv_stream_preview permiten preview sin exponer URL directa para locked/demo (token + session + Range cap). 4) JS migro a data-path y pide URLs on-demand (api_version=2 para codes). 5) Settings agrego toggles (wp_admin_only, allow_get_key) + whatsapp; docs + integration + security gate quedaron verdes.

## What failed

El integration harness fallo al probar demo porque B2 no estaba configurado (mv_get_presigned_url respondia missing_config).

## Next time

En la proxima: 1) Seedear config dummy en wp-now bootstrap cuando se testean gates de entitlement. 2) Para checkboxes en Settings, siempre incluir hidden=0 para permitir desactivar. 3) Extender escaping XSS a TODO insertAdjacentHTML restante. 4) Considerar enforcement server-side del limite de 60s (no solo byte cap) si se requiere mayor proteccion anti-exfiltration.
