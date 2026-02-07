<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Custom Post Type and Taxonomy.
 */
function jdd_register_catalog_cpt()
{
    register_post_type('jdd_catalog_item', array(
        'labels' => array(
            'name' => 'Catálogo Drive',
            'singular_name' => 'Item de Catálogo',
            'add_new' => 'Añadir Nuevo Item',
            'add_new_item' => 'Añadir Nuevo Item de Catálogo',
            'edit_item' => 'Editar Item',
        ),
        'public' => false,
        'show_ui' => true,
        'supports' => array('title'),
        'menu_icon' => 'dashicons-grid-view',
        'menu_position' => 31,
    ));

    register_taxonomy('jdd_tag', 'jdd_catalog_item', array(
        'labels' => array(
            'name' => 'Etiquetas JDD',
            'singular_name' => 'Etiqueta',
            'edit_item' => 'Editar Etiqueta',
            'update_item' => 'Actualizar Etiqueta',
            'add_new_item' => 'Añadir Nueva Etiqueta',
            'new_item_name' => 'Nombre de la nueva etiqueta',
            'menu_name' => 'Etiquetas',
        ),
        'hierarchical' => false, // Like tags, not categories
        'show_ui' => true,
        'show_admin_column' => true,
        'query_var' => true,
    ));
}
add_action('init', 'jdd_register_catalog_cpt');

/**
 * Add Meta Boxes.
 */
function jdd_add_meta_boxes()
{
    add_meta_box(
        'jdd_drive_meta',
        'Información de la Carpeta Drive',
        'jdd_render_meta_box',
        'jdd_catalog_item',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'jdd_add_meta_boxes');

/**
 * Render Meta Box.
 */
function jdd_render_meta_box($post)
{
    $folder_id = get_post_meta($post->ID, '_jdd_drive_folder_id', true);
    $image1 = get_post_meta($post->ID, '_jdd_image_1', true);
    $image2 = get_post_meta($post->ID, '_jdd_image_2', true);
    ?>
    <!-- Media Uploader Scripts -->
    <?php wp_enqueue_media(); ?>
    <script>
        jQuery(document).ready(function ($) {
            function setupUploader(btnId, inputId, previewId) {
                $(btnId).click(function (e) {
                    e.preventDefault();
                    var uploader = wp.media({
                        title: 'Seleccionar Imagen',
                        button: { text: 'Usar esta imagen' },
                        multiple: false
                    }).on('select', function () {
                        var attachment = uploader.state().get('selection').first().toJSON();
                        $(inputId).val(attachment.url);
                        $(previewId).attr('src', attachment.url).show();
                    }).open();
                });
            }
            setupUploader('#upload_image_1_btn', '#jdd_image_1', '#preview_image_1');
            setupUploader('#upload_image_2_btn', '#jdd_image_2', '#preview_image_2');
        });
    </script>

    <p>
        <label for="jdd_drive_folder_id"><strong>ID de Carpeta en Drive:</strong></label><br>
        <input type="text" id="jdd_drive_folder_id" name="jdd_drive_folder_id" value="<?php echo esc_attr($folder_id); ?>"
            style="width:100%;" placeholder="Ej: 1rFhbaG-mcsDhfNdDKB...">
        <span class="description">Copia y pega el ID de la carpeta de Google Drive que corresponde a este item.</span>
    </p>

    <div style="display:flex; gap:20px; margin-top:20px;">
        <div style="flex:1;">
            <label><strong>Imagen Destacada 1 (Cuadrada):</strong></label><br>
            <img id="preview_image_1" src="<?php echo esc_attr($image1); ?>"
                style="max-width:100%; height:auto; margin:10px 0; border:1px solid #ddd; <?php echo empty($image1) ? 'display:none;' : ''; ?>">
            <input type="text" id="jdd_image_1" name="jdd_image_1" value="<?php echo esc_attr($image1); ?>"
                style="width:100%; margin-bottom:5px;">
            <button class="button" id="upload_image_1_btn">Subir Imagen 1</button>
        </div>
        <div style="flex:1;">
            <label><strong>Imagen Destacada 2 (Cuadrada):</strong></label><br>
            <img id="preview_image_2" src="<?php echo esc_attr($image2); ?>"
                style="max-width:100%; height:auto; margin:10px 0; border:1px solid #ddd; <?php echo empty($image2) ? 'display:none;' : ''; ?>">
            <input type="text" id="jdd_image_2" name="jdd_image_2" value="<?php echo esc_attr($image2); ?>"
                style="width:100%; margin-bottom:5px;">
            <button class="button" id="upload_image_2_btn">Subir Imagen 2</button>
        </div>
    </div>
    <?php
}

/**
 * Save Meta Box Data.
 */
function jdd_save_meta_box_data($post_id)
{
    if (array_key_exists('jdd_drive_folder_id', $_POST)) {
        update_post_meta($post_id, '_jdd_drive_folder_id', sanitize_text_field($_POST['jdd_drive_folder_id']));
    }
    if (array_key_exists('jdd_image_1', $_POST)) {
        update_post_meta($post_id, '_jdd_image_1', esc_url_raw($_POST['jdd_image_1']));
    }
    if (array_key_exists('jdd_image_2', $_POST)) {
        update_post_meta($post_id, '_jdd_image_2', esc_url_raw($_POST['jdd_image_2']));
    }
}
add_action('save_post', 'jdd_save_meta_box_data');
