# Separación física de app desktop en raíz

- Date: 2026-03-04 11:04

## Context

Se movió la app BPM desde apps/bpm-desktop a una carpeta raíz dedicada desktop-bpm-app para eliminar mezcla visual con el plugin. Se ajustaron rutas de build/ignore/docs y se añadió guía de organización en raíz.

## What worked

Mover la app a carpeta raíz dedicada y actualizar referencias eliminó ambigüedad para usuarios no técnicos; compilar Python en la nueva ruta confirmó consistencia.

## What failed

No se movió el plugin completo a otra carpeta porque rompería el flujo actual de empaquetado/WordPress y aumentaría riesgo de regresión.

## Next time

Si se quiere separación total de dos carpetas hermanas (plugin y app), hacerla en una fase planificada con migración de scripts CI/build y ruta de trabajo documentada.
