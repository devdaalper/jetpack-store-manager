<?php
namespace JetpackStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for packages, tiers, templates and pricing.
 */
class Domain_Model
{
    /**
     * Package registry keyed by canonical package id.
     */
    public static function get_package_registry()
    {
        static $registry = null;
        if ($registry !== null) {
            return $registry;
        }

        $registry = array(
            'basic' => array(
                'id' => 'basic',
                'label' => 'Básico',
                'tier' => 1,
                'stats_bucket' => 'basic',
                'template_option' => 'jpsm_email_template_basic',
                'price_options' => array(
                    'mxn' => 'jpsm_price_mxn_basic',
                    'usd' => '',
                ),
                'settings_price_currencies' => array('mxn'),
                'aliases' => array(
                    'basic',
                    'basico',
                    'básico',
                    'paquete basico',
                    'paquete básico',
                ),
            ),
            'vip_videos' => array(
                'id' => 'vip_videos',
                'label' => 'VIP + Videos',
                'tier' => 3,
                'stats_bucket' => 'vip',
                'template_option' => 'jpsm_email_template_vip_videos',
                'price_options' => array(
                    'mxn' => 'jpsm_price_mxn_vip_videos',
                    'usd' => 'jpsm_price_usd_vip_videos',
                ),
                'settings_price_currencies' => array('mxn', 'usd'),
                'aliases' => array(
                    'vip videos',
                    'vip + videos',
                    'vip + videos musicales',
                ),
            ),
            'vip_pelis' => array(
                'id' => 'vip_pelis',
                'label' => 'VIP + Películas',
                'tier' => 4,
                'stats_bucket' => 'vip',
                'template_option' => 'jpsm_email_template_vip_pelis',
                'price_options' => array(
                    'mxn' => 'jpsm_price_mxn_vip_pelis',
                    'usd' => 'jpsm_price_usd_vip_pelis',
                ),
                'settings_price_currencies' => array('mxn', 'usd'),
                'aliases' => array(
                    'vip pelis',
                    'vip + pelis',
                    'vip peliculas',
                    'vip + peliculas',
                    'vip películas',
                    'vip + películas',
                ),
            ),
            'vip_basic' => array(
                'id' => 'vip_basic',
                'label' => 'VIP + Básico',
                'tier' => 2,
                'stats_bucket' => 'vip',
                'template_option' => 'jpsm_email_template_vip_basic',
                'price_options' => array(
                    'mxn' => 'jpsm_price_mxn_vip_basic',
                    'usd' => 'jpsm_price_usd_vip_basic',
                ),
                'settings_price_currencies' => array('mxn', 'usd'),
                'aliases' => array(
                    'vip basico',
                    'vip + basico',
                    'vip básico',
                    'vip + básico',
                ),
            ),
            'full' => array(
                'id' => 'full',
                'label' => 'Full',
                'tier' => 5,
                'stats_bucket' => 'full',
                'template_option' => 'jpsm_email_template_full',
                'price_options' => array(
                    'mxn' => 'jpsm_price_mxn_full',
                    'usd' => 'jpsm_price_usd_full',
                ),
                'settings_price_currencies' => array('mxn', 'usd'),
                'aliases' => array(
                    'full',
                    'active',
                    'paquete full',
                ),
            ),
        );

        return $registry;
    }

    /**
     * Registry order used in settings pages.
     */
    public static function get_settings_packages()
    {
        $registry = self::get_package_registry();
        return array(
            $registry['basic'],
            $registry['vip_videos'],
            $registry['vip_pelis'],
            $registry['vip_basic'],
            $registry['full'],
        );
    }

    /**
     * Top-level options for the registration form.
     */
    public static function get_sale_package_options()
    {
        return array(
            array('id' => 'basic', 'label' => 'Básico', 'icon' => '📦'),
            array('id' => 'vip', 'label' => 'VIP', 'icon' => '⭐'),
            array('id' => 'full', 'label' => 'Full', 'icon' => '💎'),
        );
    }

    /**
     * VIP variant options for the registration form.
     */
    public static function get_vip_variant_options()
    {
        $registry = self::get_package_registry();
        return array(
            $registry['vip_videos'],
            $registry['vip_pelis'],
            $registry['vip_basic'],
        );
    }

    /**
     * Resolve sale package from top-level package + optional vip subtype.
     */
    public static function resolve_sale_package($package, $vip_subtype = '')
    {
        $package = sanitize_text_field((string) $package);
        $vip_subtype = sanitize_text_field((string) $vip_subtype);
        $normalized_package = self::normalize_package_id($package);

        if ($normalized_package === 'vip' || self::normalize_text($package) === 'vip') {
            $variant = self::normalize_package_id($vip_subtype);
            if (!self::is_vip_variant($variant)) {
                $variant = 'vip_videos';
            }
            $resolved = self::get_package($variant);
            return $resolved ? $resolved : null;
        }

        if ($normalized_package === '') {
            return null;
        }

        $resolved = self::get_package($normalized_package);
        return $resolved ? $resolved : null;
    }

