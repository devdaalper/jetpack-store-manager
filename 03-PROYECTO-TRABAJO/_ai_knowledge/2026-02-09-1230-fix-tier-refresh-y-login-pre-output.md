# Fix: Tier Refresh + Login Pre-Output (MediaVault)

## Problema
- Usuarios no veian cambios de permisos/tier en MediaVault despues de actualizar el nivel desde el panel "Gestionar Usuarios".
- En algunos entornos, el login de MediaVault fallaba silenciosamente: se enviaba el POST, pero la sesion no quedaba (cookie no se seteaba) por "headers already sent".
- `composer integration` fallaba porque `mv_ajax` devolvia JSON contaminado por warnings (variable no definida), rompiendo el parseo.

## Causas Raiz
- `mv_ajax` incluia `remaining_plays` antes de inicializarlo, lo cual podia emitir notices/warnings y romper el JSON.
- La UI dependia de `this.userData.tier > 0` para habilitar botones de descarga, aun cuando el backend ya calculaba `can_download`.
- El template `render_full_page()` emite HTML antes de que se procese login/guest/logout (cookies/redirects). Cuando se intenta `setcookie()/wp_safe_redirect()` ya hubo output, por lo que el navegador no recibe `Set-Cookie`.
- El panel "Gestionar Usuarios" tenia tiers desactualizados (0..3) mientras el modelo real es 0..5.

## Fix Aplicado
- `includes/modules/mediavault/template-vault.php`
  - Definir `$remaining_plays` temprano (antes de armar el payload `mv_ajax`) para mantener JSON puro.
  - Mover flujos sensibles a headers (guest `invitado=1`, logout, login POST) a un handler `handle_pre_output_requests()` ejecutado al inicio de `render_full_page()`.
- `includes/modules/mediavault/assets/js/mediavault-client.js`
  - Respetar permisos server-side (`can_download`) para renderizar botones de descarga y refrescar tier desde `mv_ajax`.
  - Extender selector de tiers del admin a 0..5 (Demo, Basico, VIP Basico, VIP Videos, VIP Pelis, Full).

## Validacion
- `composer test`, `composer integration`, `composer release:verify` en verde.

## Lecciones
- Cualquier endpoint que devuelva JSON debe evitar variables no definidas y cualquier output (warnings/echo/HTML) antes de `wp_send_json`/`send_success`.
- En templates "fullscreen" que imprimen HTML, manejar login/logout/guest ANTES del primer byte de output (o via `template_redirect`) es obligatorio; de lo contrario los cookies/redirects fallan de forma intermitente segun buffering.

