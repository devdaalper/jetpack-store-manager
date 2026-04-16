<?php

/**
 * Phase 6 - Integration checks for core endpoints in a temporary wp-now env.
 *
 * Requires:
 * - Node + npx
 * - PHP curl extension
 * - PHP sqlite3 extension
 *
 * Usage:
 *   composer integration
 *
 * Optional env vars:
 * - JPSM_WPNOW_PORT (default 8099)
 * - JPSM_WPNOW_WP   (default 6.9)
 */

// This is a test harness, not production code. Keep output clean on newer PHPs.
error_reporting(E_ALL & ~E_DEPRECATED);

function jpsm_it_log($line)
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function jpsm_it_fail($line)
{
    fwrite(STDERR, $line . PHP_EOL);
    exit(1);
}

function jpsm_it_wait_for_url($url, $timeout_seconds = 60)
{
    $start = time();

    while (true) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 500) {
            return true;
        }

        if (time() - $start > $timeout_seconds) {
            return false;
        }

        usleep(250000);
    }
}

function jpsm_it_http_request($method, $url, $data = null, $cookie_file = null, $follow = true)
{
    $response_headers = array();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, (bool) $follow);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$response_headers) {
        $len = strlen($header);
        $header = trim($header);
        if ($header !== '') {
            $response_headers[] = $header;
        }
        return $len;
    });

    if ($cookie_file) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    }

    $method = strtoupper((string) $method);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : (string) $data);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : (string) $data);
        }
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return array('status' => 0, 'headers' => $response_headers, 'body' => '', 'error' => $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array('status' => $status, 'headers' => $response_headers, 'body' => $body, 'error' => '');
}

function jpsm_it_extract_js_object($haystack, $var_name)
{
    $needle = 'var ' . $var_name . ' =';
    $pos = strpos($haystack, $needle);
    if ($pos === false) {
        return null;
    }

    $start = strpos($haystack, '{', $pos);
    if ($start === false) {
        return null;
    }

    $depth = 0;
    $len = strlen($haystack);
    for ($i = $start; $i < $len; $i++) {
        $ch = $haystack[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($haystack, $start, $i - $start + 1);
            }
        }
    }

    return null;
}

