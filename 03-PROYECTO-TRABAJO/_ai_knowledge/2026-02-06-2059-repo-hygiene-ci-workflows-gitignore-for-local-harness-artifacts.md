# Repo hygiene: CI workflows + gitignore for local harness artifacts

- Date: 2026-02-06 20:59

## Context

Se limpio el estado del repo: split de commits (codigo vs tests/docs), se agrego GitHub Actions (phpunit en push/PR + integration manual), y se ignoran artefactos locales (.phpunit.cache, _ai_knowledge, debug_index.php, msg).

## What worked

Separar commits por tema reduce ruido al revisar; workflows de Actions corren composer test siempre y dejan wp-now integration como gate manual; ignorar caches evita worktree sucio.

## What failed

Si se intenta correr integration en CI en cada push puede ser lento/flaky por descargas de wp-now/WP; por eso quedo manual.

## Next time

Si se necesita integracion continua, agregar cache de npm/composer y un schedule nightly; mantener scripts de smoke con php -l y salida limpia (sin E_DEPRECATED) para detectar fallos reales.
