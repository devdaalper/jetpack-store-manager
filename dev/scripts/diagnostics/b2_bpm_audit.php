#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Backblaze B2 BPM metadata audit (Native API v2).
 *
 * Usage:
 *   php scripts/diagnostics/b2_bpm_audit.php \
 *     --key-id "<keyId>" \
 *     --app-key "<appKey>" \
 *     --bucket "<bucketName>" \
 *     --sample 200
 *
 * Optional:
 *   --prefix "Music/"
 *   --max-pages 50
 *   --examples 10
 *   --json
 *
 * Env fallback:
 *   B2_KEY_ID, B2_APP_KEY, B2_BUCKET
 */

const DEFAULT_SAMPLE = 200;
const DEFAULT_MAX_PAGES = 50;
const DEFAULT_EXAMPLES = 10;
const MIN_BPM = 40.0;
const MAX_BPM = 260.0;

main($argv);

function main(array $argv): void
{
    $opts = parse_args($argv);
    if (!empty($opts['help'])) {
        print_help();
        exit(0);
    }

    $key_id = (string) ($opts['key-id'] ?? getenv('B2_KEY_ID') ?: '');
    $app_key = (string) ($opts['app-key'] ?? getenv('B2_APP_KEY') ?: '');
    $bucket_name = (string) ($opts['bucket'] ?? getenv('B2_BUCKET') ?: '');
    $prefix = (string) ($opts['prefix'] ?? '');
    $sample_target = max(1, intval($opts['sample'] ?? DEFAULT_SAMPLE));
    $max_pages = max(1, intval($opts['max-pages'] ?? DEFAULT_MAX_PAGES));
    $max_examples = max(1, intval($opts['examples'] ?? DEFAULT_EXAMPLES));
    $json_mode = array_key_exists('json', $opts);

    if ($key_id === '' || $app_key === '' || $bucket_name === '') {
        fail("Missing required credentials/options. Provide --key-id, --app-key, --bucket (or env vars).");
    }

    $auth = b2_authorize_account($key_id, $app_key);
    $bucket_id = resolve_bucket_id($auth, $bucket_name);

    $result = audit_bpm_coverage(
        $auth,
        $bucket_name,
        $bucket_id,
        $prefix,
        $sample_target,
        $max_pages,
        $max_examples
    );

    if ($json_mode) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    print_summary($result);
}

function parse_args(array $argv): array
{
    $out = array();
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $raw = (string) $argv[$i];
        if ($raw === '--help' || $raw === '-h') {
            $out['help'] = true;
            continue;
        }
        if ($raw === '--json') {
            $out['json'] = true;
            continue;
        }
        if (substr($raw, 0, 2) !== '--') {
            continue;
        }

        $eq = strpos($raw, '=');
        if ($eq !== false) {
            $key = substr($raw, 2, $eq - 2);
            $value = substr($raw, $eq + 1);
            $out[$key] = $value;
            continue;
        }

        $key = substr($raw, 2);
        $next = ($i + 1 < $count) ? (string) $argv[$i + 1] : '';
        if ($next !== '' && substr($next, 0, 2) !== '--') {
            $out[$key] = $next;
            $i++;
            continue;
        }
        $out[$key] = true;
    }
    return $out;
}

function print_help(): void
{
    $help = <<<TXT
Backblaze B2 BPM metadata audit

Required:
  --key-id <value>      B2 Key ID (or env B2_KEY_ID)
  --app-key <value>     B2 Application Key (or env B2_APP_KEY)
  --bucket <value>      Bucket name (or env B2_BUCKET)

Optional:
  --prefix <value>      Prefix/folder filter
  --sample <n>          Target audio files to inspect (default: 200)
  --max-pages <n>       Max b2_list_file_names pages to scan (default: 50)
  --examples <n>        Max examples to print (default: 10)
  --json                Print JSON result
  --help                Show this help
TXT;
    echo $help . PHP_EOL;
}

