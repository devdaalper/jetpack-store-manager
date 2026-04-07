# MediaVault: descargas faltantes por permisos de carpeta

- Date: 2026-02-06 22:34

## Context

Bug: a veces usuarios pagados no veian el boton/enlace real de Descargar (quedaba bloqueado) aunque debian tener acceso. Causa: el UI legacy de permisos por carpeta enviaba un solo campo tier (minimo), pero el backend lo guardaba como array de un solo elemento y el motor lo interpretaba como lista exacta (no como minimo).

## What worked

1) En includes/class-access-manager.php se normalizaron permisos: ints y arrays de un solo elemento ahora se expanden a rango min..5. 2) update_folder_tier_ajax distingue matriz (tiers como lista explicita) vs legacy (tier minimo) y usa set_folder_tier para rango. 3) get_all_folder_permissions ahora devuelve valores normalizados para que el frontend no reciba singletons. 4) Se agregaron tests unitarios y composer integration paso.

## What failed

Asumir que un array siempre significa lista exacta: en este repo conviven dos modelos (lista explicita vs minimo legacy). Guardar un singleton como array rompia el acceso para tiers superiores.

## Next time

Definir y documentar el contrato de permisos (explicito vs minimo) y normalizar en un solo lugar. Para UIs legacy: almacenar formato canonico o versionar schema. Para endpoints: evitar ambiguedad de payload (tier vs tiers) y cubrirlo con tests.
