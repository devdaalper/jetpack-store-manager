# Mapeo módulo de descargas por carpeta

- Date: 2026-02-17 14:09

## Context

Se auditó el flujo de descarga por carpeta en MediaVault sin modificar código: UI, endpoint backend, permisos y eventos analíticos.

## What worked

Usar rg+nl para localizar rápidamente el endpoint mv_list_folder, el click handler de carpeta y la persistencia en Data Layer permitió reconstruir el flujo completo y los puntos de tracking actuales.

## What failed

No se detectó fallo funcional durante esta fase porque fue solo de lectura; no se ejecutaron smoke tests de runtime.

## Next time

En próximos cambios, definir primero eventos de intento/inicio/fin/error con correlación por download session id y luego validar en dashboard segmentado por mes/tier/dispositivo.
