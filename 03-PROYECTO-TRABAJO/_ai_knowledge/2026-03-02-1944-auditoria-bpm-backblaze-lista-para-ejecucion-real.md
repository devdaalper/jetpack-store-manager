# Auditoria BPM Backblaze lista para ejecucion real

- Date: 2026-03-02 19:44

## Context

Se construyo un script CLI para auditar cobertura BPM en B2 usando API nativa (authorize, list_buckets, list_file_names) con muestreo configurable, deteccion de BPM en fileInfo y fallback por nombre de archivo.

## What worked

El script quedo autocontenido, sin depender de WordPress, y entrega resumen + JSON con cobertura, fuentes y ejemplos. Validacion local confirmo flujo de errores claro cuando credenciales no son reales.

## What failed

En este entorno solo habia credenciales test_* en wp-now, por lo que no se pudo auditar bucket productivo (401 bad_auth_token).

## Next time

Agregar opcion futura de HEAD S3 opcional para validar x-amz-meta directamente cuando el equipo use metadata S3 no reflejada en fileInfo; mantener timeout y limites de pagina para no saturar API.