function audit_bpm_coverage(array $auth, string $bucket_name, string $bucket_id, string $prefix, int $sample_target, int $max_pages, int $max_examples): array
{
    $api_url = (string) $auth['apiUrl'];
    $auth_token = (string) $auth['authorizationToken'];
    $start_file_name = null;

    $scanned_files = 0;
    $scanned_audio = 0;
    $audio_with_bpm = 0;
    $bpm_values = array();
    $source_counts = array(
        'file_info' => 0,
        'filename' => 0,
    );
    $fileinfo_key_counts = array();
    $examples = array();
    $pages = 0;
    $truncated_by_pages = false;

    while ($pages < $max_pages && $scanned_audio < $sample_target) {
        $pages++;
        $remaining = max(1, $sample_target - $scanned_audio);
        $page_size = max(100, min(1000, $remaining * 3));

        $payload = array(
            'bucketId' => $bucket_id,
            'maxFileCount' => $page_size,
        );
        if ($prefix !== '') {
            $payload['prefix'] = $prefix;
        }
        if ($start_file_name !== null && $start_file_name !== '') {
            $payload['startFileName'] = $start_file_name;
        }

        $page = b2_api_post_json($api_url, '/b2api/v2/b2_list_file_names', $auth_token, $payload);
        $files = (array) ($page['files'] ?? array());
        if (empty($files)) {
            break;
        }

        foreach ($files as $file) {
            $scanned_files++;

            if (!is_array($file) || (string) ($file['action'] ?? 'upload') !== 'upload') {
                continue;
            }

            $file_name = (string) ($file['fileName'] ?? '');
            $content_type = strtolower((string) ($file['contentType'] ?? ''));
            if (!is_audio_object($file_name, $content_type)) {
                continue;
            }

            $scanned_audio++;
            $file_info = is_array($file['fileInfo'] ?? null) ? $file['fileInfo'] : array();
            foreach ($file_info as $k => $v) {
                $key = strtolower((string) $k);
                if ($key === '') {
                    continue;
                }
                $fileinfo_key_counts[$key] = intval($fileinfo_key_counts[$key] ?? 0) + 1;
            }

            $detected = detect_bpm((string) $file_name, $file_info);
            if ($detected !== null) {
                $audio_with_bpm++;
                $bpm_values[] = $detected['bpm'];
                $source = (string) $detected['source'];
                $source_counts[$source] = intval($source_counts[$source] ?? 0) + 1;

                if (count($examples) < $max_examples) {
                    $examples[] = array(
                        'file_name' => $file_name,
                        'bpm' => $detected['bpm'],
                        'source' => $source,
                        'key' => $detected['key'] ?? '',
                        'raw' => $detected['raw'] ?? '',
                    );
                }
            }

            if ($scanned_audio >= $sample_target) {
                break;
            }
        }

        $start_file_name = isset($page['nextFileName']) ? (string) $page['nextFileName'] : '';
        if ($start_file_name === '') {
            break;
        }
    }

    if ($pages >= $max_pages && $scanned_audio < $sample_target) {
        $truncated_by_pages = true;
    }

    arsort($fileinfo_key_counts);
    $top_keys = array_slice($fileinfo_key_counts, 0, 20, true);

    $coverage_pct = ($scanned_audio > 0)
        ? round(($audio_with_bpm / $scanned_audio) * 100, 2)
        : 0.0;

    return array(
        'scanned_at_utc' => gmdate('c'),
        'bucket' => $bucket_name,
        'prefix' => $prefix,
        'sample_target_audio' => $sample_target,
        'scan_limits' => array(
            'max_pages' => $max_pages,
            'pages_scanned' => $pages,
            'truncated_by_max_pages' => $truncated_by_pages,
        ),
        'counts' => array(
            'files_scanned_total' => $scanned_files,
            'audio_scanned' => $scanned_audio,
            'audio_with_bpm' => $audio_with_bpm,
            'audio_without_bpm' => max(0, $scanned_audio - $audio_with_bpm),
            'bpm_coverage_percent' => $coverage_pct,
        ),
        'bpm_stats' => summarize_bpm_values($bpm_values),
        'source_breakdown' => $source_counts,
        'top_file_info_keys_on_audio' => $top_keys,
        'examples' => $examples,
    );
}

