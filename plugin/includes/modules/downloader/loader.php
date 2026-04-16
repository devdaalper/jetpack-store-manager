<?php
if (!defined('JDD_VERSION')) {
    define('JDD_VERSION', time());
}
if (!defined('JDD_PLUGIN_DIR')) {
    define('JDD_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('JDD_PLUGIN_URL')) {
    define('JDD_PLUGIN_URL', plugin_dir_url(__FILE__));
}
// Include required module files.
require_once JDD_PLUGIN_DIR . 'class-access-manager.php';

die('Access Manager loaded OK');