function jpsm_it_find_wpnow_db()
{
    $home = getenv('HOME');
    if (!$home) {
        return null;
    }

    // Prefer the expected folder name derived from the repo directory.
    $repo_basename = basename(dirname(__DIR__, 2));
    $paths = glob($home . '/.wp-now/wp-content/' . $repo_basename . '-*/database/.ht.sqlite');
    if (!$paths) {
        // Fallback: take the newest DB from any wp-now env (useful if wp-now changes naming).
        $paths = glob($home . '/.wp-now/wp-content/*/database/.ht.sqlite');
    }

    if (!$paths) {
        return null;
    }

    usort($paths, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    return $paths[0];
}

function jpsm_it_sqlite_exec(SQLite3 $db, $sql)
{
    $ok = $db->exec($sql);
    if (!$ok) {
        jpsm_it_fail('SQLite error: ' . $db->lastErrorMsg() . ' | SQL=' . $sql);
    }
}

function jpsm_it_bootstrap_sqlite($db_path)
{
    $db = new SQLite3($db_path);

    // Make admin login deterministic.
    $admin_md5 = md5('admin');
    jpsm_it_sqlite_exec(
        $db,
        "UPDATE wp_users SET user_pass='" . SQLite3::escapeString($admin_md5) . "' WHERE user_login='admin';"
    );

    // Ensure /?pagename=descargas contains the shortcode.
    jpsm_it_sqlite_exec(
        $db,
        "UPDATE wp_posts SET post_title='Descargas', post_name='descargas', post_content='[jpsm_media_vault]' WHERE ID=2;"
    );

    // Ensure index table exists (plugin normally creates it, but keep tests self-contained).
    jpsm_it_sqlite_exec(
        $db,
        "CREATE TABLE IF NOT EXISTS wp_jpsm_mediavault_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            path TEXT NOT NULL DEFAULT '',
            path_hash TEXT NOT NULL DEFAULT '',
            path_norm TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            name_norm TEXT NOT NULL DEFAULT '',
            folder TEXT NOT NULL DEFAULT '',
            folder_norm TEXT NOT NULL DEFAULT '',
            size INTEGER DEFAULT 0,
            extension TEXT DEFAULT '',
            media_kind TEXT DEFAULT 'other',
            bpm INTEGER DEFAULT 0,
            bpm_source TEXT DEFAULT '',
            synced_at TEXT DEFAULT CURRENT_TIMESTAMP
        );"
    );

    // Seed one search row.
    jpsm_it_sqlite_exec($db, "DELETE FROM wp_jpsm_mediavault_index;");
    jpsm_it_sqlite_exec(
        $db,
        "INSERT INTO wp_jpsm_mediavault_index (path, path_hash, path_norm, name, name_norm, folder, folder_norm, size, extension, media_kind, bpm, bpm_source, synced_at)
         VALUES ('smoke/smoke-track.mp3', lower(hex(randomblob(16))), 'smoke smoke track mp3', 'smoke-track.mp3', 'smoke track mp3', 'smoke', 'smoke', 123456, 'mp3', 'audio', 100, 'test_seed', datetime('now'));"
    );

    // Seed minimal (dummy) B2 config so MediaVault endpoints can exercise entitlement gates without real credentials.
    // Note: plugin constants are defined from options on each request.
    jpsm_it_sqlite_exec(
        $db,
        "INSERT OR REPLACE INTO wp_options (option_name, option_value, autoload) VALUES
            ('jpsm_b2_key_id', 'test_key_id', 'yes'),
            ('jpsm_b2_app_key', 'test_app_key', 'yes'),
            ('jpsm_b2_bucket', 'test-bucket', 'yes'),
            ('jpsm_b2_region', 'us-west-004', 'yes'),
            ('jpsm_cloudflare_domain', 'https://jpsm-downloads.example.workers.dev', 'yes');"
    );

    $db->close();
}

$project_root = realpath(__DIR__ . '/../..');
if (!$project_root) {
    jpsm_it_fail('Could not resolve project root');
}

$workspace_root = realpath($project_root . '/..');
if (!$workspace_root) {
    jpsm_it_fail('Could not resolve workspace root');
}

$plugin_root = realpath($workspace_root . '/01-WORDPRESS-SUBIR/jetpack-store-manager');
if (!$plugin_root || !is_file($plugin_root . '/jetpack-store-manager.php')) {
    jpsm_it_fail('Could not resolve plugin root at 01-WORDPRESS-SUBIR/jetpack-store-manager');
}

$port = (int) (getenv('JPSM_WPNOW_PORT') ?: 8099);
$wp_version = (string) (getenv('JPSM_WPNOW_WP') ?: '6.9');
$base_url = 'http://localhost:' . $port;

$cmd = 'npx -y @wp-now/wp-now start --path ' . escapeshellarg($plugin_root)
    . ' --port ' . $port
    . ' --skip-browser --wp=' . escapeshellarg($wp_version)
    . ' --reset';

jpsm_it_log('[integration] Starting wp-now...');
$proc = proc_open(
    $cmd,
    array(
        0 => array('pipe', 'r'),
        1 => array('file', '/dev/null', 'w'),
        2 => array('file', '/dev/null', 'w'),
    ),
    $pipes,
    $plugin_root
);

if (!is_resource($proc)) {
    jpsm_it_fail('Failed to start wp-now');
}

register_shutdown_function(function () use ($proc, $pipes) {
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    @proc_terminate($proc);
});

if (!jpsm_it_wait_for_url($base_url . '/', 90)) {
    jpsm_it_fail('wp-now did not become ready on ' . $base_url);
}

$db_path = jpsm_it_find_wpnow_db();
if (!$db_path || !file_exists($db_path)) {
    jpsm_it_fail('Could not locate wp-now SQLite DB');
}

jpsm_it_log('[integration] Bootstrapping SQLite...');
jpsm_it_bootstrap_sqlite($db_path);

$cookie_file = tempnam(sys_get_temp_dir(), 'jpsm_it_');
if (!$cookie_file) {
    jpsm_it_fail('Could not create cookie jar');
}