function summarize_bpm_values(array $values): array
{
    if (empty($values)) {
        return array(
            'count' => 0,
            'min' => null,
            'max' => null,
            'avg' => null,
        );
    }

    $count = count($values);
    $sum = 0.0;
    $min = $values[0];
    $max = $values[0];
    foreach ($values as $v) {
        $f = floatval($v);
        $sum += $f;
        if ($f < $min) {
            $min = $f;
        }
        if ($f > $max) {
            $max = $f;
        }
    }

    return array(
        'count' => $count,
        'min' => round($min, 2),
        'max' => round($max, 2),
        'avg' => round($sum / $count, 2),
    );
}

function detect_bpm(string $file_name, array $file_info): ?array
{
    $preferred_keys = array(
        'bpm',
        'tempo',
        'tbpm',
        'track_bpm',
        'beats_per_minute',
        'x-amz-meta-bpm',
        'x-amz-meta-tempo',
        'x-bz-info-bpm',
        'x-bz-info-tempo',
    );

    $lower_map = array();
    foreach ($file_info as $k => $v) {
        $lower_map[strtolower((string) $k)] = (string) $v;
    }

    foreach ($preferred_keys as $key) {
        if (!array_key_exists($key, $lower_map)) {
            continue;
        }
        $raw = (string) $lower_map[$key];
        $bpm = parse_bpm_number($raw);
        if ($bpm !== null) {
            return array(
                'bpm' => $bpm,
                'source' => 'file_info',
                'key' => $key,
                'raw' => $raw,
            );
        }
    }

    // Fallback: any fileInfo key that looks bpm/tempo-ish.
    foreach ($lower_map as $k => $raw) {
        if (strpos($k, 'bpm') === false && strpos($k, 'tempo') === false) {
            continue;
        }
        $bpm = parse_bpm_number((string) $raw);
        if ($bpm !== null) {
            return array(
                'bpm' => $bpm,
                'source' => 'file_info',
                'key' => $k,
                'raw' => $raw,
            );
        }
    }

    // Last fallback: filename pattern like "..._128bpm.mp3".
    if (preg_match('/(?:^|[^0-9])([4-9][0-9]|1[0-9]{2}|2[0-5][0-9])\s*bpm(?:[^0-9]|$)/i', $file_name, $m)) {
        $bpm = parse_bpm_number((string) ($m[1] ?? ''));
        if ($bpm !== null) {
            return array(
                'bpm' => $bpm,
                'source' => 'filename',
                'key' => 'filename_pattern',
                'raw' => (string) $m[0],
            );
        }
    }

    return null;
}

function parse_bpm_number(string $raw): ?float
{
    if (preg_match('/([0-9]{2,3}(?:\\.[0-9]+)?)/', $raw, $m)) {
        $v = floatval($m[1]);
        if ($v >= MIN_BPM && $v <= MAX_BPM) {
            return round($v, 2);
        }
    }
    return null;
}

function is_audio_object(string $file_name, string $content_type): bool
{
    $audio_exts = array(
        'mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac', 'aif', 'aiff', 'alac', 'wma',
    );
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, $audio_exts, true)) {
        return true;
    }
    return strpos($content_type, 'audio/') === 0;
}

function resolve_bucket_id(array $auth, string $bucket_name): string
{
    $payload = array('accountId' => (string) $auth['accountId']);
    $res = b2_api_post_json((string) $auth['apiUrl'], '/b2api/v2/b2_list_buckets', (string) $auth['authorizationToken'], $payload);
    $buckets = (array) ($res['buckets'] ?? array());
    foreach ($buckets as $bucket) {
        if (!is_array($bucket)) {
            continue;
        }
        $name = (string) ($bucket['bucketName'] ?? '');
        if ($name === $bucket_name) {
            return (string) ($bucket['bucketId'] ?? '');
        }
    }
    fail("Bucket not found in account: {$bucket_name}");
    return '';
}

function b2_authorize_account(string $key_id, string $app_key): array
{
    $url = 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account';
    $auth_header = 'Authorization: Basic ' . base64_encode($key_id . ':' . $app_key);
    $res = http_json('GET', $url, array($auth_header), null);
    $status = intval($res['status']);
    if ($status !== 200) {
        $msg = b2_error_message((array) ($res['json'] ?? array()), $res['body']);
        fail("b2_authorize_account failed ({$status}): {$msg}");
    }
    return (array) $res['json'];
}

