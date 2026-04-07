# Hotfix minimo: upgrades solo por WhatsApp en MediaVault

- Date: 2026-02-23 00:12

## Context

Se aplicó un cambio mínimo para eliminar fallback a correo en CTAs de mayor acceso (demo y tiers 1-4) dentro de MediaVault, conservando el uso del número configurado en jpsm_whatsapp_number.

## What worked

Eliminar mailto en dos métodos JS y en el banner PHP fue suficiente para cubrir todos los botones porque convergen en openUpgradeDialog/openWhatsApp y banner de demo. La sección de WP para jpsm_whatsapp_number ya existe y quedó reutilizada.

## What failed

El worktree está altamente modificado, por lo que un git diff normal enmascara el hotfix y dificulta revisión visual rápida.

## Next time

En repos sucios, validar el cambio por búsquedas targeteadas (rg mailto/wa.me) y referencias de línea en lugar de revisar diff completo.
