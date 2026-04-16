# Deployment (Produccion)

Este repositorio contiene archivos de trabajo (documentacion, tests, skills, notas) que NO deben subirse a WordPress.
Para que las actualizaciones sean rapidas (y consistentes), el despliegue debe hacerse con un paquete "dist" que incluya solo lo necesario para runtime.

## Build del plugin (ZIP)

1. Ejecuta:

```bash
cd "/path/to/Administrador JetPackStore/01-WORDPRESS-SUBIR/jetpack-store-manager"
composer release:verify
```

2. Salida (artefacto único de despliegue):
- `dist/mediavault-manager.zip`

Nota (Option A): el ZIP se llama `mediavault-manager.zip`, pero la carpeta interna del plugin sigue siendo
`jetpack-store-manager/` para no romper actualizaciones existentes.

`composer release:verify` corre desde `01-WORDPRESS-SUBIR/jetpack-store-manager`, pero consume el harness compartido de `03-PROYECTO-TRABAJO`. Ejecuta:
- Unit tests (`composer test`)
- Integration smoke (`composer integration`)
- Security gate (bloqueante) + build del ZIP

Ese ZIP contiene:
- `includes/`, `assets/`, `templates/`, `jetpack-store-manager.php`
- `vendor/` instalado con Composer en modo produccion (`--no-dev`)

Y excluye:
- `docs/`, `tests/`, `scripts/`, `SKILLS/`, `_ai_knowledge/`, `.git/`, `.github/`, etc.
- `desktop-bpm-app/` (app local de escritorio BPM, nunca se sube a WordPress).

## Instalacion en WordPress

Opciones:
1. Recomendado: subir el ZIP en `wp-admin -> Plugins -> Add New -> Upload Plugin`.
2. FTP: descomprimir y subir SOLO la carpeta `jetpack-store-manager/` del ZIP a `wp-content/plugins/jetpack-store-manager/` (reemplazando la anterior).

## Importante
- No subas este repo completo a WordPress (contiene `docs/`, `tests/`, `SKILLS/`, etc.).
- No subas `desktop-bpm-app/` al servidor WordPress.
- El despliegue debe hacerse siempre desde `dist/mediavault-manager.zip`.
