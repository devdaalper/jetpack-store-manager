# Deploy: empaquetado dist (ZIP) para no subir docs/tests/skills

- Date: 2026-02-07 13:54

## Context

El plugin empezo a incluir miles de archivos al subir por FTP porque el repo ahora contiene vendor, docs, tests, skills y notas locales. Eso hace lentas las actualizaciones y sube cosas que no deben ir a produccion.

## What worked

Agregar un script de build Built: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/dist/jetpack-store-manager.zip que genera  con solo runtime: , , , entrypoint y  instalado con Composer en modo produccion. Excluir , , , , , , etc. Documentar el flujo en .

## What failed

Subir el repo completo por FTP mezcla archivos de trabajo con runtime y escala el numero de archivos (vendor + notas + docs), volviendo el deploy lento y riesgoso.

## Next time

Estandarizar que SIEMPRE se despliega desde un artefacto dist (ZIP) o via rsync con exclude. Mantener una lista unica de excludes (script/manifest) para evitar fugas de archivos locales a produccion.
