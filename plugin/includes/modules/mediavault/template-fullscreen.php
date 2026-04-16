<?php
/**
 * Template Name: MediaVault Fullscreen
 * Description: A raw HTML wrapper for MediaVault that bypasses the active theme's header/footer.
 */

if (!defined('ABSPATH')) {
    exit;
}

use JetpackStore\MediaVault\UI;

// AJAX folder navigation must return clean JSON (no HTML wrapper), otherwise fetch().json() fails.
if (isset($_GET['mv_ajax']) && $_GET['mv_ajax'] == '1') {
    echo UI::render();
    return;
}

UI::render_full_page();
