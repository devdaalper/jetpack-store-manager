# Hotfix módulo descargas por carpeta (fallback + ventanas)

- Date: 2026-02-20 04:54

## Context

Se restauró el módulo de demanda por carpeta con fuente híbrida (behavior primary + fallback legacy), se añadió filtro temporal window (month/prev/90d/lifetime), KPIs de demanda y badge de calidad de fuente, y se ampliaron CSV/tests/integración.

## What worked

Separar fuente principal y fallback evitó doble conteo; agregar window en contrato mantuvo frontend y export alineados; limitar warm-up para ventanas no mensuales evitó timeouts de integración.

## What failed

Ejecutar integración vía composer con timeout default de 300s fue inestable; en algunos corridos expiró aunque la suite funcionalmente pasaba.

## Next time

Para CI, fijar COMPOSER_PROCESS_TIMEOUT>=900 o ejecutar el script integration directo; además incluir monitoreo de frescura del rollup diario para detectar gaps antes de que lleguen al dashboard.
