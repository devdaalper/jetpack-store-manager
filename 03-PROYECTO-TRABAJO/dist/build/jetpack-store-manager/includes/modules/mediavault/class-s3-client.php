<?php
/**
 * Native S3 Client for Backblaze B2
 * Handles AWS Signature v4 and Presigned URLs
 * 
 * Rewritten from scratch for correct S3 v4 signature implementation.
 */

class JPSM_S3_Client
{
    private $access_key;
    private $secret_key;
    private $region;
    private $bucket;
    private $endpoint;

    public function __construct($access_key, $secret_key, $region, $bucket)
    {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->region = $region;
        $this->bucket = $bucket;
        $this->endpoint = "s3.{$region}.backblazeb2.com";
    }

    /**
     * Parse an S3 XML error response into a safe WP_Error.
     *
     * IMPORTANT: do not include raw response bodies to avoid leaking internal details.
     */
    private function make_s3_error($http_code, $body)
    {
        $http_code = intval($http_code);
        $err_code = '';
        $err_msg = '';

        if (is_string($body) && $body !== '') {
            $xml = @simplexml_load_string($body);
            if ($xml) {
                $node = isset($xml->Error) ? $xml->Error : $xml;
                if (isset($node->Code)) {
                    $err_code = trim((string) $node->Code);
                }
                if (isset($node->Message)) {
                    $err_msg = trim((string) $node->Message);
                }
            }
        }

        $msg = 'S3 Error ' . $http_code;
        if ($err_code !== '' || $err_msg !== '') {
            $suffix = trim($err_code . (($err_code !== '' && $err_msg !== '') ? ': ' : '') . $err_msg);
            if ($suffix !== '') {
                $msg .= ': ' . $suffix;
            }
        }

        $hint = $this->s3_error_hint($err_code, $http_code);
        if ($hint !== '') {
            $msg .= ' (' . $hint . ')';
        }

        return new WP_Error('s3_error', $msg, array(
            'http_status' => $http_code,
            's3_code' => $err_code,
        ));
    }

    /**
     * Human hint for common Backblaze B2 S3-compatible errors.
     */
    private function s3_error_hint($s3_code, $http_code)
    {
        $s3_code = strtoupper((string) $s3_code);
        switch ($s3_code) {
            case 'ACCESSDENIED':
                return "Permisos insuficientes: habilita 'List Files' y 'Read Files' en la Application Key para este bucket.";
            case 'INVALIDACCESSKEYID':
                return 'Key ID inválido: verifica que copiaste el Key ID correcto.';
            case 'SIGNATUREDOESNOTMATCH':
                return 'Firma inválida: verifica región, Key ID y Application Key (no invertidos).';
            case 'NOSUCHBUCKET':
                return 'Bucket inexistente: verifica el nombre del bucket y la región.';
            case 'AUTHORIZATIONHEADERMALFORMED':
                return 'Authorization inválido: revisa que la región configurada sea la correcta.';
        }

        if (intval($http_code) === 403) {
            return 'Acceso denegado: revisa permisos de la Application Key y bucket/región.';
        }

        return '';
    }

