# UI admin: evitar loading infinito en busquedas AJAX

- Date: 2026-02-06 22:01

## Context

En el panel WP (Control de Accesos -> Gestionar Usuarios), al buscar por email quedaba en 'Buscando...' indefinidamente cuando el request AJAX fallaba o la respuesta no se podia parsear como JSON (p.ej. warnings/notices del backend).

## What worked

Cambiar $.get(...) por $.ajax({dataType:'json', timeout}) + handlers .done/.fail para siempre actualizar el UI. Registrar el smoke a nivel codigo (composer test) en docs/SMOKE_TESTS.md.

## What failed

Asumir que el callback de exito siempre correra: si el backend devuelve JSON invalido o hay error de red, jQuery no llama al success y la UI se queda colgada.

## Next time

Estandarizar un protocolo de bugfix: (1) delimitar UI vs backend, (2) exigir contrato JSON sin output extra, (3) agregar always/finally para limpiar spinners/loaders, (4) agregar timeout y logging para diagnosticar parse errors. Aplicable a cualquier proyecto con UI async.