jpsm_it_log('[integration] Logging into WP admin...');
$login = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-login.php',
    array(
        'log' => 'admin',
        'pwd' => 'admin',
        'wp-submit' => 'Log In',
        'redirect_to' => $base_url . '/wp-admin/',
        'testcookie' => '1',
    ),
    $cookie_file,
    true
);

if ($login['status'] < 200 || $login['status'] >= 400) {
    jpsm_it_fail('WP login failed, status=' . $login['status'] . ' error=' . $login['error']);
}

$dashboard = jpsm_it_http_request(
    'GET',
    $base_url . '/wp-admin/admin.php?page=jetpack-store-manager',
    null,
    $cookie_file,
    true
);
if ($dashboard['status'] !== 200) {
    jpsm_it_fail('Dashboard did not render, status=' . $dashboard['status'] . ' plugin_root=' . $plugin_root);
}
if (strpos($dashboard['body'], 'jpsm-mobile-app') === false) {
    jpsm_it_fail('Dashboard HTML missing jpsm-mobile-app');
}

$jpsm_vars_json = jpsm_it_extract_js_object($dashboard['body'], 'jpsm_vars');
if (!$jpsm_vars_json) {
    jpsm_it_fail('Could not extract jpsm_vars from dashboard HTML');
}

$jpsm_vars = json_decode($jpsm_vars_json, true);
if (!is_array($jpsm_vars)) {
    jpsm_it_fail('Could not decode jpsm_vars JSON');
}

$nonces = isset($jpsm_vars['nonces']) && is_array($jpsm_vars['nonces']) ? $jpsm_vars['nonces'] : array();
$sales_nonce = $nonces['sales'] ?? '';
$access_nonce = $nonces['access'] ?? '';
$index_nonce = $nonces['index'] ?? '';
$mv_nonce = $nonces['mediavault'] ?? '';

if ($sales_nonce === '' || $access_nonce === '' || $index_nonce === '' || $mv_nonce === '') {
    jpsm_it_fail('Missing one or more required nonces from jpsm_vars');
}

jpsm_it_log('[integration] B2 connectivity test...');
$b2_test = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php',
    array(
        'action' => 'jpsm_test_b2_connection',
        'nonce' => $index_nonce,
    ),
    $cookie_file,
    true
);

if ($b2_test['status'] === 0) {
    jpsm_it_fail('test_b2_connection request failed: ' . $b2_test['error']);
}

$b2_test_json = json_decode($b2_test['body'], true);
if (!is_array($b2_test_json) || !array_key_exists('success', $b2_test_json)) {
    jpsm_it_fail('test_b2_connection did not return expected JSON');
}

jpsm_it_log('[integration] process_sale + history...');
$email = 'buyer-integration@example.com';
$process_sale = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php',
    array(
        'action' => 'jpsm_process_sale',
        'nonce' => $sales_nonce,
        'client_email' => $email,
        'package_type' => 'vip',
        'vip_subtype' => 'vip_pelis',
        'region' => 'national',
    ),
    $cookie_file,
    true
);

$sale_json = json_decode($process_sale['body'], true);
if (!is_array($sale_json) || !array_key_exists('success', $sale_json)) {
    jpsm_it_fail('process_sale did not return expected JSON');
}

$history = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php',
    array(
        'action' => 'jpsm_get_history',
        'nonce' => $sales_nonce,
        'api_version' => '2',
    ),
    $cookie_file,
    true
);

$history_json = json_decode($history['body'], true);
if (!is_array($history_json) || empty($history_json['success']) || empty($history_json['data']['ok'])) {
    jpsm_it_fail('get_history (api_version=2) did not return v2 envelope');
}

$log = $history_json['data']['data'] ?? array();
if (!is_array($log)) {
    jpsm_it_fail('get_history v2 payload missing data list');
}

$entry_id = '';
foreach ($log as $entry) {
    if (is_array($entry) && ($entry['email'] ?? '') === $email) {
        $entry_id = (string) ($entry['id'] ?? '');
        break;
    }
}
if ($entry_id === '') {
    jpsm_it_fail('Sale entry not found in history for ' . $email);
}

