<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-jpsm-data-layer.php';

final class DataLayerTransferTest extends TestCase
{
    public function testNormalizeBehaviorEventRowClampsTransferBytes(): void
    {
        $method = new ReflectionMethod('JPSM_Data_Layer', 'normalize_behavior_event_row');
        $method->setAccessible(true);

        $row = $method->invoke(null, array(
            'event_name' => 'download_folder_completed',
            'bytes_authorized' => '4096.7',
            'bytes_observed' => '-17',
            'object_type' => 'folder',
            'object_path_norm' => 'music/pack/',
        ));

        $this->assertSame(4096, intval($row['bytes_authorized'] ?? -1));
        $this->assertSame(0, intval($row['bytes_observed'] ?? -1));
    }

    public function testNormalizeBehaviorEventRowDefaultsTransferBytesToZero(): void
    {
        $method = new ReflectionMethod('JPSM_Data_Layer', 'normalize_behavior_event_row');
        $method->setAccessible(true);

        $row = $method->invoke(null, array(
            'event_name' => 'preview_direct_opened',
            'object_type' => 'file',
            'object_path_norm' => 'video/demo.mp4',
        ));

        $this->assertSame(0, intval($row['bytes_authorized'] ?? -1));
        $this->assertSame(0, intval($row['bytes_observed'] ?? -1));
    }
}