    /**
     * Normalize package input (id/legacy label) to canonical id.
     */
    public static function normalize_package_id($value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        $registry = self::get_package_registry();
        if (isset($registry[$value])) {
            return $value;
        }

        $needle = self::normalize_text($value);
        if ($needle === '') {
            return '';
        }

        if ($needle === 'vip') {
            return 'vip';
        }

        foreach ($registry as $id => $package) {
            if (self::normalize_text($id) === $needle) {
                return $id;
            }
            if (self::normalize_text($package['label']) === $needle) {
                return $id;
            }
            if (!empty($package['aliases']) && is_array($package['aliases'])) {
                foreach ($package['aliases'] as $alias) {
                    if (self::normalize_text($alias) === $needle) {
                        return $id;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Return package record by canonical id.
     */
    public static function get_package($package_id)
    {
        $package_id = self::normalize_package_id($package_id);
        $registry = self::get_package_registry();
        return isset($registry[$package_id]) ? $registry[$package_id] : null;
    }

    /**
     * Return display label by canonical id/legacy value.
     */
    public static function get_package_label($package)
    {
        $record = self::get_package($package);
        if ($record) {
            return $record['label'];
        }
        return sanitize_text_field((string) $package);
    }

    /**
     * Return template option name for canonical id/legacy value.
     */
    public static function get_template_option($package)
    {
        $record = self::get_package($package);
        if ($record && !empty($record['template_option'])) {
            return $record['template_option'];
        }
        return 'jpsm_email_template_basic';
    }

    /**
     * Return price option name for canonical id/legacy value and currency.
     */
    public static function get_price_option($package, $currency)
    {
        $record = self::get_package($package);
        $currency = self::normalize_currency($currency);

        if (!$record) {
            return '';
        }

        $opt = isset($record['price_options'][$currency]) ? (string) $record['price_options'][$currency] : '';
        if ($opt !== '') {
            return $opt;
        }

        // Legacy fallback for packages without explicit currency option.
        return 'jpsm_price_' . $currency . '_' . $record['id'];
    }

    /**
     * Resolve tier numeric value from package id/label.
     */
    public static function get_package_tier($package)
    {
        $record = self::get_package($package);
        if ($record) {
            return intval($record['tier']);
        }
        return 1; // Preserve legacy default: unknown package -> basic.
    }

    /**
     * Resolve stats bucket from package id/label.
     */
    public static function get_stats_bucket($package)
    {
        $record = self::get_package($package);
        if ($record && !empty($record['stats_bucket'])) {
            return (string) $record['stats_bucket'];
        }
        return 'basic';
    }

    /**
     * Return stats bucket labels.
     */
    public static function get_stats_bucket_labels()
    {
        return array(
            'basic' => 'Básico',
            'vip' => 'VIP',
            'full' => 'Full',
        );
    }

    /**
     * Resolve entry price from domain registry.
     */
    public static function get_entry_price($entry)
    {
        if (!is_array($entry)) {
            $entry = array();
        }

        $package_value = isset($entry['package']) ? (string) $entry['package'] : '';
        $package_id = self::normalize_package_id($package_value);
        if ($package_id === '' || $package_id === 'vip') {
            $package_id = 'vip_videos';
        }

        $region = isset($entry['region']) ? strtolower((string) $entry['region']) : 'national';
        $currency = isset($entry['currency']) ? (string) $entry['currency'] : ($region === 'international' ? 'usd' : 'mxn');
        $currency = self::normalize_currency($currency);

        $option_name = self::get_price_option($package_id, $currency);
        if ($option_name === '') {
            return 0.0;
        }

        return floatval(get_option($option_name, 0));
    }

    /**
     * Tier name from value.
     */
    public static function get_tier_name($tier)
    {
        $map = array(
            0 => 'demo',
            1 => 'basic',
            2 => 'vip_basic',
            3 => 'vip_videos',
            4 => 'vip_pelis',
            5 => 'full',
        );
        $tier = intval($tier);
        return isset($map[$tier]) ? $map[$tier] : 'demo';
    }

    /**
     * Tier value from name.
     */
    public static function get_tier_value($name)
    {
        $name = self::normalize_text((string) $name);
        $map = array(
            'demo' => 0,
            'basic' => 1,
            'vip_basic' => 2,
            'vip_videos' => 3,
            'vip_pelis' => 4,
            'full' => 5,
            'vip' => 3,
        );
        return isset($map[$name]) ? intval($map[$name]) : 0;
    }

    /**
     * Tier options for admin UI.
     */
    public static function get_tier_options_for_ui()
    {
        return array(
            array('value' => 0, 'label' => 'Demo'),
            array('value' => 1, 'label' => 'Level 1: Básico'),
            array('value' => 2, 'label' => 'Level 2: VIP + Básico'),
            array('value' => 3, 'label' => 'Level 3: VIP + Videos'),
            array('value' => 4, 'label' => 'Level 4: VIP + Pelis'),
            array('value' => 5, 'label' => 'Level 5: Full'),
        );
    }

    /**
     * Normalize currency value.
     */
    public static function normalize_currency($currency)
    {
        $currency = strtolower(sanitize_text_field((string) $currency));
        if ($currency === 'usd') {
            return 'usd';
        }
        return 'mxn';
    }

    private static function is_vip_variant($package_id)
    {
        return in_array($package_id, array('vip_videos', 'vip_pelis', 'vip_basic'), true);
    }

    /**
     * Normalize text for robust alias matching.
     */
    private static function normalize_text($value)
    {
        $value = strtolower(remove_accents((string) $value));
        $value = str_replace('+', ' ', $value);
        $value = preg_replace('/[^a-z0-9_ ]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);
        return trim((string) $value);
    }
}

// Backward compatibility alias.
class_alias(Domain_Model::class, 'JPSM_Domain_Model');
