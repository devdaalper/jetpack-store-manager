---
name: Sistema de Ventas Protegido
description: Instrucciones para verificar/modificar la estructura del Sistema de Ventas y la clase JPSM_Sales.
---

# Sistema de Ventas Protegido

La lógica del Sistema de Ventas ha sido modularizada y movida fuera de `class-jpsm-admin.php` hacia `class-jpsm-sales.php`.

## Protecciones Nucleares (Guardrails)

1.  **Prohibición de Monolitos**:
    - **NUNCA** vuelva a añadir manejadores AJAX relacionados con Ventas en `class-jpsm-admin.php`.
    - **NUNCA** ponga `process_sale_ajax`, `delete_log_ajax`, `resend_email_ajax` o lógica de estadísticas en `JPSM_Admin`.
    - Todas las nuevas funcionalidades de ventas/estadísticas DEBEN ir en `JPSM_Sales` o en una nueva clase dedicada.

2.  **Control de Acceso**:
    - `JPSM_Sales` tiene su propio método `verify_access()` para asegurar su autonomía. No lo elimine.
    - Éste acepta: Usuario Administrador (`manage_options`), Clave Secreta (`$_REQUEST['key']`) o Galleta (Cookie) de Sesión.

3.  **Estructura de Archivos**:
    - **`includes/class-jpsm-sales.php`**: Contiene la clase `JPSM_Sales`.
        - `process_sale_ajax`: Maneja nuevas ventas.
        - `process_sale`: Lógica central (envío de correo).
        - `get_history_ajax`: Obtiene los registros (logs).
        - `delete_*_ajax`: Gestión de registros.
        - `get_persistent_stats`: Estadísticas históricas.
    - **`includes/class-jpsm-admin.php`**:
        - En `run()`: Los hooks deben apuntar a `array('JPSM_Sales', 'method_name')`.
        - En `render_frontend_interface()`: Llamar a `JPSM_Sales::get_persistent_stats()` y `JPSM_Sales::get_entry_price()`.

## Reglas de Modificación

*   **Si modifica la lógica de correo**: Revise `JPSM_Sales::process_sale`.
*   **Si modifica las estadísticas**: Revise `JPSM_Sales::get_persistent_stats`.
*   **Si modifica el Panel de Administración**: Es posible que deba actualizar cómo `JPSM_Admin` llama a los métodos estáticos de `JPSM_Sales`.

## Verificación

Después de cualquier cambio en ventas:
1.  Pruebe una "Venta de Prueba" desde el frontend/admin.
2.  Verifique si el "Historial" se carga en el Panel de Administración.
3.  Verifique si "Reenviar Correo" funciona.
