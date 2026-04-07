# BPM profundo automático con reset y ffmpeg

- Date: 2026-03-03 22:51

## Context

Se completó el extractor BPM integrado en MediaVault: modo deep con análisis acústico vía ffmpeg, reset de marcas auto_* y ejecución por lotes desde UI de Sincronizador.

## What worked

Corregir firma de auto_detect_bpm_batch y añadir reset endpoint eliminó bloqueos de reintento; ffmpeg + autocorrelación sobre PCM dio detección estable en prueba sintética; tests phpunit/lint/integración pasaron.

## What failed

Si ffmpeg no está instalado en servidor, modo deep falla por diseño para evitar marcar falsos no_bpm; requiere preparar entorno primero.

## Next time

Agregar campo UI para ruta ffmpeg y un modo dual (deep/meta) seleccionable; incluir test de endpoint reset y mock de errores de decode para cubrir diagnósticos.
