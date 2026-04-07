# Phase A0: secret hygiene + security gate

- Date: 2026-02-08 08:52

## Context

Se elimino hardcode/fallback de credenciales B2 y datos del owner; Settings ahora es write-only para secretos (no se imprimen en HTML y no se borran si se submiten vacios); se ajustaron placeholders a example.com; se actualizo build+ZIP con security gate.

## What worked

Crear compuerta automatizada (scan_tree + scan_zip) y usarla como bloqueo; ajustar register_setting con sanitize_callback para preservar secretos si el campo viene vacio; reemplazar emails/dominios hardcodeados por admin_email o dominios reservados.

## What failed

El gate inicial con substring 'jetpackstore' daba falsos positivos por el nombre del repo; se afino a 'jetpackstore.net' para mantener senal alta.

## Next time

Mantener el security gate como paso obligatorio en cada release; agregar mas reglas cuando aparezcan nuevas superficies (export/import, nuevos endpoints).
