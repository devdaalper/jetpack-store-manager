# Popup movil automatico para aviso desktop en MediaVault

- Date: 2026-02-12 16:14

## Context

Se ajusto el aviso movil para que el modal aparezca automaticamente al cargar la pagina en usuarios elegibles (movil, tier>0, no admin), manteniendo excepcion demo/admin y el gate antes de descargar. Se agrego bandera runtime para no reabrir el modal varias veces en la misma carga.

## What worked

Reusar initDesktopRecommendationNotice permitio activar popup automatico sin tocar endpoints ni contratos de descarga. La bandera desktopNoticeAutoPopupShown evita repeticion intrapagina.

## What failed

Ninguna falla funcional en este ajuste; fue un cambio puntual sobre la logica existente.

## Next time

Cuando haya feedback UX de visibilidad, priorizar cambios en el punto de inicializacion antes que multiplicar banners/modales nuevos para mantener comportamiento predecible.
