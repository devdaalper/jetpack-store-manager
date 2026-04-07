# Skill: security gate (no secretos/PII + dist ZIP)

- Date: 2026-02-08 08:24

## Context

Se creo una nueva skill de seguridad para este repo: mediavault-manager-security-gate (SKILLS/...). Incluye compuertas para escanear repo y el ZIP dist antes de releases, y se registro en AGENTS.md.

## What worked

Usar skill-creator/init_skill.py para crear estructura; scripts Python (scan_tree/scan_zip) evitan imprimir valores sensibles y reportan solo ubicaciones; generate_openai_yaml.py deja metadata de UI.

## What failed

generate_openai_yaml fallo al inicio por YAML frontmatter invalido (descripcion con ':' sin comillas).

## Next time

Citar/poner entre comillas cualquier descripcion en frontmatter que contenga ':' u otros caracteres YAML sensibles. Considerar integrar security_gate.sh en CI mas adelante para que sea bloqueante en releases.
