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
    $paths = glob($home . '/.wp-now/wp-content/Administrador JetPackStore-*/database/.ht.sqlite');
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
            name TEXT NOT NULL DEFAULT '',
            folder TEXT NOT NULL DEFAULT '',
            size INTEGER DEFAULT 0,
            extension TEXT DEFAULT '',
            synced_at TEXT DEFAULT CURRENT_TIMESTAMP
        );"
    );

    // Seed one search row.
    jpsm_it_sqlite_exec($db, "DELETE FROM wp_jpsm_mediavault_index;");
    jpsm_it_sqlite_exec(
        $db,
        "INSERT INTO wp_jpsm_mediavault_index (path, name, folder, size, extension, synced_at)
         VALUES ('smoke/smoke-track.mp3','smoke-track.mp3','smoke',123456,'mp3',datetime('now'));"
    );

    $db->close();
}

$root = realpath(__DIR__ . '/../..');
if (!$root) {
    jpsm_it_fail('Could not resolve repo root');
}

$port = (int) (getenv('JPSM_WPNOW_PORT') ?: 8099);
$wp_version = (string) (getenv('JPSM_WPNOW_WP') ?: '6.9');
$base_url = 'http://localhost:' . $port;

$cmd = 'npx -y @wp-now/wp-now start --path ' . escapeshellarg($root)
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
    $root
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
    jpsm_it_fail('Dashboard did not render, status=' . $dashboard['status']);
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
