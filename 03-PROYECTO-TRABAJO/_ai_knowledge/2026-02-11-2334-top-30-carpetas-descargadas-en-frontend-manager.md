# Top 30 carpetas descargadas en frontend manager

- Date: 2026-02-11 23:34

## Context

Se implemento analitica historica de descargas de carpeta en el flujo activo de MediaVault: nueva tabla jpsm_folder_download_events en Data Layer, tracking pasivo en mv_list_folder y nueva pestana dedicada en el dashboard frontend con KPIs + tabla Top 30. Se actualizaron docs de data stores/endpoints/refactoring.

## What worked

Mantener el tracking dentro de mv_list_folder (endpoint real de descarga de carpeta) evito depender del modulo downloader dormido. El diseno quedo consistente reutilizando jpsm-modern-table y tokens CSS existentes. El esquema versionado en Data Layer permitio crear la tabla sin exponer endpoints nuevos.

## What failed

Intentar validar el cambio mirando git diff contra HEAD fue ruidoso porque el repo ya estaba muy sucio con cambios previos en los mismos archivos; se requirio verificar por secciones y con pruebas ejecutables en lugar de confiar en un diff limpio.

## Next time

Cuando el arbol este sucio, validar feature por tests/smoke y busquedas dirigidas (IDs de tab, claves de stats, metodos nuevos) para reducir riesgo de mezclar contexto. Mantener instrumentacion en zona protegida al minimo y envolverla en try/catch para no romper descargas si falla la analitica.
