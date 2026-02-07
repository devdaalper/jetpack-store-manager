<?php
/**
 * Template Name: JetPack Full Width
 * Description: Plantilla de página de ancho completo para la interfaz de JetPack Store Manager.
 */

get_header(); ?>

<div id="jpsm-full-width-template" class="jpsm-full-width-container">
    <?php
    while (have_posts()):
        the_post();
        the_content();
    endwhile;
    ?>
</div>

<style>
    /* Forzar ancho completo en la mayoría de temas */
    #jpsm-full-width-template {
        width: 100vw !important;
        position: relative;
        left: 50%;
        right: 50%;
        margin-left: -50vw;
        margin-right: -50vw;
        padding: 0;
        overflow-x: hidden;
    }

    /* Ocultar elementos que suelen estorbar en ancho completo */
    .sidebar,
    #sidebar,
    .widget-area {
        display: none !important;
    }

    /* Ajuste específico para el contenedor de la app móvil */
    #jpsm-mobile-app {
        max-width: 100% !important;
        margin: 0 !important;
    }
</style>

<?php get_footer(); ?>