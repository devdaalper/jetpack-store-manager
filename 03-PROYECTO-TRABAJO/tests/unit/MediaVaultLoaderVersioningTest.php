<?php

use PHPUnit\Framework\TestCase;

final class MediaVaultLoaderVersioningTest extends TestCase
{
    public function testMediaVaultLoaderUsesFileMtimeForClientVersion(): void
    {
        $loader = dirname(__DIR__, 3) . '/01-WORDPRESS-SUBIR/jetpack-store-manager/includes/modules/mediavault/loader.php';
        $source = file_get_contents($loader);

        $this->assertIsString($source);
        $this->assertStringContainsString('function jpsm_mv_client_asset_version()', $source);
        $this->assertStringContainsString("filemtime(\$client_file)", $source);
        $this->assertStringContainsString("\$ver = jpsm_mv_client_asset_version();", $source);
    }
}
