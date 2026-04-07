# MediaVault: sidebar activo flakey (AJAX + slash inconsistente)

- Date: 2026-02-06 23:10

## Context

Bug UI: la navegacion izquierda a veces no resaltaba la carpeta actual. Pasaba especialmente al navegar via AJAX (sin recargar) y al entrar desde breadcrumbs/links que omitian el slash final en el parametro folder.

## What worked

1) Normalizar folder en backend (si viene sin slash final, agregarlo). 2) Normalizar comparaciones de activo en PHP (trim + slash) para evitar mismatches. 3) En el cliente, agregar un updateSidebarActive() que se ejecuta en carga y en cada loadFolder() para mantener el highlight sincronizado durante navegacion AJAX.

## What failed

Confiar solo en el render del servidor para el estado activo: con navegacion AJAX el sidebar no se re-renderiza. Ademas, comparar strings sin normalizacion (folder con o sin slash) produce falsos negativos.

## Next time

Definir un formato canonico para paths de carpeta (sin leading slash, con trailing slash) y usar helpers compartidos al construir links, comparar estado y hacer fetch. Para cualquier SPA/AJAX: cuando cambie la ruta, actualizar tambien el estado visual de nav.
