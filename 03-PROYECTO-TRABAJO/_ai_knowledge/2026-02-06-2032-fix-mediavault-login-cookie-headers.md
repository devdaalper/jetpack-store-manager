# Fix: MediaVault login cookie headers

- Date: 2026-02-06 20:32

## Context

Bug: MediaVault login/logout/guest intentaban setear cookie/redirect desde el render (template fullscreen) cuando ya se habia emitido HTML; Set-Cookie no salia y la sesion quedaba invalida. Fix: procesar esas acciones en template_redirect (antes de output) en mediavault/loader.php.

## What worked

Hook en template_redirect (priority 0) para: invitado=1, action=mv_logout y POST jdd_login+jdd_email. En exito: set_access_cookie() + wp_safe_redirect() y exit. Con wp-now se verifico 302 + Set-Cookie jdd_access_token y luego mv_search_global devuelve resultados con nonce valido.

## What failed

Intentar arreglarlo via POST dentro del template render_login_form no funciona cuando el HTML ya empezo (headers sent).

## Next time

Si se quiere mejorar UX/seguridad: agregar nonce al form de login y manejar errores via query arg o transient; tambien considerar unificar esta logica dentro de una clase servicio para no meter closures en loader.php (manteniendo el ambito protegido).
