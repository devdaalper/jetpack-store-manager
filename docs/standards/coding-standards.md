---
name: Estándares de Codificiación y Arquitectura
description: Directrices para mantener la modularidad, simplicidad y escalabilidad en el código de JetPack Store Manager.
---

# JetPack Store Manager - Estándares de Codificación

## 1. Filosofía Arquitectónica
Este proyecto sigue una estricta **Separación de Responsabilidades** para asegurar el mantenimiento y la escalabilidad. Todo el código nuevo debe adherirse a esta estructura.

### 🏛 El Patrón Controlador (`JPSM_Admin`)
- **Rol:** La clase `JPSM_Admin` (y controladores similares) actúa **solo** como un director de tráfico.
- **Responsabilidades:**
  - Registrar Hooks (`add_action`, `add_filter`).
  - Registrar Menús.
  - Encolar Scripts/Estilos.
  - Delegar la ejecución a clases especializadas.
- **⛔ PROHIBIDO:**
  - NO escribir lógica de negocio (ej. consultas a bases de datos, cálculo de estadísticas) dentro del Controlador.
  - NO renderizar HTML dentro del Controlador.
  - NO definir lógica de manejadores AJAX dentro del Controlador (delegar a un Manager).

### 🎨 La Capa de Vista (`*_Views`, `*_Dashboard`)
- **Rol:** Manejar toda la salida HTML y el renderizado de la UI.
- **Convención de Nombres:** `JPSM_Admin_Views`, `JPSM_Dashboard`.
- **Directrices:**
  - Los métodos deben ser `public static` para llamadas fáciles.
  - Mantener la lógica PHP al mínimo (solo bucles o condicionales simples para visualización).
  - Usar archivos de plantilla para páginas complejas si es posible.

### 🧠 La Capa de Lógica (`*_Manager`, `*_Sales`)
- **Rol:** Manejar la lógica de negocio, el procesamiento de datos y las interacciones con la base de datos.
- **Convención de Nombres:** `JPSM_Sales`, `JPSM_Access_Manager`, `JPSM_Index_Manager`.
- **Responsabilidades:**
  - Lógica de Callback AJAX (ej. `process_sale_ajax`).
  - Consultas a la Base de Datos (`get_option`, `wpdb`).
  - Verificaciones de Autenticación.
  - Llamadas a APIs Externas.

## 2. Modularidad y Estructura de Archivos
- **Clases Núcleo:** Ubicadas en `includes/` (ej. `class-jpsm-sales.php`).
- **Módulos de Funcionalidades:** Ubicados en `includes/modules/[nombre_funcionalidad]/` (ej. `mediavault`, `downloader`).
  - Cada módulo debe tener su propio cargador o manager.
  - Mantener los activos del módulo (JS/CSS) dentro de la carpeta del módulo si son específicos de esa funcionalidad.

## 3. Reglas de Escalabilidad
1.  **Métodos Estáticos:** Preferir métodos `public static` para utilidades y renderizado de vistas para evitar instanciaciones innecesarias.
2.  **Manejadores AJAX:** Siempre separar el registro del hook AJAX (Controlador) de la lógica del manejador (Manager).
3.  **Validación:** Siempre sanitizar las entradas (`sanitize_text_field`, `sanitize_email`) y verificar Nonces o Permisos al inicio de cualquier acción.

## 4. Ejemplo de Refactorización
**Antes (Mal):**
```php
class JPSM_Admin {
    public function render_page() {
        echo '<h1>Mi Página</h1>'; // HTML en el Controlador
        $data = $this->calculate_stats(); // Lógica en el Controlador
    }
}
```

**Después (Bien):**
```php
// Controlador
class JPSM_Admin {
    public function run() {
         add_menu_page(..., array('JPSM_Admin_Views', 'render_page'));
    }
}

// Vista
class JPSM_Admin_Views {
    public static function render_page() {
        $data = JPSM_Sales::get_stats(); // Obtener Datos
        echo '<h1>Mi Página</h1>'; // Renderizar
    }
}

// Lógica
class JPSM_Sales {
    public static function get_stats() { ... }
}
```
