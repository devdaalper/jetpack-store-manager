<?php
/**
 * Admin Settings for JetPack Drive Downloader.
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register settings.
 */
function jdd_register_settings()
{
    register_setting('jdd_options_group', 'jdd_google_api_key');
    register_setting('jdd_options_group', 'jdd_root_folder_id', 'jdd_sanitize_folder_id');
}
add_action('admin_init', 'jdd_register_settings');

/**
 * Sanitize Folder ID.
 */
function jdd_sanitize_folder_id($input)
{
    // Remove query parameters like ?usp=sharing
    $input = strtok($input, '?');
    return sanitize_text_field($input);
}

/**
 * Add settings page to menu.
 */
function jdd_add_options_page()
{
    add_options_page(
        'JetPack Drive Downloader',
        'JetPack Drive',
        'manage_options',
        'jetpack-drive-downloader',
        'jdd_options_page_html'
    );
}
add_action('admin_menu', 'jdd_add_options_page');

/**
 * Settings page HTML.
 */
function jdd_options_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('jdd_options_group');
            do_settings_sections('jdd_options_group');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Google Drive API Key</th>
                    <td>
                        <input type="text" name="jdd_google_api_key"
                            value="<?php echo esc_attr(get_option('jdd_google_api_key')); ?>" class="regular-text" />
                        <p class="description">Enter your Google Cloud API Key with Google Drive API enabled.</p>
                    </td>
                </tr>
				<tr valign="top">
					<th scope="row">Root Folder ID</th>
					<td>
						<input type="text" name="jdd_root_folder_id" value="1rFhbaG-mcsDhfNdDKB_ch6gXQaxMhZ1g" class="regular-text" readonly disabled />
						<p class="description">Este ID está fijo por seguridad. Para cambiarlo, contacta al desarrollador.</p>
					</td>
				</tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
