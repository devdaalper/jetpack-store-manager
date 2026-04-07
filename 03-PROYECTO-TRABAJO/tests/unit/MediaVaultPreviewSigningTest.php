<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/01-WORDPRESS-SUBIR/jetpack-store-manager/includes/modules/mediavault/class-s3-client.php';

final class MediaVaultPreviewSigningTest extends TestCase
{
    public function testDownloadPresignedUrlKeepsAttachmentDisposition(): void
    {
        $client = new JPSM_S3_Client('key', 'secret', 'us-west-004', 'Recursos-JetPackStore');
        $url = $client->get_presigned_url('smoke/track.mp3', 1800);
        $params = $this->getQueryParams($url);

        $this->assertSame('attachment; filename="track.mp3"', $params['response-content-disposition'] ?? null);
        $this->assertArrayHasKey('X-Amz-Signature', $params);
    }

    public function testDirectPreviewPresignedUrlUsesInlineDisposition(): void
    {
        $client = new JPSM_S3_Client('key', 'secret', 'us-west-004', 'Recursos-JetPackStore');
        $url = $client->get_presigned_url('smoke/track.mp3', 1800, array(
            'response_content_disposition' => 'inline',
        ));
        $params = $this->getQueryParams($url);

        $this->assertSame('inline; filename="track.mp3"', $params['response-content-disposition'] ?? null);
        $this->assertArrayHasKey('X-Amz-Signature', $params);
    }

    /**
     * @return array<string, string>
     */
    private function getQueryParams($url): array
    {
        $query = parse_url((string) $url, PHP_URL_QUERY);
        $params = array();
        parse_str(is_string($query) ? $query : '', $params);
        return $params;
    }
}
