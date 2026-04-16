<?php

use PHPUnit\Framework\TestCase;

require_once jpsm_test_plugin_root() . '/includes/class-jpsm-config.php';

final class ConfigValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        update_option('jpsm_cloudflare_domain', '');
    }

    public function testValidateB2ConfigAllowsMixedCaseBucket(): void
    {
        $cfg = array(
            'key_id' => 'test_key_id',
            'app_key' => 'test_app_key',
            'bucket' => 'Recursos-JetPackStore',
            'region' => 'us-east-005',
        );

        $res = JPSM_Config::validate_b2_config($cfg);
        $this->assertTrue($res);
    }

    public function testValidateB2ConfigRejectsBucketWithUnsafeChars(): void
    {
        $cfg = array(
            'key_id' => 'test_key_id',
            'app_key' => 'test_app_key',
            'bucket' => 'bad/bucket',
            'region' => 'us-east-005',
        );

        $res = JPSM_Config::validate_b2_config($cfg);
        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('invalid_bucket', $res->get_error_code());
    }

    public function testNormalizeCloudflareDomainAddsHttpsAndCanonicalizesHost(): void
    {
        $normalized = JPSM_Config::normalize_cloudflare_domain('Downloads.Example.com:8443');
        $this->assertSame('https://downloads.example.com:8443', $normalized);
    }

    public function testNormalizeCloudflareDomainRejectsInvalidOrigins(): void
    {
        $this->assertSame('', JPSM_Config::normalize_cloudflare_domain('http://downloads.example.com'));
        $this->assertSame('', JPSM_Config::normalize_cloudflare_domain('https://downloads.example.com/path'));
        $this->assertSame('', JPSM_Config::normalize_cloudflare_domain('https://downloads.example.com?x=1'));
        $this->assertSame('', JPSM_Config::normalize_cloudflare_domain('https://user:pass@downloads.example.com'));
    }

    public function testRewriteDownloadUrlForCloudflareReplacesOnlyOrigin(): void
    {
        update_option('jpsm_cloudflare_domain', 'https://edge.example.workers.dev');

        $source = 'https://s3.us-west-004.backblazeb2.com/mybucket/folder/file.mp3?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=abc123&response-content-disposition=attachment';
        $rewritten = JPSM_Config::rewrite_download_url_for_cloudflare($source);

        $this->assertSame(
            'https://edge.example.workers.dev/mybucket/folder/file.mp3?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=abc123&response-content-disposition=attachment',
            $rewritten
        );
    }

    public function testRewriteDownloadUrlForCloudflareFallsBackWhenDomainMissing(): void
    {
        update_option('jpsm_cloudflare_domain', '');

        $source = 'https://s3.us-west-004.backblazeb2.com/mybucket/file.mp3?X-Amz-Signature=abc123';
        $this->assertSame($source, JPSM_Config::rewrite_download_url_for_cloudflare($source));
    }
}
