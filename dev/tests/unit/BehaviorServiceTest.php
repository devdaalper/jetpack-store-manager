<?php

use PHPUnit\Framework\TestCase;

require_once jpsm_test_plugin_root() . '/includes/class-jpsm-behavior-service.php';

final class BehaviorServiceTest extends TestCase
{
    public function testNormalizeSearchQueryRedactsSensitivePatterns(): void
    {
        $normalized = JPSM_Behavior_Service::normalize_search_query('  ROCK 2026 Juan@mail.com +52 (55) 1234-5678 !!! ');

        $this->assertSame('rock 2026 email phone', $normalized);
    }

    public function testNormalizeObjectPathCanonicalizesSlashes(): void
    {
        $this->assertSame('folder/sub/file.mp3', JPSM_Behavior_Service::normalize_object_path('/folder//sub/file.mp3'));
        $this->assertSame('folder/sub/', JPSM_Behavior_Service::normalize_object_path('folder/sub///'));
    }

    public function testHashIdentityIsStableAndNonEmpty(): void
    {
        $a = JPSM_Behavior_Service::hash_identity('Client@Example.com');
        $b = JPSM_Behavior_Service::hash_identity('client@example.com');
        $c = JPSM_Behavior_Service::hash_identity('another@example.com');

        $this->assertNotSame('', $a);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a));
    }

    public function testDetectDeviceClassFromUserAgent(): void
    {
        $this->assertSame('mobile', JPSM_Behavior_Service::detect_device_class('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'));
        $this->assertSame('tablet', JPSM_Behavior_Service::detect_device_class('Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X)'));
        $this->assertSame('desktop', JPSM_Behavior_Service::detect_device_class('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)'));
    }

    public function testSanitizeMonthUsesExpectedFormat(): void
    {
        $this->assertSame('2026-02', JPSM_Behavior_Service::sanitize_month('2026-02'));
        $fallback = JPSM_Behavior_Service::sanitize_month('bad-value');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $fallback);
    }

    public function testNormalizeTransferBytesClampsAndCasts(): void
    {
        $this->assertSame(0, JPSM_Behavior_Service::normalize_transfer_bytes(-100));
        $this->assertSame(0, JPSM_Behavior_Service::normalize_transfer_bytes(''));
        $this->assertSame(1024, JPSM_Behavior_Service::normalize_transfer_bytes('1024'));
        $this->assertSame(2048, JPSM_Behavior_Service::normalize_transfer_bytes('2048.99'));
    }

    public function testSanitizeTransferWindowFallsBackToMonth(): void
    {
        $this->assertSame('month', JPSM_Behavior_Service::sanitize_transfer_window('bad_window'));
        $this->assertSame('rolling_90d', JPSM_Behavior_Service::sanitize_transfer_window('rolling_90d'));
    }
}
