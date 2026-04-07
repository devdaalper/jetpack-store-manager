# JPSM BPM Desktop (macOS GUI)

Aplicación de escritorio para analizar BPM de audios en Backblaze B2 y publicar resultados al plugin MediaVault.

## Características v1
- GUI con flujo estricto por pasos (wizard bloqueante).
- Preflight hard-fail.
- Dry-run obligatorio.
- Backfill pausable/reanudable con checkpoints y recuperación tras cierre inesperado.
- Pipeline BPM por orden: metadata embebida (TBPM MP3) -> reglas de nombre/ruta -> acústico (ffmpeg + librosa; aubio opcional).
- Revisión manual mínima de casos dudosos.
- Publicación por CSV o API token (`jpsm_import_bpm_batch_api`) con doble confirmación.
- Rollback por batch (`jpsm_rollback_bpm_batch_api`) desde la pestaña Reporte.

## Estructura
- `src/bpm_desktop/`: código principal.
- `resources/`: assets y binarios opcionales (ej. ffmpeg embebido).
- `local_data/`: SQLite/logs/exportaciones (se crea automáticamente).

## Requisitos
- macOS
- Python 3.11+
- Dependencias en `requirements.txt`
- Token desktop generado desde WordPress (`Sincronizador B2 > Token API Desktop BPM`)

## Ejecución local
```bash
cd 02-DESKTOP-BPM-APP
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
PYTHONPATH=src python -m bpm_desktop.main
```

O en macOS (doble click):

- `02-DESKTOP-BPM-APP/run_gui.command`

## Nota
Esta app vive en `apps/` y **no se incluye** en el ZIP del plugin WordPress.

## Flujo operativo obligatorio (anti-error)
1. Conexiones: guarda credenciales B2 + URL/token WordPress.
2. Preflight: todos los checks deben quedar en verde.
3. Dry-run: ejecuta muestra y valida que no haya inválidos.
   Durante el dry-run la UI muestra barra de progreso, contador procesado/total y archivo actual para confirmar actividad.
4. Backfill: corre lotes (puedes pausar/reanudar).
5. Revisión: corrige solo casos de baja confianza/conflicto.
6. Publicación: quality gate + doble confirmación.
7. Reporte: auditoría final y rollback por lote API si se requiere.
