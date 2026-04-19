# Bugs Pendientes — MediaVault v2

Fecha: 2026-04-18
Estado: El UI se deployó pero tiene problemas funcionales graves.

## Bug 1: Junction auto-drilling no funciona
**Síntoma:** La página principal muestra "Full Pack [JetPack Store]" como carpeta sola. Debería auto-drillarse y mostrar las subcarpetas (PAQUETE MUSICAL VIP, PACK BÁSICO, etc.).
**Causa probable:** La función `detectJunction()` en `browse-folder.ts` busca `depth` en el file_index, pero puede que los valores de depth no coincidan con lo esperado. El sync puede haber calculado depth de forma diferente.
**Archivo:** `src/application/catalog/browse-folder.ts` — función `detectJunction()`
**Debug:** Ejecutar en Supabase SQL Editor:
```sql
SELECT DISTINCT depth, COUNT(*) FROM file_index WHERE version = 1 GROUP BY depth ORDER BY depth;
SELECT DISTINCT folder FROM file_index WHERE version = 1 AND depth = 1 LIMIT 10;
SELECT DISTINCT folder FROM file_index WHERE version = 1 AND depth = 2 LIMIT 10;
```

## Bug 2: Sidebar no muestra carpetas
**Síntoma:** El sidebar solo muestra "Inicio". No aparecen las 5 carpetas principales.
**Causa probable:** El layout.tsx filtra por `depth = 1`, pero si la estructura real tiene depth = 2 para las carpetas visibles (porque el root es "Full Pack [JetPack Store]/"), entonces no encuentra nada.
**Archivo:** `src/app/vault/layout.tsx` — la query de sidebar folders
**Fix probable:** Después de detectar el junction, buscar carpetas a depth = junction_depth + 1

## Bug 3: Lentitud general
**Síntoma:** El sistema es más lento que WordPress.
**Causa probable:** 
- Supabase free tier puede tener cold starts
- Las queries no están optimizadas (busca en 291K rows sin filtros eficientes)
- No hay caching de resultados
**Fixes:**
1. Agregar `select` específico (no `select *`)
2. Cache de resultados en memory o Vercel Edge Cache
3. Limitar queries con paginación
4. Considerar ISR (Incremental Static Regeneration) para carpetas populares

## Bug 4: No se cargan todas las carpetas sincronizadas
**Síntoma:** Solo 1 carpeta visible cuando deberían ser 5+
**Causa:** Mismo problema que Bug 1 — junction no funciona + sidebar query incorrecta

## Cómo reproducir
1. Ir a https://mediavault-teal.vercel.app/vault
2. Login si necesario
3. Observar: solo "Full Pack [JetPack Store]" visible, sidebar vacío

## Prioridad de fix
1. **Bug 1 + 2 + 4** (son el mismo root cause): arreglar junction + sidebar query
2. **Bug 3**: optimizar queries + agregar caching

## ROOT CAUSE IDENTIFICADO

**Active version = 2** (tiene 291,790 rows). Version 1 tiene 0 rows.

**El problema es que `depth` se calcula por la profundidad del ARCHIVO, no de la carpeta:**
- Depth 1: 0 archivos (no hay archivos en la raíz)
- Depth 2: 0 archivos (no hay archivos en "Full Pack [JetPack Store]/")
- Depth 3: primeros archivos aparecen (ej: "Full Pack.../PAQUETE MUSICAL VIP/JPM0002.../archivo.mp3")

**El sidebar busca `depth = 1` esperando encontrar carpetas, pero esa query devuelve vacío.**

**Fix necesario:**
1. **Sidebar:** No filtrar por `depth`. En su lugar, extraer las carpetas top-level de todos los `folder` values:
   ```sql
   SELECT DISTINCT folder FROM file_index WHERE version = 2
   ```
   Y luego extraer programáticamente el primer segmento de cada folder.

2. **Junction detection:** Usar la misma lógica — buscar segmentos únicos del primer nivel, no `depth`.

3. **browseFolder():** La lógica de subcarpetas ya funciona (busca `folder LIKE prefix%`), pero el sidebar está roto.

## Datos necesarios para debuggear
Ejecutar estas queries en Supabase para entender la estructura del index:
```sql
-- ¿Cuántas rows hay?
SELECT COUNT(*) FROM file_index WHERE version = 1;

-- ¿Qué depths existen?
SELECT depth, COUNT(*) FROM file_index WHERE version = 1 GROUP BY depth ORDER BY depth;

-- ¿Carpetas top-level?
SELECT DISTINCT folder FROM file_index WHERE version = 1 ORDER BY folder LIMIT 20;

-- ¿Cuántas carpetas únicas en depth 1?
SELECT DISTINCT folder FROM file_index WHERE version = 1 AND depth = 1 LIMIT 20;

-- ¿Cuántas carpetas únicas en depth 2?
SELECT DISTINCT folder FROM file_index WHERE version = 1 AND depth = 2 LIMIT 20;
```
