<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-jpsm-data-layer.php';

final class DataLayerFolderDemandTest extends TestCase
{
    public function testNormalizeFolderEventPathCanonicalizesAndAddsTrailingSlash(): void
    {
        $method = new ReflectionMethod('JPSM_Data_Layer', 'normalize_folder_event_path');
        $method->setAccessible(true);

        $this->assertSame('music/rock/', $method->invoke(null, '/music//rock///'));
        $this->assertSame('', $method->invoke(null, '///'));
    }

    public function testFolderEventNameFromPathUsesLastSegment(): void
    {
        $method = new ReflectionMethod('JPSM_Data_Layer', 'folder_event_name_from_path');
        $method->setAccessible(true);

        $this->assertSame('pack', $method->invoke(null, 'seasonal/pack/'));
        $this->assertSame('', $method->invoke(null, ''));
    }

    public function testRangeMethodsExistForLegacyFallback(): void
    {
        $this->assertTrue(method_exists('JPSM_Data_Layer', 'get_top_folder_downloads_by_range'));
        $this->assertTrue(method_exists('JPSM_Data_Layer', 'get_folder_download_totals_by_range'));
        $this->assertTrue(method_exists('JPSM_Data_Layer', 'get_behavior_transfer_unique_folder_count'));
    }
}

