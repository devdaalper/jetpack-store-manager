# Cache-bust: bump version para assets MediaVault

- Date: 2026-02-09 13:52

## Context

Usuario no vio el cambio de UI (boton Descargar en subcarpetas). Probable causa: JS cacheado por version constante (A5 uso JPSM_VERSION para versionado de assets).

## What worked

Bump de JPSM_VERSION (1.2.3) para forzar cache-bust en WP/browser/CDN; rebuild dist/mediavault-manager.zip. Confirmado que ZIP contiene header Version 1.2.3.

## What failed

Sin bump, el cambio puede parecer 'no funciono' aunque el codigo local este correcto.

## Next time

Para cambios de UI criticos, siempre bump version antes de enviar ZIP, y si el hosting tiene cache/CDN, pedir hard reload y purge del cache al actualizar plugin.
