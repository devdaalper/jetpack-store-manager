<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/modules/mediavault/class-index-manager.php';

final class IndexManagerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = array();

        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public $is_mysql = true;

            public function db_server_info()
            {
                return '8.0.36';
            }
        };
    }

    public function testNormalizeSearchTextCleansAccentsAndPunctuation(): void
    {
        $normalized = JPSM_Index_Manager::normalize_search_text('  MÚSICA / DJ-SET_2026!!!  ');
        $this->assertSame('musica dj set 2026', $normalized);
    }

    public function testMediaKindDetectionCoversAudioVideoAndOther(): void
    {
        $method = new ReflectionMethod('JPSM_Index_Manager', 'detect_media_kind');
        $method->setAccessible(true);

        $this->assertSame('audio', $method->invoke(null, 'mp3'));
        $this->assertSame('video', $method->invoke(null, 'mp4'));
        $this->assertSame('other', $method->invoke(null, 'pdf'));
    }

    public function testActiveTablePointerControlsActiveAndInactiveNames(): void
    {
        $setActive = new ReflectionMethod('JPSM_Index_Manager', 'set_active_table_alias');
        $setActive->setAccessible(true);

        $setActive->invoke(null, 'shadow');

        $this->assertSame('shadow', JPSM_Index_Manager::get_active_table_alias());
        $this->assertSame('wp_jpsm_mediavault_index_shadow', JPSM_Index_Manager::get_table_name('active'));
        $this->assertSame('wp_jpsm_mediavault_index', JPSM_Index_Manager::get_table_name('inactive'));
    }

    public function testLongPathHashesAvoidLegacyPrefixCollisionRisk(): void
    {
        $normalizeKey = new ReflectionMethod('JPSM_Index_Manager', 'normalize_object_key');
        $normalizeKey->setAccessible(true);

        $shared = str_repeat('segment-', 30);
        $pathA = $normalizeKey->invoke(null, $shared . 'alpha/file-a.mp3');
        $pathB = $normalizeKey->invoke(null, $shared . 'beta/file-b.mp3');

        $this->assertNotSame('', $pathA);
        $this->assertNotSame('', $pathB);
        $this->assertNotSame($pathA, $pathB);
        $this->assertNotSame(md5($pathA), md5($pathB));
    }

    public function testInferBpmFromPathPattern(): void
    {
        $method = new ReflectionMethod('JPSM_Index_Manager', 'infer_bpm_from_text');
        $method->setAccessible(true);

        $bpm = $method->invoke(
            null,
            'Full Pack/100 Bpm Reggaeton/track.mp3',
            'track.mp3',
            'Full Pack/100 Bpm Reggaeton'
        );

        $this->assertSame(100, $bpm);
    }

    public function testNormalizeBpmRangeSwapsWhenInverted(): void
    {
        $method = new ReflectionMethod('JPSM_Index_Manager', 'normalize_bpm_range');
        $method->setAccessible(true);

        $range = $method->invoke(null, 140, 100);
        $this->assertSame(array(100, 140), $range);
    }

    public function testParseBpmNumberRespectsRangeBounds(): void
    {
        $method = new ReflectionMethod('JPSM_Index_Manager', 'parse_bpm_number');
        $method->setAccessible(true);

        $this->assertSame(128, $method->invoke(null, 'tempo=128'));
        $this->assertSame(0, $method->invoke(null, 'tempo=12'));
        $this->assertSame(0, $method->invoke(null, 'tempo=320'));
    }

    public function testExtractBpmFromMp3HeadReadsTbpmFrame(): void
    {
        $method = new ReflectionMethod('JPSM_Index_Manager', 'extract_bpm_from_mp3_head');
        $method->setAccessible(true);

        $frameData = "\x00" . '128'; // text encoding byte + value
        $frame = 'TBPM' . pack('N', strlen($frameData)) . "\x00\x00" . $frameData;
        $tagHeader = 'ID3' . chr(3) . chr(0) . chr(0) . "\x00\x00\x00\x0E";
        $blob = $tagHeader . $frame;

        $this->assertSame(128, $method->invoke(null, $blob));
    }

    public function testEstimateBpmFromPcmDetectsSyntheticPulseTempo(): void
    {
        $method = new ReflectionMethod('JPSM_Index_Manager', 'estimate_bpm_from_pcm');
        $method->setAccessible(true);

        $sampleRate = 11025;
        $targetBpm = 120;
        $seconds = 16;
        $pulseEvery = max(1, intval(round(($sampleRate * 60) / $targetBpm)));
        $totalSamples = $sampleRate * $seconds;

        $pcm = '';
        for ($i = 0; $i < $totalSamples; $i++) {
            $amp = (($i % $pulseEvery) < 120) ? 22000 : 0;
            $pcm .= pack('v', $amp);
        }

        $detected = intval($method->invoke(null, $pcm, $sampleRate));
        $this->assertGreaterThanOrEqual(115, $detected);
        $this->assertLessThanOrEqual(125, $detected);
    }

    public function testGetBearerTokenReadsRedirectAuthorizationHeader(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer desktop_token_123';
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['Authorization']);

        $method = new ReflectionMethod('JPSM_Index_Manager', 'get_bearer_token_from_request');
        $method->setAccessible(true);

        $token = $method->invoke(null);
        $this->assertSame('desktop_token_123', $token);

        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    public function testReadJsonRequestBodyFallsBackToPostPayloadJson(): void
    {
        $_POST = array(
            'payload_json' => json_encode(array(
                'batch_id' => 'batch_001',
                'profile' => 'balanced',
                'rows' => array(),
            )),
        );

        $method = new ReflectionMethod('JPSM_Index_Manager', 'read_json_request_body');
        $method->setAccessible(true);

        $payload = $method->invoke(null);
        $this->assertIsArray($payload);
        $this->assertSame('batch_001', $payload['batch_id']);

        $_POST = array();
    }

    public function testNormalizeDesktopProfileDefaultsToBalancedWhenInvalid(): void
    {
        $method = new ReflectionMethod('JPSM_Index_Manager', 'normalize_desktop_profile');
        $method->setAccessible(true);

        $this->assertSame('fast', $method->invoke(null, 'fast'));
        $this->assertSame('balanced', $method->invoke(null, 'unknown'));
    }
}