jpsm_it_log('[integration] resend_email...');
$resend = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php',
    array(
        'action' => 'jpsm_resend_email',
        'nonce' => $sales_nonce,
        'id' => $entry_id,
    ),
    $cookie_file,
    true
);

$resend_json = json_decode($resend['body'], true);
if (!is_array($resend_json) || !array_key_exists('success', $resend_json)) {
    jpsm_it_fail('resend_email did not return expected JSON');
}

jpsm_it_log('[integration] get_user_tier...');
$tier = jpsm_it_http_request(
    'GET',
    $base_url . '/wp-admin/admin-ajax.php?' . http_build_query(array(
        'action' => 'jpsm_get_user_tier',
        'nonce' => $access_nonce,
        'email' => $email,
    )),
    null,
    $cookie_file,
    true
);

$tier_json = json_decode($tier['body'], true);
if (!is_array($tier_json) || empty($tier_json['success']) || empty($tier_json['data']['tier'])) {
    jpsm_it_fail('get_user_tier did not return expected payload');
}

jpsm_it_log('[integration] MediaVault login (cookie) + search...');
$mv_login = jpsm_it_http_request(
    'POST',
    $base_url . '/?pagename=descargas',
    array(
        'jdd_login' => '1',
        'jdd_email' => $email,
    ),
    $cookie_file,
    false
);

if ($mv_login['status'] !== 302) {
    jpsm_it_fail('MediaVault login expected 302 redirect, got ' . $mv_login['status']);
}

$mv_ajax = jpsm_it_http_request(
    'GET',
    $base_url . '/?' . http_build_query(array(
        'pagename' => 'descargas',
        'mv_ajax' => '1',
        'folder' => 'smoke/',
        'nonce' => $mv_nonce,
    )),
    null,
    $cookie_file,
    true
);

$mv_ajax_json = json_decode($mv_ajax['body'], true);
if (!is_array($mv_ajax_json) || empty($mv_ajax_json['success']) || empty($mv_ajax_json['data']['files'][0]['name'])) {
    jpsm_it_fail('MediaVault mv_ajax did not return expected folder listing payload');
}
if (isset($mv_ajax_json['data']['files'][0]['url'])) {
    jpsm_it_fail('MediaVault mv_ajax leaked a presigned url in browse payload');
}

$mv_search = jpsm_it_http_request(
    'GET',
    $base_url . '/wp-admin/admin-ajax.php?' . http_build_query(array(
        'action' => 'mv_search_global',
        'nonce' => $mv_nonce,
        'query' => 'smoke',
    )),
    null,
    $cookie_file,
    true
);

$mv_json = json_decode($mv_search['body'], true);
if (!is_array($mv_json) || empty($mv_json['success']) || empty($mv_json['data'][0]['name'])) {
    jpsm_it_fail('mv_search_global did not return seeded result');
}
if (isset($mv_json['data'][0]['url'])) {
    jpsm_it_fail('mv_search_global leaked a presigned url in search payload');
}

// Search v2 shape (items/meta/suggestions) should remain stable.
$mv_search_v2 = jpsm_it_http_request(
    'GET',
    $base_url . '/wp-admin/admin-ajax.php?' . http_build_query(array(
        'action' => 'mv_search_global',
        'nonce' => $mv_nonce,
        'api_version' => '2',
        'query' => 'smoke',
        'type' => 'audio',
        'limit' => 10,
        'offset' => 0,
    )),
    null,
    $cookie_file,
    true
);
$mv_v2_json = json_decode($mv_search_v2['body'], true);
if (!is_array($mv_v2_json) || empty($mv_v2_json['success'])) {
    jpsm_it_fail('mv_search_global v2 did not return success payload');
}
$mv_v2_data = $mv_v2_json['data'] ?? null;
if (is_array($mv_v2_data) && isset($mv_v2_data['ok']) && isset($mv_v2_data['data']) && is_array($mv_v2_data['data'])) {
    // API v2 envelope.
    $mv_v2_data = $mv_v2_data['data'];
}
if (!is_array($mv_v2_data) || !isset($mv_v2_data['items']) || !isset($mv_v2_data['meta']) || !isset($mv_v2_data['suggestions'])) {
    jpsm_it_fail('mv_search_global v2 missing expected items/meta/suggestions shape');
}
if (!empty($mv_v2_data['items'][0]['url'])) {
    jpsm_it_fail('mv_search_global v2 leaked a presigned url in search payload');
}
if (!isset($mv_v2_data['meta']['index_state'])) {
    jpsm_it_fail('mv_search_global v2 missing meta.index_state');
}

