# Pipeline BPM completo en MediaVault con CSV y filtros

- Date: 2026-03-02 20:20

## Context

Se cerró implementación full BPM en MediaVault: schema v2.1 (bpm/bpm_source), tabla de overrides persistentes, endpoint AJAX de importación CSV, filtros BPM en UI y búsqueda global con rango BPM; además se actualizaron DATA_STORES, ENDPOINTS y REFRACTORING_PLAN.

## What worked

La estrategia override-first funcionó: BPM manual via CSV persiste y sobrevive a re-sync. Pruebas clave pasaron (php -l, PHPUnit IndexManager/AccessManager, integración wp-now PASS). El enfoque mantuvo funnel de visibilidad sin filtrar URLs firmadas en browse/search.

## What failed

No hubo BPM nativo en metadata de B2 para los objetos auditados, por lo que depender solo de proveedor no era viable. Sin CSV de curación la cobertura BPM inferida por patrones sigue siendo limitada en catálogos heterogéneos.

## Next time

Mantener plantilla CSV oficial y proceso periódico de curación BPM por lotes. Añadir futura columna de orden por BPM en UI y reporte de cobertura por carpeta para priorizar curación donde más impacto comercial tenga.