    /**
     * List objects in the bucket, supporting folders via delimiter
     */
    public function list_objects($prefix = '')
    {
        $results = [
            'folders' => [],
            'files' => []
        ];
        $continuation_token = null;

        do {
            $method = 'GET';
            $service = 's3';
            $host = $this->endpoint;
            // Bucket-level operations in path-style use "/<bucket>" (no trailing slash).
            // A trailing slash can cause signature validation issues on some S3-compatible providers.
            $uri = '/' . $this->bucket;

            // Query parameters
            $query_params = [
                'delimiter' => '/',
                'list-type' => '2',
                'prefix' => $prefix
            ];
            if ($continuation_token) {
                $query_params['continuation-token'] = $continuation_token;
            }
            ksort($query_params);
            $query_string = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

            // Timestamps
            $amz_date = gmdate('Ymd\THis\Z');
            $date_stamp = gmdate('Ymd');

            // Payload hash (empty body)
            $payload_hash = hash('sha256', '');

            // Headers to sign
            $headers = [
                'host' => $host,
                'x-amz-content-sha256' => $payload_hash,
                'x-amz-date' => $amz_date,
            ];
            ksort($headers);

            $canonical_headers = '';
            foreach ($headers as $key => $value) {
                $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            }
            $signed_headers = implode(';', array_keys($headers));

            $canonical_request = implode("\n", [
                $method,
                $uri,
                $query_string,
                $canonical_headers,
                $signed_headers,
                $payload_hash
            ]);

            $algorithm = 'AWS4-HMAC-SHA256';
            $credential_scope = "{$date_stamp}/{$this->region}/{$service}/aws4_request";
            $string_to_sign = implode("\n", [
                $algorithm,
                $amz_date,
                $credential_scope,
                hash('sha256', $canonical_request)
            ]);

            $signing_key = $this->get_signature_key($this->secret_key, $date_stamp, $this->region, $service);
            $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

            $authorization = "{$algorithm} Credential={$this->access_key}/{$credential_scope},SignedHeaders={$signed_headers},Signature={$signature}";

            $url = "https://{$host}{$uri}?{$query_string}";

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => $authorization,
                    'x-amz-content-sha256' => $payload_hash,
                    'x-amz-date' => $amz_date,
                    'Host' => $host
                ],
                'timeout' => 120
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($code !== 200) {
                return $this->make_s3_error($code, $body);
            }

            $xml = simplexml_load_string($body);
            if (!$xml) {
                return new WP_Error('xml_error', 'Could not parse B2 response');
            }

            // CommonPrefixes (Folders)
            if (isset($xml->CommonPrefixes)) {
                foreach ($xml->CommonPrefixes as $cp) {
                    $results['folders'][] = (string) $cp->Prefix;
                }
            }

            // Contents (Files)
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $key = (string) $content->Key;
                    if ($key === $prefix)
                        continue;

                    $results['files'][] = [
                        'name' => basename($key),
                        'path' => $key,
                        'size' => (int) $content->Size,
                        'date' => (string) $content->LastModified
                    ];
                }
            }

            // Check if there are more results
            $continuation_token = isset($xml->NextContinuationToken) ? (string) $xml->NextContinuationToken : null;

        } while ($continuation_token);

        return $results;
    }

    /**
     * Generate a presigned URL.
     * Uses Path-Style to avoid DNS issues (s3.region.backblazeb2.com/Bucket/File)
     *
     * Supported $options:
     * - response_content_disposition: "attachment", "inline", custom string, or null to omit.
     */
    public function get_presigned_url($file_path, $expires_in = 86400, $options = array())
    {
        $method = 'GET';
        $service = 's3';

        // Path Style: Host is generic, URI includes bucket
        $host = $this->endpoint;

        // Build URI path and encode each segment
        $path_segments = explode('/', $file_path);
        $encoded_segments = array_map('rawurlencode', $path_segments);
        $encoded_path = implode('/', $encoded_segments);

        // URI for signing (bucket is also URL encoded)
        $uri = '/' . rawurlencode($this->bucket) . '/' . ltrim($encoded_path, '/');

        // Timestamps
        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');

        // Credential scope
        $credential_scope = "{$date_stamp}/{$this->region}/{$service}/aws4_request";

        $options = is_array($options) ? $options : array();
        $filename = basename($file_path);
        $disposition_mode = isset($options['response_content_disposition'])
            ? $options['response_content_disposition']
            : 'attachment';
        $disposition = null;

        if (is_string($disposition_mode)) {
            $disposition_mode = trim($disposition_mode);
            if ($disposition_mode === 'attachment' || $disposition_mode === 'inline') {
                $disposition = $disposition_mode . '; filename="' . rawurlencode($filename) . '"';
            } elseif ($disposition_mode !== '') {
                $disposition = $disposition_mode;
            }
        }

        // Query parameters for presigned URL
        $query_params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->access_key}/{$credential_scope}",
            'X-Amz-Date' => $amz_date,
            'X-Amz-Expires' => $expires_in,
            'X-Amz-SignedHeaders' => 'host',
        ];
        if ($disposition !== null) {
            $query_params['response-content-disposition'] = $disposition;
        }
        ksort($query_params);
        $query_string = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

        // Canonical request
        $canonical_request = implode("\n", [
            $method,
            $uri,
            $query_string,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD'
        ]);

        // String to sign
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $credential_scope,
            hash('sha256', $canonical_request)
        ]);

        // Calculate signature
        $signing_key = $this->get_signature_key($this->secret_key, $date_stamp, $this->region, $service);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        // Add signature to query
        $query_params['X-Amz-Signature'] = $signature;
        $final_query = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

        return "https://{$host}{$uri}?{$final_query}";
    }

    /**
     * Enable CORS on the bucket to allow Browser fetch()
     */
    public function enable_cors()
    {
        $method = 'PUT';
        $uri = "/{$this->bucket}/";
        $host = $this->endpoint;

        $cors_config = '<CORSConfiguration>
             <CORSRule>
                 <AllowedOrigin>*</AllowedOrigin>
                 <AllowedMethod>GET</AllowedMethod>
                 <AllowedMethod>HEAD</AllowedMethod>
                 <MaxAgeSeconds>3000</MaxAgeSeconds>
                 <AllowedHeader>*</AllowedHeader>
                 <ExposeHeader>ETag</ExposeHeader>
             </CORSRule>
        </CORSConfiguration>';

        $payload_hash = hash('sha256', $cors_config);
        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');

        // Query param ?cors
        $query_params = ['cors' => ''];
        // Note: empty value params have tricky signing. usually 'cors=' in query string
        $query_string = 'cors=';

        // Headers
        $headers = [
            'content-length' => strlen($cors_config),
            'content-md5' => base64_encode(md5($cors_config, true)),
            'content-type' => 'application/xml',
            'host' => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $amz_date
        ];
        ksort($headers);

        $canonical_headers = '';
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
        }
        $signed_headers = implode(';', array_keys($headers));

        $canonical_request = implode("\n", [
            $method,
            $uri,
            $query_string,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        ]);

        $scope = "{$date_stamp}/{$this->region}/s3/aws4_request";
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $scope,
            hash('sha256', $canonical_request)
        ]);

        $signing_key = $this->get_signature_key($this->secret_key, $date_stamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$scope},SignedHeaders={$signed_headers},Signature={$signature}";

        $url = "https://{$host}{$uri}?cors";

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'body' => $cors_config,
            'headers' => [
                'Authorization' => $authorization,
                'x-amz-date' => $amz_date,
                'x-amz-content-sha256' => $payload_hash,
                'Content-Type' => 'application/xml',
                'Content-MD5' => $headers['content-md5']
            ]
        ]);

        return $response;
    }

    /**
     * Derive signing key
     */
    private function get_signature_key($key, $date_stamp, $region, $service)
    {
        $k_date = hash_hmac('sha256', $date_stamp, "AWS4" . $key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        return $k_signing;
    }

    /**
     * List all objects recursively (for batch operations)
     * Returns flat array of files: ['path' => ..., 'size' => ..., 'name' => ...]
     */
    public function list_objects_recursive($prefix)
    {
        $files = [];
        $continuation_token = null;

        // Ensure prefix ends in / if not empty
        if ($prefix && substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        do {
            $query_params = [
                'list-type' => '2',
                'prefix' => $prefix,
            ];

            if ($continuation_token) {
                $query_params['continuation-token'] = $continuation_token;
            }

            // Note: NO delimiter implies recursive listing in S3

            // Sort params strictly for signature
            ksort($query_params);
            $query_string = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

            // Timestamps
            $amz_date = gmdate('Ymd\THis\Z');
            $date_stamp = gmdate('Ymd');
            $payload_hash = hash('sha256', '');

            // Headers
            $headers = [
                'host' => $this->endpoint,
                'x-amz-content-sha256' => $payload_hash,
                'x-amz-date' => $amz_date,
            ];
            ksort($headers);

            // Canonical Headers
            $canonical_headers = '';
            foreach ($headers as $key => $value) {
                $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            }
            $signed_headers = implode(';', array_keys($headers));

            // Canonical Request
            $canonical_request = implode("\n", [
                'GET',
                '/' . $this->bucket,
                $query_string,
                $canonical_headers,
                $signed_headers,
                $payload_hash
            ]);

            // Sign
            $algorithm = 'AWS4-HMAC-SHA256';
            $credential_scope = "{$date_stamp}/{$this->region}/s3/aws4_request";
            $string_to_sign = implode("\n", [
                $algorithm,
                $amz_date,
                $credential_scope,
                hash('sha256', $canonical_request)
            ]);

            $signing_key = $this->get_signature_key($this->secret_key, $date_stamp, $this->region, 's3');
            $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

            $authorization = "{$algorithm} Credential={$this->access_key}/{$credential_scope},SignedHeaders={$signed_headers},Signature={$signature}";

            // Request
            $url = "https://{$this->endpoint}/{$this->bucket}?{$query_string}";

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => $authorization,
                    'x-amz-date' => $amz_date,
                    'x-amz-content-sha256' => $payload_hash,
                    'Host' => $this->endpoint,
                ],
                'timeout' => 120
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $body = wp_remote_retrieve_body($response);
                return $this->make_s3_error($code, $body);
            }

            $body = wp_remote_retrieve_body($response);

            // SimpleXML Parsing (Handles namespaces better)
            $xml = simplexml_load_string($body);
            if (!$xml) {
                return new WP_Error('xml_error', 'Could not parse B2 response');
            }

            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $key = (string) $content->Key;
                    $size = (int) $content->Size;

                    // Skip if it's a folder placeholder (B2 sends these sometimes)
                    if (substr($key, -1) === '/')
                        continue;

                    $files[] = [
                        'path' => $key,
                        'name' => basename($key),
                        'size' => $size
                    ];
                }
            }

            // Pagination
            $continuation_token = isset($xml->NextContinuationToken) ? (string) $xml->NextContinuationToken : null;

        } while ($continuation_token);

        return $files;
    }

    /**
     * List objects recursively - Single Page (for batch processing)
     * 
     * @param string $prefix
     * @param string|null $continuation_token
     * @return array|WP_Error ['files' => [], 'next_token' => string|null]
     */
    public function list_objects_page($prefix, $continuation_token = null, $max_keys = 1000)
    {
        $files = [];

        // Ensure prefix ends in / if not empty
        if ($prefix && substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        $max_keys = intval($max_keys);
        if ($max_keys < 1 || $max_keys > 1000) {
            $max_keys = 1000;
        }

        $query_params = [
            'list-type' => '2',
            'prefix' => $prefix,
            // Process up to 1000 files per batch (S3 limit). Lower values are useful for health checks.
            'max-keys' => (string) $max_keys
        ];

        if ($continuation_token) {
            $query_params['continuation-token'] = $continuation_token;
        }

        // Note: NO delimiter implies recursive listing in S3

        // Sort params strictly for signature
        ksort($query_params);
        $query_string = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

        // Timestamps
        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');
        $payload_hash = hash('sha256', '');

        // Headers
        $headers = [
            'host' => $this->endpoint,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $amz_date,
        ];
        ksort($headers);

        // Canonical Headers
        $canonical_headers = '';
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
        }
        $signed_headers = implode(';', array_keys($headers));

        // Canonical Request
        $canonical_request = implode("\n", [
            'GET',
            '/' . $this->bucket,
            $query_string,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        ]);

        // Sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$date_stamp}/{$this->region}/s3/aws4_request";
        $string_to_sign = implode("\n", [
            $algorithm,
            $amz_date,
            $credential_scope,
            hash('sha256', $canonical_request)
        ]);

        $signing_key = $this->get_signature_key($this->secret_key, $date_stamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        $authorization = "{$algorithm} Credential={$this->access_key}/{$credential_scope},SignedHeaders={$signed_headers},Signature={$signature}";

        // Request
        $url = "https://{$this->endpoint}/{$this->bucket}?{$query_string}";

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $authorization,
                'x-amz-date' => $amz_date,
                'x-amz-content-sha256' => $payload_hash,
                'Host' => $this->endpoint,
            ],
            'timeout' => 30 // 30s is enough for one page
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return $this->make_s3_error($code, $body);
        }

        $body = wp_remote_retrieve_body($response);

        // SimpleXML Parsing
        $xml = simplexml_load_string($body);
        if (!$xml) {
            return new WP_Error('xml_error', 'Could not parse B2 response');
        }

        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $content) {
                $key = (string) $content->Key;
                $size = (int) $content->Size;

                // Skip folders
                if (substr($key, -1) === '/')
                    continue;

                $files[] = [
                    'path' => $key,
                    'name' => basename($key),
                    'size' => $size
                ];
            }
        }

        $next_token = isset($xml->NextContinuationToken) ? (string) $xml->NextContinuationToken : null;

        return [
            'files' => $files,
            'next_token' => $next_token
        ];
    }
}
