<?php
/**
 * Template Name: MediaVault Fullscreen
 * Description: A raw HTML wrapper for MediaVault that bypasses the active theme's header/footer.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Ensure the class is loaded
if (!class_exists('JPSM_MediaVault_UI')) {
    require_once plugin_dir_path(__FILE__) . 'template-vault.php';
}

JPSM_MediaVault_UI::render_full_page();
