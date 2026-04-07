# Crear skill global de memoria AI

- Date: 2026-02-06 16:03

## Context

Se creó una skill global para forzar lectura y escritura de _ai_knowledge antes y después de tareas técnicas

## What worked

Inicializar skill con init_skill.py y agregar script reusable funcionó bien

## What failed

La validación inicial falló por frontmatter YAML sin comillas y por ejecutar script sin permisos

## Next time

Citar descripciones largas en YAML y ejecutar quick_validate al primer cambio
