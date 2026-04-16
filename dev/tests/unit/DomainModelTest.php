<?php

use PHPUnit\Framework\TestCase;

require_once jpsm_test_plugin_root() . '/includes/class-jpsm-domain-model.php';

final class DomainModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__wp_options'] = array(
            'jpsm_price_mxn_basic' => 100,
            'jpsm_price_mxn_vip_videos' => 200,
            'jpsm_price_mxn_vip_pelis' => 300,
            'jpsm_price_mxn_vip_basic' => 150,
            'jpsm_price_mxn_full' => 500,
            'jpsm_price_usd_full' => 50,
        );
    }

    public function testNormalizePackageIdResolvesAliases(): void
    {
        $this->assertSame('basic', JPSM_Domain_Model::normalize_package_id('Básico'));
        $this->assertSame('basic', JPSM_Domain_Model::normalize_package_id('basico'));
        $this->assertSame('vip_pelis', JPSM_Domain_Model::normalize_package_id('VIP + Películas'));
        $this->assertSame('vip_pelis', JPSM_Domain_Model::normalize_package_id('vip peliculas'));
        $this->assertSame('vip', JPSM_Domain_Model::normalize_package_id('VIP'));
        $this->assertSame('full', JPSM_Domain_Model::normalize_package_id('active'));
    }

    public function testResolveSalePackageVipSubtypeDefaultsSafely(): void
    {
        $resolved = JPSM_Domain_Model::resolve_sale_package('vip', 'vip_pelis');
        $this->assertIsArray($resolved);
        $this->assertSame('vip_pelis', $resolved['id']);
        $this->assertSame('VIP + Películas', $resolved['label']);

        $fallback = JPSM_Domain_Model::resolve_sale_package('vip', 'not_a_variant');
        $this->assertIsArray($fallback);
        $this->assertSame('vip_videos', $fallback['id']);
    }

    public function testGetPriceOptionUsesRegistry(): void
    {
        $this->assertSame('jpsm_price_mxn_vip_pelis', JPSM_Domain_Model::get_price_option('vip_pelis', 'MXN'));
        $this->assertSame('jpsm_price_usd_full', JPSM_Domain_Model::get_price_option('full', 'usd'));
    }

    public function testGetEntryPriceUsesOptionAndCurrencyNormalization(): void
    {
        $price = JPSM_Domain_Model::get_entry_price(array(
            'package' => 'VIP + Películas',
            'region' => 'national',
        ));
        $this->assertSame(300.0, $price);

        $priceUsd = JPSM_Domain_Model::get_entry_price(array(
            'package' => 'Full',
            'region' => 'international',
            'currency' => 'USD',
        ));
        $this->assertSame(50.0, $priceUsd);
    }

    public function testTierAndStatsBucketMapping(): void
    {
        $this->assertSame(4, JPSM_Domain_Model::get_package_tier('VIP + Películas'));
        $this->assertSame('vip', JPSM_Domain_Model::get_stats_bucket('VIP + Películas'));
        $this->assertSame('full', JPSM_Domain_Model::get_stats_bucket('Full'));
        $this->assertSame('basic', JPSM_Domain_Model::get_stats_bucket('unknown'));
    }
}
