# XSS hardening extra pass (MediaVault + Manager)

- Date: 2026-02-08 10:52

## Context

Pasada extra enfocada solo en XSS restante: se escaparon strings no confiables en sinks de DOM (innerHTML/insertAdjacentHTML) en MediaVault y Manager; se eliminaron handlers inline con strings interpolados (onchange con email/folder) en el panel de permisos; y se endurecio la emision inline de MV_USER_DATA como JSON con opciones JSON_HEX_* para evitar break-out via </script>.

## What worked

1) Helpers de escape mvEscapeHtml/mvEscapeAttr + escapeHtml en Manager y aplicarlos sistematicamente en templates JS. 2) Sustituir inline onchange por addEventListener con closures/dataset. 3) Emitir MV_USER_DATA como JSON completo via wp_json_encode(..., JSON_HEX_TAG|...) para evitar XSS en <script>.

## What failed

composer integration fallo al inicio porque el harness buscaba nonce con regex nonce: "..."; al cambiar MV_USER_DATA a JSON ya no matcheaba. Se ajusto el extractor para soportar ambos formatos.

## Next time

Tratar cualquier inline <script> con datos dinamicos como hot-zone XSS: siempre usar wp_json_encode con JSON_HEX_TAG. Agregar una regla automatizada que alerte si aparece innerHTML/insertAdjacentHTML con interpolacion sin escape, o si se emiten objetos JS manualmente en PHP con json_encode sin flags.