// Instrumentation contract check: behavior report keeps expected search summary keys.
$behavior_report = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php',
    array(
        'action' => 'jpsm_get_behavior_report',
        'nonce' => $access_nonce,
        'month' => gmdate('Y-m'),
    ),
    $cookie_file,
    true
);
$behavior_json = json_decode($behavior_report['body'], true);
if (!is_array($behavior_json) || empty($behavior_json['success'])) {
    jpsm_it_fail('jpsm_get_behavior_report did not return success payload');
}
$report_payload = $behavior_json['data'] ?? array();
if (is_array($report_payload) && isset($report_payload['ok']) && isset($report_payload['data']) && is_array($report_payload['data'])) {
    // Defensive unwrap in case a v2 envelope is returned by configuration.
    $report_payload = $report_payload['data'];
}
$summary = $report_payload['summary'] ?? null;
if (!is_array($summary)) {
    jpsm_it_fail('behavior report missing summary block');
}
foreach (array('search_total', 'search_zero_results_total', 'search_zero_results_rate') as $key) {
    if (!array_key_exists($key, $summary)) {
        jpsm_it_fail('behavior report summary missing key: ' . $key);
    }
}
if (!array_key_exists('top_keywords', $report_payload) || !is_array($report_payload['top_keywords'])) {
    jpsm_it_fail('behavior report missing top_keywords array');
}

jpsm_it_log('[integration] MediaVault premium: presigned URL should use Cloudflare host...');
$mv_dl = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php?action=mv_get_presigned_url',
    array(
        'api_version' => '2',
        'nonce' => $mv_nonce,
        'path' => 'smoke/smoke-track.mp3',
    ),
    $cookie_file,
    true
);

$mv_dl_json = json_decode($mv_dl['body'], true);
if (!is_array($mv_dl_json) || empty($mv_dl_json['success'])) {
    jpsm_it_fail('Premium mv_get_presigned_url did not return expected URL payload');
}
$mv_dl_url = '';
if (isset($mv_dl_json['data']['url']) && is_string($mv_dl_json['data']['url'])) {
    $mv_dl_url = (string) $mv_dl_json['data']['url']; // legacy envelope
} elseif (isset($mv_dl_json['data']['data']['url']) && is_string($mv_dl_json['data']['data']['url'])) {
    $mv_dl_url = (string) $mv_dl_json['data']['data']['url']; // v2 envelope
}
if ($mv_dl_url === '') {
    jpsm_it_fail('Premium mv_get_presigned_url returned success without URL payload');
}
$mv_dl_host = parse_url($mv_dl_url, PHP_URL_HOST);
if ((string) $mv_dl_host !== 'jpsm-downloads.example.workers.dev') {
    jpsm_it_fail('Premium mv_get_presigned_url expected Cloudflare host, got: ' . (string) $mv_dl_host);
}
$mv_dl_query = parse_url($mv_dl_url, PHP_URL_QUERY);
parse_str(is_string($mv_dl_query) ? $mv_dl_query : '', $mv_dl_params);
if (($mv_dl_params['response-content-disposition'] ?? '') !== 'attachment; filename="smoke-track.mp3"') {
    jpsm_it_fail('Premium mv_get_presigned_url expected attachment disposition for downloads');
}

jpsm_it_log('[integration] MediaVault premium: direct preview URL should stay inline...');
$mv_preview = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php?action=mv_get_preview_url',
    array(
        'api_version' => '2',
        'nonce' => $mv_nonce,
        'path' => 'smoke/smoke-track.mp3',
    ),
    $cookie_file,
    true
);

