# Resultado real auditoria BPM bucket Recursos-JetPackStore

- Date: 2026-03-02 20:06

## Context

Se ejecuto auditoria BPM contra Backblaze B2 con credenciales reales y bucket Recursos-JetPackStore usando script diagnostics/b2_bpm_audit.php. Se corrio muestra de 300, 1500 y 5000 audios.

## What worked

Auditoria directa sobre API nativa B2 entrego cobertura estable al ampliar muestra; los BPM detectados provienen de patrones en ruta/nombre de archivo, no de metadata tecnica fileInfo.

## What failed

No hay BPM en metadata de B2 (file_info=0 en todas las corridas), por lo que no existe fuente estructurada para filtrar BPM completo sin proceso de enriquecimiento.

## Next time

Implementar columna bpm en indice local y backfill asincrono por parser (ruta/nombre + reglas), luego opcionalmente enriquecer faltantes con analisis audio o carga manual/CSV para subir cobertura.
