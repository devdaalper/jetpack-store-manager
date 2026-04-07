# Extracción BPM automática integrada por lotes (sin CSV manual)

- Date: 2026-03-02 21:50

## Context

Se agregó endpoint/admin UI para extracción BPM automática en lotes (`jpsm_auto_detect_bpm_batch`), con detección software desde metadatos MP3 ID3 TBPM, guardado en overrides y actualización de índices; se añadieron métricas de pendientes BPM en stats y documentación de endpoint/decisión.

## What worked

El enfoque batch con estado en `bpm_source` (auto_none/auto_error/auto_unsupported) evita loops infinitos y permite progresar en catálogos grandes. Mantener manual_csv como prioridad evita sobrescribir correcciones humanas.

## What failed

Backblaze no entrega BPM nativo; sin tag embebido no hay detección confiable solo con este método. La versión actual cubre MP3 con TBPM y marca no soportados para no bloquear la cola.

## Next time

Siguiente iteración: agregar analizador acústico opcional (servicio Python/ffmpeg) para audios sin metadato y ampliar cobertura a m4a/flac/ogg sin depender de tags.