$mv_preview_json = json_decode($mv_preview['body'], true);
if (!is_array($mv_preview_json) || empty($mv_preview_json['success'])) {
    jpsm_it_fail('Premium mv_get_preview_url did not return expected JSON');
}
$mv_preview_mode = '';
$mv_preview_url = '';
if (isset($mv_preview_json['data']['mode'])) {
    $mv_preview_mode = (string) $mv_preview_json['data']['mode'];
    $mv_preview_url = isset($mv_preview_json['data']['url']) ? (string) $mv_preview_json['data']['url'] : '';
} elseif (isset($mv_preview_json['data']['data']) && is_array($mv_preview_json['data']['data'])) {
    $mv_preview_mode = isset($mv_preview_json['data']['data']['mode']) ? (string) $mv_preview_json['data']['data']['mode'] : '';
    $mv_preview_url = isset($mv_preview_json['data']['data']['url']) ? (string) $mv_preview_json['data']['data']['url'] : '';
}
if ($mv_preview_mode !== 'direct' || $mv_preview_url === '') {
    jpsm_it_fail('Premium mv_get_preview_url expected direct mode with URL payload');
}
$mv_preview_host = parse_url($mv_preview_url, PHP_URL_HOST);
if ((string) $mv_preview_host !== 'jpsm-downloads.example.workers.dev') {
    jpsm_it_fail('Premium mv_get_preview_url expected Cloudflare host, got: ' . (string) $mv_preview_host);
}
$mv_preview_query = parse_url($mv_preview_url, PHP_URL_QUERY);
parse_str(is_string($mv_preview_query) ? $mv_preview_query : '', $mv_preview_params);
if (($mv_preview_params['response-content-disposition'] ?? '') !== 'inline; filename="smoke-track.mp3"') {
    jpsm_it_fail('Premium mv_get_preview_url expected inline disposition for direct previews');
}

jpsm_it_log('[integration] MediaVault mobile notice analytics endpoint...');
$mv_notice_ok = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php?action=mv_track_mobile_notice_event',
    array(
        'api_version' => '2',
        'nonce' => $mv_nonce,
        'event' => 'shown',
    ),
    $cookie_file,
    true
);

$mv_notice_ok_json = json_decode($mv_notice_ok['body'], true);
if (!is_array($mv_notice_ok_json) || empty($mv_notice_ok_json['success'])) {
    jpsm_it_fail('mv_track_mobile_notice_event (valid) did not return success');
}

$mv_notice_bad = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php?action=mv_track_mobile_notice_event',
    array(
        'api_version' => '2',
        'nonce' => $mv_nonce,
        'event' => 'invalid_event_name',
    ),
    $cookie_file,
    true
);

$mv_notice_bad_json = json_decode($mv_notice_bad['body'], true);
if (!is_array($mv_notice_bad_json) || !array_key_exists('success', $mv_notice_bad_json)) {
    jpsm_it_fail('mv_track_mobile_notice_event (invalid) did not return expected JSON');
}
if (!empty($mv_notice_bad_json['success'])) {
    jpsm_it_fail('mv_track_mobile_notice_event accepted an invalid event');
}
$mv_notice_bad_code = $mv_notice_bad_json['data']['code'] ?? '';
if ((string) $mv_notice_bad_code !== 'invalid_event') {
    jpsm_it_fail('mv_track_mobile_notice_event expected invalid_event code, got: ' . (string) $mv_notice_bad_code);
}

jpsm_it_log('[integration] behavior tracking endpoint...');
$behavior_track_ok = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php?action=jpsm_track_behavior_event',
    array(
        'api_version' => '2',
        'nonce' => $mv_nonce,
        'event_name' => 'download_file_click',
        'object_type' => 'file',
        'object_path_norm' => 'smoke/smoke-track.mp3',
        'status' => 'click',
    ),
    $cookie_file,
    true
);
$behavior_track_ok_json = json_decode($behavior_track_ok['body'], true);
if (!is_array($behavior_track_ok_json) || empty($behavior_track_ok_json['success'])) {
    jpsm_it_fail('jpsm_track_behavior_event (valid) did not return success');
}

