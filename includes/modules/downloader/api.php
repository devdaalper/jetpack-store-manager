<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register API Routes.
 */
function jdd_register_api_routes()
{
    register_rest_route('jdd/v1', '/catalog', array(
        'methods' => 'GET',
        'callback' => 'jdd_api_get_catalog',
        'permission_callback' => function () {
            return JPSM_Access_Manager::check_current_session();
        }
    ));

    register_rest_route('jdd/v1', '/track', array(
        'methods' => 'POST',
        'callback' => 'jdd_api_track_download',
        'permission_callback' => function () {
            return JPSM_Access_Manager::check_current_session();
        }
    ));

    register_rest_route('jdd/v1', '/sync', array(
        'methods' => 'POST',
        'callback' => 'jdd_api_sync_catalog',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        }
    ));
}
add_action('rest_api_init', 'jdd_register_api_routes');

/**
 * API: Sync Catalog Items from Drive.
 */
function jdd_api_sync_catalog($request)
{
    $params = $request->get_json_params();
    $items = isset($params['items']) ? $params['items'] : array();

    if (!is_array($items) || empty($items)) {
        return new WP_Error('invalid_params', 'Items list is required', array('status' => 400));
    }

    $processed_ids = array();
    $created_count = 0;

    foreach ($items as $item) {
        $folder_id = sanitize_text_field($item['id']);
        $folder_name = sanitize_text_field($item['name']);

        if (!$folder_id || !$folder_name) {
            continue;
        }

        $processed_ids[] = $folder_id;

        $args = array(
            'post_type' => 'jdd_catalog_item',
            'meta_key' => '_jdd_drive_folder_id',
            'meta_value' => $folder_id,
            'posts_per_page' => 1,
            'post_status' => 'any'
        );
        $exists = get_posts($args);

        if (!$exists) {
            $post_id = wp_insert_post(array(
                'post_title' => $folder_name,
                'post_type' => 'jdd_catalog_item',
                'post_status' => 'publish'
            ));

            if ($post_id && !is_wp_error($post_id)) {
                update_post_meta($post_id, '_jdd_drive_folder_id', $folder_id);
                $created_count++;
            }
        } else {
            if ($exists[0]->post_status === 'trash') {
                wp_untrash_post($exists[0]->ID);
            }
        }
    }

    $all_args = array(
        'post_type' => 'jdd_catalog_item',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'fields' => 'ids'
    );
    $all_posts = get_posts($all_args);

    $trashed_count = 0;
    foreach ($all_posts as $post_id) {
        $drive_id = get_post_meta($post_id, '_jdd_drive_folder_id', true);
        if ($drive_id && !in_array($drive_id, $processed_ids)) {
            wp_trash_post($post_id);
            $trashed_count++;
        }
    }

    return array(
        'success' => true,
        'created' => $created_count,
        'trashed' => $trashed_count,
        'processed' => count($processed_ids)
    );
}

/**
 * API: Get Catalog Metadata.
 */
function jdd_api_get_catalog()
{
    $args = array(
        'post_type' => 'jdd_catalog_item',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    );
    $posts = get_posts($args);

    $catalog = array();
    $all_tags = array();

    foreach ($posts as $post) {
        $folder_id = get_post_meta($post->ID, '_jdd_drive_folder_id', true);
        if (!$folder_id) {
            continue;
        }

        $image1 = get_post_meta($post->ID, '_jdd_image_1', true);
        $image2 = get_post_meta($post->ID, '_jdd_image_2', true);

        $terms = get_the_terms($post->ID, 'jdd_tag');
        $item_tags = array();
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $item_tags[] = $term->name;
                if (!isset($all_tags[$term->slug])) {
                    $all_tags[$term->slug] = $term->name;
                }
            }
        }

        $catalog[$folder_id] = array(
            'title' => $post->post_title,
            'image1' => $image1,
            'image2' => $image2,
            'tags' => $item_tags
        );
    }

    return array(
        'items' => $catalog,
        'available_tags' => $all_tags
    );
}

/**
 * API: Track Download.
 */
function jdd_api_track_download($request)
{
    $params = $request->get_json_params();
    $folder_id = isset($params['folder_id']) ? sanitize_text_field($params['folder_id']) : '';
    $folder_name = isset($params['folder_name']) ? sanitize_text_field($params['folder_name']) : '';

    if (!$folder_id) {
        return new WP_Error('missing_params', 'Folder ID is required', array('status' => 400));
    }

    jdd_track_download($folder_id, $folder_name);

    return array('success' => true);
}
