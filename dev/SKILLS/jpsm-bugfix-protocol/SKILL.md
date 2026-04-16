---
name: jpsm-bugfix-protocol
description: Protocolo para corregir bugs sin romper lo existente: repro -> fix minimo -> guardas (tests) -> smoke -> documentacion -> notas.
---

# JPSM Bugfix Protocol

Use este skill cuando se solicite corregir un bug o "pulir" un flujo.

## Workflow
1. Reproducir y definir contrato:
   - Documente "Comportamiento actual" vs "Comportamiento esperado".
   - Identifique el detonador exacto (acciones UI, endpoint, payload, etc.).

2. Verificar zona protegida:
   - Si toca `includes/modules/mediavault/`, active tambien `$jpsm-protected-download-zone` y siga `docs/standards/protected-download-engine.md`.

3. Aislar el cambio:
   - Evite refactors no relacionados.
   - Cambios pequenos, con rollback mental claro.

4. Agregar guardas contra regresion:
   - Preferir test automatizado (PHPUnit / integracion).
   - Si no aplica, agregar un "guard test" (ej. asercion de strings/contratos) o checklist documentado.

5. Implementar la correccion:
   - En UI: evitar recargas completas si hay procesos largos (descargas, playback).
   - Cancelar requests en vuelo si hay navegacion rapida (ej. `AbortController`) para evitar estados viejos.

6. Verificar:
   - Ejecutar `composer test` y `composer integration`.
   - Registrar el resultado en `docs/SMOKE_TESTS.md` (fecha, alcance, pass/fail).

7. Documentacion y aprendizaje:
   - Actualice el estandar correspondiente en `docs/standards/` si el bug refleja una regla de producto/UX o una restriccion critica.
   - Escriba una nota en `_ai_knowledge` (ver `$ai-knowledge-habit`) explicando causa raiz y la leccion generalizable.

## Output
- Correccion con cambios minimos y pruebas/guardas.
- Evidencia en `docs/SMOKE_TESTS.md`.
- Nota tecnica en `_ai_knowledge` con aprendizaje aplicable a otros proyectos.
