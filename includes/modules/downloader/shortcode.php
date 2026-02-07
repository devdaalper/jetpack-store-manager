<?php
/**
 * Shortcode for JetPack Drive Downloader.
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register shortcode.
 */
function jdd_shortcode()
{
    ob_start();
    ?>
    <div id="jdd-app-container">
        <div class="jdd-loading">Cargando Gestor de Descargas...</div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('jetpack_drive_downloader', 'jdd_shortcode');