$behavior_track_bad = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php?action=jpsm_track_behavior_event',
    array(
        'api_version' => '2',
        'nonce' => $mv_nonce,
        'event_name' => 'not_allowed_event',
    ),
    $cookie_file,
    true
);
$behavior_track_bad_json = json_decode($behavior_track_bad['body'], true);
if (!is_array($behavior_track_bad_json) || !array_key_exists('success', $behavior_track_bad_json)) {
    jpsm_it_fail('jpsm_track_behavior_event (invalid) did not return expected JSON');
}
if (!empty($behavior_track_bad_json['success'])) {
    jpsm_it_fail('jpsm_track_behavior_event accepted an invalid event');
}
$behavior_track_bad_code = $behavior_track_bad_json['data']['code'] ?? '';
if ((string) $behavior_track_bad_code !== 'invalid_event') {
    jpsm_it_fail('jpsm_track_behavior_event expected invalid_event code, got: ' . (string) $behavior_track_bad_code);
}

jpsm_it_log('[integration] behavior report endpoint...');
$behavior_report = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php',
    array(
        'action' => 'jpsm_get_behavior_report',
        'api_version' => '2',
        'nonce' => $access_nonce,
        'month' => gmdate('Y-m'),
        'tier' => 'all',
        'region' => 'all',
        'device_class' => 'all',
    ),
    $cookie_file,
    true
);

$behavior_report_json = json_decode($behavior_report['body'], true);
if (!is_array($behavior_report_json) || empty($behavior_report_json['success'])) {
    jpsm_it_fail('jpsm_get_behavior_report did not return expected payload');
}
$behavior_report_data = $behavior_report_json['data']['data'] ?? null;
if (!is_array($behavior_report_data) || !isset($behavior_report_data['summary'])) {
    jpsm_it_fail('jpsm_get_behavior_report missing summary payload');
}

jpsm_it_log('[integration] transfer report endpoint...');
foreach (array('month', 'prev_month', 'rolling_90d', 'lifetime') as $transfer_window) {
    $transfer_report = jpsm_it_http_request(
        'POST',
        $base_url . '/wp-admin/admin-ajax.php',
        array(
            'action' => 'jpsm_get_transfer_report',
            'api_version' => '2',
            'nonce' => $access_nonce,
            'month' => gmdate('Y-m'),
            'window' => $transfer_window,
            'tier' => 'all',
            'region' => 'all',
            'device_class' => 'all',
        ),
        $cookie_file,
        true
    );
    $transfer_report_json = json_decode($transfer_report['body'], true);
    if (!is_array($transfer_report_json) || empty($transfer_report_json['success'])) {
        jpsm_it_fail('jpsm_get_transfer_report did not return expected payload for window=' . $transfer_window);
    }
    $transfer_report_data = $transfer_report_json['data']['data'] ?? null;
    if (
        !is_array($transfer_report_data)
        || !isset($transfer_report_data['kpis'])
        || !isset($transfer_report_data['coverage'])
        || !isset($transfer_report_data['window'])
        || !isset($transfer_report_data['top_folders_source'])
        || !isset($transfer_report_data['top_folders_quality'])
        || !isset($transfer_report_data['demand_kpis'])
    ) {
        jpsm_it_fail('jpsm_get_transfer_report missing expected payload keys for window=' . $transfer_window);
    }
}

