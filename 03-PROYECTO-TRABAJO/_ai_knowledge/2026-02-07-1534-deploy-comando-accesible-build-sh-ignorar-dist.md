# Deploy: comando accesible ./build.sh + ignorar dist/

- Date: 2026-02-07 15:34

## Context

Se requeria que el build del ZIP de produccion fuera facil de ejecutar desde la raiz del proyecto y que el artefacto no se subiera/commitiera por accidente.

## What worked

Agregar  en la raiz que delega a Built: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/dist/jetpack-store-manager.zip, y agregar  a  para mantener el repo limpio. Validar que Built: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/dist/jetpack-store-manager.zip genera .

## What failed

Dejar el comando solo dentro de  lo hace menos visible; no ignorar  incrementa riesgo de subir artefactos al repo.

## Next time

Mantener un entrypoint de build en la raiz (Makefile o build.sh) y documentar en  para que el deploy siempre sea por ZIP y no por FTP del repo completo.
