---
name: Estándares de Diseño de JetPack Store
description: Define el lenguaje de diseño global oficial, el diseño (layout) y la biblioteca de componentes para todo el proyecto JetPack Store Manager, basado en la arquitectura de Media Vault.
---

# Estándares de Diseño de JetPack Store

Este documento define el estilo visual, la arquitectura de componentes y los patrones de comportamiento para el proyecto **JetPack Store Manager**.
El **Media Vault (v2.0)** se considera la implementación de referencia ("Gold Standard"). Todos los nuevos módulos y elementos de la interfaz de usuario deben adherirse a estas directrices para asegurar la consistencia en toda la aplicación.

## 1. Tokens de Diseño Principales

### Colores (Variables CSS)
Todos los colores deben usar las variables CSS definidas. No escriba valores hexadecimales directamente en el código.

```css
:root {
    --mv-bg: #f8fafc;              /* Slate 50 */
    --mv-surface: #ffffff;         /* Blanco */
    --mv-surface-hover: #fff7ed;   /* Orange 50 - Fondo en estado hover */
    --mv-border: #e2e8f0;          /* Slate 200 */
    --mv-border-hover: #fb923c;    /* Orange 400 - Borde activo/hover */
    
    /* Branding */
    --mv-accent: #ea580c;          /* Orange 600 - Color de acción principal */
    --mv-accent-hover: #c2410c;    /* Orange 700 - Color de acción en hover */
    
    /* Estado */
    --mv-success: #16a34a;         /* Verde 600 */
    --mv-danger: #dc2626;          /* Rojo 600 */
    
    /* Tipografía */
    --mv-text: #0f172a;            /* Slate 900 - Texto principal */
    --mv-text-muted: #64748b;      /* Slate 500 - Texto secundario/meta */
}
```

### Tipografía
*   **Familia de Fuentes:** 'Inter', system-ui, -apple-system, sans-serif.
*   **Pesos:** 
    *   Regular (400) para el cuerpo del texto.
    *   Medium (500) para botones y elementos de navegación.
    *   Semi-Bold (600) para títulos de tarjetas (cards).
    *   Extra-Bold (800) para encabezados Hero.

### Espaciado y Radio (Radius)
*   **Radio de Borde (Border Radius):** 
    *   Tarjetas/Modales: `12px` o `16px`.
    *   Botones: `8px` (estándar) o `99px` (pills/acciones).
    *   Entradas (Inputs): `99px` (barras de búsqueda).
*   **Sombras (Shadows):** 
    *   Hover de Tarjeta: `0 4px 6px -1px rgba(0, 0, 0, 0.1)`.
    *   Modales: `0 25px 50px -12px rgba(0, 0, 0, 0.2)`.

---

## 2. Estructura del Diseño (Layout)

El Media Vault usa un **Diseño de Dos Paneles**: Barra lateral (Sidebar) + Contenido Principal.

### Barra Lateral (`.mv-sidebar`)
*   **Ancho:** Fijo de `260px`.
*   **Posición:** Sticky, `top: 0`, `height: 100vh`.
*   **Borde:** Borde derecho de `1px solid var(--mv-border)`.
*   **Comportamiento Móvil:** Cajón oculto (off-canvas). Requiere `.mv-mobile-toggle` y una capa superpuesta (overlay).

### Contenido Principal (`.mv-main-content`)
*   **Visualización:** Flex column.
*   **Fondo:** `var(--mv-bg)`.
*   **Encabezado Hero:** 
    *   Fondo degradado: `linear-gradient(180deg, rgba(59, 130, 246, 0.1) 0%, rgba(9, 9, 11, 0) 100%)`.
    *   Icono Grande: Cuadrado de 100x100px, fondo blanco, sombra.

### Barra de Herramientas Fija (`.jpsm-mv-toolbar`)
*   **Posición:** Sticky `top: 0`.
*   **Efecto:** `backdrop-filter: blur(8px)`, fondo blanco semitransparente.
*   **Contenidos:** Barra de búsqueda, pastillas de filtro (filter pills), selectores de vista.

---

## 3. Componentes de UI

### Tarjetas (`.jpsm-mv-card`)
La unidad central de la interfaz. Debe manejar tanto la vista de cuadrícula (Grid) como la de lista (List).

*   **Vista de Cuadrícula (Predeterminada):**
    *   Diseño vertical.
    *   Imagen de Portada: Relación de aspecto cuadrada (`aspect-ratio: 1`).
    *   Hover: Se eleva (`translate Y`), la sombra aumenta, el borde se vuelve naranja.
    *   Título: Máximo 2 líneas (`-webkit-line-clamp: 2`).

*   **Vista de Lista (Padre `.view-list`):**
    *   Diseño horizontal (`flex-direction: row`).
    *   Imagen de Portada: Miniatura pequeña (`48px`).
    *   Título: Una sola línea.
    *   Información Meta: Alineada a la derecha.

### Botones
*   **Primario/Explícito:** `.mv-btn-explicit`
    *   Fondo: Tinte azul claro (`rgba(59, 130, 246, 0.15)`) o color sólido de realce (Accent).
    *   Transición: All 0.2s.
    *   Hover: Color Accent sólido, texto blanco.
*   **Pastillas de Filtro:** `.mv-filter-pill`
    *   Predeterminado: Fondo blanco, borde gris.
    *   Activo: Fondo con tinte naranja, borde naranja, texto naranja.
*   **Botones de Descarga:** `.mv-folder-download-btn`
    *   Fondo blanco, borde gris, hover a superficie naranja.

### Barra de Búsqueda (`.jpsm-mv-search`)
*   Forma de pastilla completa (`border-radius: 99px`).
*   Icono posicionado absolutamente a la izquierda.
*   Estado de enfoque: Resplandor/borde naranja (`box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15)`).

### Administrador de Descargas
*   Panel ANCLADO en la parte inferior de la pantalla.
*   Minimizable.
*   Barras de progreso verdes para descargas activas.

---

## 4. Patrones de Interacción

1.  **Cambios de Estado:** 
    *   Usar transiciones CSS (`0.2s ease` o `0.3s cubic-bezier`).
    *   Nunca cambiar estados instantáneamente a menos que un requisito funcional lo dicte.
2.  **Contenido Bloqueado:**
    *   Añadir clase `.locked`.
    *   Visuales: Filtro de escala de grises, opacidad reducida.
    *   Acción: Reemplazado con una llamada a la acción (CTA) de "Desbloquear/Mejorar".
3.  **Modales:**
    *   Posicionamiento fijo `z-index: 10000`.
    *   Fondo oscuro (`rgba(0,0,0,0.85)`).
    *   Desenfoque de fondo (`blur(5px)`).

## 5. Responsividad Móvil

*   **Puntos de quiebre (Breakpoints):** `< 768px`.
*   **Ajustes:**
    *   La barra lateral se oculta fuera de la pantalla.
    *   Migas de pan (breadcrumbs) se desplazan horizontalmente (mask-image para efecto de desvanecimiento).
    *   La cuadrícula cambia a 1 o 2 columnas según el ancho mínimo.
    *   Los objetivos táctiles deben ser de al menos 44px.

---

## 6. Estrategia de Iconografía

Usar emojis para un reconocimiento visual rápido de los tipos de archivos, apoyados por conos SVG para acciones del sistema.

*   **Carpeta:** 📁 (o imagen de portada personalizada)
*   **Audio:** 🎵
*   **Video:** 🎬
*   **Imagen:** 🖼️
*   **Archivo:** 📦