jpsm_it_log('[integration] transfer CSV export endpoint...');
$transfer_csv = jpsm_it_http_request(
    'GET',
    $base_url . '/wp-admin/admin-ajax.php?action=jpsm_export_transfer_csv'
    . '&nonce=' . urlencode($access_nonce)
    . '&month=' . urlencode(gmdate('Y-m'))
    . '&window=month&tier=all&region=all&device_class=all',
    null,
    $cookie_file,
    true
);
if ($transfer_csv['status'] !== 200) {
    jpsm_it_fail('jpsm_export_transfer_csv expected 200, got: ' . intval($transfer_csv['status']));
}
if (strpos((string) $transfer_csv['body'], 'section,key,value') === false) {
    jpsm_it_fail('jpsm_export_transfer_csv missing CSV header');
}
if (strpos((string) $transfer_csv['body'], 'kpis,transfer_observed_bytes_month') === false) {
    jpsm_it_fail('jpsm_export_transfer_csv missing KPI section');
}
if (strpos((string) $transfer_csv['body'], 'meta_extended,window,month') === false) {
    jpsm_it_fail('jpsm_export_transfer_csv missing extended meta section');
}
if (strpos((string) $transfer_csv['body'], 'demand_kpis,downloads_total_window') === false) {
    jpsm_it_fail('jpsm_export_transfer_csv missing demand KPI section');
}
if (strpos((string) $transfer_csv['body'], 'top_folders_window,') === false) {
    jpsm_it_fail('jpsm_export_transfer_csv missing top_folders_window section');
}

jpsm_it_log('[integration] MediaVault demo: presigned download URLs must be denied...');
$guest_cookie_file = tempnam(sys_get_temp_dir(), 'jpsm_it_guest_');
if (!$guest_cookie_file) {
    jpsm_it_fail('Could not create guest cookie jar');
}

// Trigger guest access flow (sets signed user session cookie) and capture the rendered nonce.
$guest_page = jpsm_it_http_request(
    'GET',
    $base_url . '/?pagename=descargas&invitado=1',
    null,
    $guest_cookie_file,
    true
);

if ($guest_page['status'] !== 200) {
    jpsm_it_fail('Guest MediaVault page did not render, status=' . $guest_page['status']);
}

$guest_nonce = '';
if (preg_match("/nonce:\\s*'([^']+)'/", $guest_page['body'], $m)) {
    $guest_nonce = (string) $m[1];
}
// Option A (post-A3): MV_USER_DATA is emitted as JSON, not as a JS object literal with single quotes.
if ($guest_nonce === '' && preg_match('/"nonce"\\s*:\\s*"([^"]+)"/', $guest_page['body'], $m)) {
    $guest_nonce = (string) $m[1];
}
if ($guest_nonce === '') {
    jpsm_it_fail('Could not extract mediavault nonce from guest page HTML');
}

$guest_dl = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php?action=mv_get_presigned_url',
    array(
        'api_version' => '2',
        'nonce' => $guest_nonce,
        'path' => 'smoke/smoke-track.mp3',
    ),
    $guest_cookie_file,
    true
);

$guest_dl_json = json_decode($guest_dl['body'], true);
if (!is_array($guest_dl_json) || !array_key_exists('success', $guest_dl_json)) {
    jpsm_it_fail('Guest mv_get_presigned_url did not return expected JSON');
}
if (!empty($guest_dl_json['success'])) {
    jpsm_it_fail('Guest mv_get_presigned_url unexpectedly succeeded');
}
$guest_code = $guest_dl_json['data']['code'] ?? '';
if ((string) $guest_code !== 'requires_premium') {
    jpsm_it_fail('Guest mv_get_presigned_url expected requires_premium, got: ' . (string) $guest_code);
}

jpsm_it_log('[integration] index stats + sync...');
$idx_stats = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php',
    array(
        'action' => 'jpsm_get_index_stats',
        'nonce' => $index_nonce,
    ),
    $cookie_file,
    true
);

$idx_stats_json = json_decode($idx_stats['body'], true);
if (!is_array($idx_stats_json) || empty($idx_stats_json['success']) || empty($idx_stats_json['data']['table_exists'])) {
    jpsm_it_fail('get_index_stats did not return expected payload');
}

$idx_sync = jpsm_it_http_request(
    'POST',
    $base_url . '/wp-admin/admin-ajax.php',
    array(
        'action' => 'jpsm_sync_mediavault_index',
        'nonce' => $index_nonce,
    ),
    $cookie_file,
    true
);

$idx_sync_json = json_decode($idx_sync['body'], true);
if (!is_array($idx_sync_json) || !array_key_exists('success', $idx_sync_json)) {
    jpsm_it_fail('sync_mediavault_index did not return expected JSON');
}

jpsm_it_log('[integration] PASS');

// Cleanup.
@unlink($cookie_file);

exit(0);
