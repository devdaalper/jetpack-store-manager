# Claridad KPI en descargas por carpeta (tooltips + glosario + semáforos)

- Date: 2026-02-20 15:35

## Context

Se completó la capa de claridad de KPI en el tab de descargas por carpeta sin tocar cálculos backend: etiquetas de negocio, tooltip por KPI, glosario lateral persistente en desktop y bottom-sheet en mobile, estados semáforo/tendencia y manejo explícito de N/A.

## What worked

Centralizar metadatos en transferKpiMetaMap permitió mantener 13 KPI alineados entre tarjeta, tooltip y glosario. El wiring delegado de help buttons + Escape/click outside mejoró usabilidad sin romper filtros/ventanas.

## What failed

Sin estilos específicos, el glosario y ayudas quedaban visibles pero confusos; además los valores sin histórico se interpretaban como cero.

## Next time

Mantener siempre una capa de metadata de lectura (nombre/definición/acción) para cada KPI y validar conteo KPI=tooltips=status antes de cerrar cambios UI analíticos.