function b2_api_post_json(string $api_url, string $path, string $auth_token, array $payload): array
{
    $url = rtrim($api_url, '/') . $path;
    $headers = array(
        'Authorization: ' . $auth_token,
        'Content-Type: application/json',
    );
    $body = json_encode($payload);
    $res = http_json('POST', $url, $headers, $body !== false ? $body : '{}');
    $status = intval($res['status']);
    if ($status !== 200) {
        $msg = b2_error_message((array) ($res['json'] ?? array()), $res['body']);
        fail("B2 API call failed {$path} ({$status}): {$msg}");
    }
    return (array) $res['json'];
}

function http_json(string $method, string $url, array $headers, ?string $body): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        fail('Failed to initialize curl.');
    }

    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
    );
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    if ($errno !== 0) {
        fail("HTTP request failed: {$error}");
    }

    $response_body = is_string($raw) ? $raw : '';
    $json = json_decode($response_body, true);

    return array(
        'status' => $status,
        'body' => $response_body,
        'json' => is_array($json) ? $json : array(),
    );
}

function b2_error_message(array $json, string $raw): string
{
    $code = (string) ($json['code'] ?? '');
    $msg = (string) ($json['message'] ?? '');
    if ($code !== '' || $msg !== '') {
        return trim($code . (($code !== '' && $msg !== '') ? ': ' : '') . $msg);
    }
    $trim = trim($raw);
    if ($trim !== '') {
        return substr($trim, 0, 200);
    }
    return 'Unknown error';
}

function print_summary(array $result): void
{
    $counts = (array) ($result['counts'] ?? array());
    $stats = (array) ($result['bpm_stats'] ?? array());
    $sources = (array) ($result['source_breakdown'] ?? array());
    $keys = (array) ($result['top_file_info_keys_on_audio'] ?? array());
    $examples = (array) ($result['examples'] ?? array());

    echo 'B2 BPM Audit' . PHP_EOL;
    echo '-----------' . PHP_EOL;
    echo 'Bucket: ' . (string) ($result['bucket'] ?? '') . PHP_EOL;
    echo 'Prefix: ' . ((string) ($result['prefix'] ?? '') !== '' ? (string) $result['prefix'] : '(all)') . PHP_EOL;
    echo 'Audio scanned: ' . intval($counts['audio_scanned'] ?? 0) . PHP_EOL;
    echo 'Audio with BPM: ' . intval($counts['audio_with_bpm'] ?? 0) . PHP_EOL;
    echo 'Coverage: ' . number_format(floatval($counts['bpm_coverage_percent'] ?? 0), 2) . '%' . PHP_EOL;
    echo 'BPM stats (detected): '
        . 'min=' . value_or_dash($stats['min'] ?? null)
        . ', max=' . value_or_dash($stats['max'] ?? null)
        . ', avg=' . value_or_dash($stats['avg'] ?? null)
        . PHP_EOL;
    echo PHP_EOL;

    echo 'Source breakdown:' . PHP_EOL;
    foreach ($sources as $k => $v) {
        echo '  - ' . $k . ': ' . intval($v) . PHP_EOL;
    }
    echo PHP_EOL;

    echo 'Top fileInfo keys (audio objects):' . PHP_EOL;
    $printed = 0;
    foreach ($keys as $k => $v) {
        echo '  - ' . $k . ': ' . intval($v) . PHP_EOL;
        $printed++;
        if ($printed >= 10) {
            break;
        }
    }
    if ($printed === 0) {
        echo '  - (none)' . PHP_EOL;
    }
    echo PHP_EOL;

    echo 'Examples:' . PHP_EOL;
    if (empty($examples)) {
        echo '  - (no BPM found in sample)' . PHP_EOL;
        return;
    }
    foreach ($examples as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '  - ' . (string) ($row['file_name'] ?? '')
            . ' | bpm=' . value_or_dash($row['bpm'] ?? null)
            . ' | source=' . (string) ($row['source'] ?? '')
            . ' | key=' . (string) ($row['key'] ?? '')
            . PHP_EOL;
    }
}

function value_or_dash($v): string
{
    if ($v === null || $v === '') {
        return '-';
    }
    return (string) $v;
}

function fail(string $message): void
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}
