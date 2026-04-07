# Mapeo de CTAs upgrade: email a WhatsApp en MediaVault

- Date: 2026-02-23 00:07

## Context

Se auditó el flujo de botones de solicitud de mayor acceso para tiers demo, 1-4 en frontend MediaVault. Se localizaron todos los puntos de render y handlers que abren WhatsApp o hacen fallback a mailto/admin_email.

## What worked

Buscar por mailto/wa.me/openUpgradeDialog permitió identificar que no hay envío backend con wp_mail para upgrades, sino enlaces client-side. Se confirmó que jpsm_whatsapp_number ya existe en settings y se inyecta en MV_USER_DATA.

## What failed

El grep amplio inicial devolvió demasiado ruido y ocultó señales clave; fue necesario acotar al módulo mediavault y revisar lineas exactas de template-vault.php y mediavault-client.js.

## Next time

En cambios de CTAs de acceso, empezar por un inventario de handlers (event delegation), métodos de contacto y puntos de render dinámico/estático. Eliminar fallbacks inconsistentes (mailto) en un helper único para evitar divergencias por nivel o vista.
