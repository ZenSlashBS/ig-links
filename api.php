<?php
/**
 * get_posts.php -- PHP Instagram post URL scraper API.
 * Pure HTTP, no login. Returns JSON array of post/reel URLs.
 *
 * Usage: GET /get_posts.php?username=example
 *        GET /get_posts.php?username=example&debug=1
 */

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!isset($_GET['username']) || empty(trim($_GET['username']))) {
    http_response_code(400);
    echo json_encode(['error' => 'Username parameter required']);
    exit;
}

$username = trim($_GET['username']);
$debug = isset($_GET['debug']) && $_GET['debug'];

log_msg("============ START @$username ============");

$start_time = microtime(true);

// ── Step 1: Fetch profile HTML -> user_id ──
log_msg("[STEP 1] Fetching profile: https://www.instagram.com/$username/");

$html = fetch_url("www.instagram.com", "/$username/", [
    'Accept' => 'text/html,application/xhtml+xml,*/*',
    'Sec-Fetch-Dest' => 'document',
    'Sec-Fetch-Mode' => 'navigate',
    'Upgrade-Insecure-Requests' => '1',
]);

log_msg("[STEP 1] HTTP {$html['status']} | " . strlen($html['body']) . " bytes");

if ($html['status'] !== 200) {
    http_response_code($html['status']);
    echo json_encode(['error' => "HTTP {$html['status']} fetching profile"]);
    exit;
}

// ── Step 2: Extract user_id ──
$user_id = null;

if (preg_match('/"user_id":"(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] user_id=$user_id (method: user_id field)");
}
if (!$user_id && preg_match('/"profilePage_(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] user_id=$user_id (method: profilePage)");
}

if (!$user_id) {
    log_msg("[STEP 2] FAILED — no user_id found");
    $err = ['error' => 'Could not find user_id (private/deleted/geo-blocked?)'];
    if ($debug) {
        $err['html_length'] = strlen($html['body']);
        $err['html_preview'] = substr($html['body'], 0, 1000);
        $err['regex_test'] = preg_match('/"user_id"/', $html['body'])
            ? 'user_id key found but no match'
            : 'user_id key missing entirely';
    }
    http_response_code(404);
    echo json_encode($err, JSON_PRETTY_PRINT);
    exit;
}

// ── Step 3: Paginate feed API ──
log_msg("============ PAGINATING user_id=$user_id ============");

$all_urls = [];
$seen_pks = [];
$max_id = '';
$page_n = 0;
$more_available = false;
$next_max_id = '';
$consecutive_empty = 0;
$consecutive_fails = 0;
$total_retries = 0;
$rate_limit_hits = 0;

do {
    $page_n++;

    // Same path format as the working JS/Python: /api/v1/feed/user/{id}/?count=12
    $path = "/api/v1/feed/user/$user_id/?count=12";
    if ($max_id) {
        $path .= '&max_id=' . urlencode($max_id);
    }

    log_msg("[PAGE $page_n] GET https://www.instagram.com$path");

    // Retry loop: max 3 quick retries
    $resp = null;
    $page_ok = false;

    for ($retry = 0; $retry < 3; $retry++) {
        if ($retry > 0) {
            $wait = 3 * $retry + mt_rand(1, 3);
            $total_retries++;
            log_msg("[PAGE $page_n] RETRY $retry/3 — wait {$wait}s");
            sleep($wait);
        }

        $req_start = microtime(true);

        // EXACT same headers as the working code + JS/Python reference
        $resp = fetch_url("www.instagram.com", $path, [
            'X-IG-App-ID' => '1217981644879628',
            'Referer' => "https://www.instagram.com/$username/",
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
        ]);

        $req_time = round(microtime(true) - $req_start, 2);
        log_msg("[PAGE $page_n] HTTP {$resp['status']} | " . strlen($resp['body']) . " bytes | {$req_time}s");

        if ($resp['status'] === 200) {
            $page_ok = true;
            $consecutive_fails = 0;
            break;
        }

        if (in_array($resp['status'], [429, 401, 403])) {
            $rate_limit_hits++;
            log_msg("[PAGE $page_n] RATE LIMITED ({$resp['status']}) — total hits: $rate_limit_hits");
        }

        log_msg("[PAGE $page_n] Response preview: " . substr($resp['body'], 0, 200));
    }

    if (!$page_ok) {
        $consecutive_fails++;
        log_msg("[PAGE $page_n] FAILED (consecutive=$consecutive_fails)");
        if ($consecutive_fails >= 2) {
            log_msg("STOPPING: 2 consecutive failed pages");
            break;
        }
        sleep(5);
        continue;
    }

    // Parse JSON
    $body = $resp['body'];
    if (!preg_match('/^\s*\{/', $body)) {
        log_msg("[PAGE $page_n] NOT JSON — stopping");
        log_msg("[PAGE $page_n] Preview: " . substr($body, 0, 200));
        break;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_msg("[PAGE $page_n] JSON ERROR: " . json_last_error_msg());
        break;
    }

    $items = $data['items'] ?? [];
    $more_available = $data['more_available'] ?? false;
    $next_max_id = $data['next_max_id'] ?? '';

    // Process items
    $new_count = 0;
    $dupes = 0;
    foreach ($items as $item) {
        $pk = (string)($item['pk'] ?? $item['id'] ?? '');
        if (!$pk || isset($seen_pks[$pk])) {
            $dupes++;
            continue;
        }
        $seen_pks[$pk] = true;

        $url = item_to_url($item);
        if ($url) {
            $all_urls[] = $url;
            $new_count++;
        }
    }

    log_msg("[PAGE $page_n] +$new_count new | $dupes dupes | total=" . count($all_urls) . " | more=" . ($more_available ? 'Y' : 'N') . " | next=" . ($next_max_id ? substr($next_max_id, 0, 20) . '...' : 'NONE'));

    // Log one sample URL per page
    if ($new_count > 0) {
        log_msg("[PAGE $page_n] Sample: " . $all_urls[count($all_urls) - $new_count]);
    }

    // Empty page detection
    if ($new_count === 0) {
        $consecutive_empty++;
        log_msg("[PAGE $page_n] WARNING: empty page ($consecutive_empty consecutive)");
        if ($consecutive_empty >= 3) {
            log_msg("STOPPING: 3 consecutive empty pages");
            break;
        }
    } else {
        $consecutive_empty = 0;
    }

    $max_id = $next_max_id;

    // Original delay: 0.6-1.1s
    if ($more_available && $next_max_id) {
        usleep(600000 + mt_rand(0, 500000));
    }

    // Progress log every 25 pages
    if ($page_n % 25 === 0) {
        $elapsed = round(microtime(true) - $start_time, 1);
        $rate = count($all_urls) > 0 ? round(count($all_urls) / ($elapsed / 60), 1) : 0;
        log_msg("──── PROGRESS: {$page_n} pages | " . count($all_urls) . " URLs | {$elapsed}s | ~{$rate}/min ────");
    }

} while ($more_available && $next_max_id);

// ── Output ──
$duration = round(microtime(true) - $start_time, 2);

$stop_reason = 'unknown';
if (!$more_available && empty($next_max_id)) $stop_reason = 'reached_end';
elseif (!$more_available) $stop_reason = 'no_more_available';
elseif (empty($next_max_id)) $stop_reason = 'no_next_cursor';
elseif ($consecutive_empty >= 3) $stop_reason = 'empty_pages';
elseif ($consecutive_fails >= 2) $stop_reason = 'request_failures';

log_msg("============ DONE @$username ============");
log_msg("URLs=" . count($all_urls) . " | Pages=$page_n | Time={$duration}s | Retries=$total_retries | RateLimits=$rate_limit_hits | Stop=$stop_reason");
log_msg("==========================================");

$response = [
    'username' => $username,
    'user_id' => $user_id,
    'count' => count($all_urls),
    'pages_fetched' => $page_n,
    'duration_seconds' => $duration,
    'stop_reason' => $stop_reason,
    'urls' => $all_urls,
];

if ($debug) {
    $response['debug'] = [
        'unique_pks' => count($seen_pks),
        'total_retries' => $total_retries,
        'rate_limit_hits' => $rate_limit_hits,
        'final_more_available' => $more_available ?? false,
        'final_next_max_id' => $next_max_id ?? '',
    ];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);

// ============================================================
// FUNCTIONS — kept EXACTLY like the working version
// ============================================================

/**
 * Log with timestamp and memory usage
 */
function log_msg(string $msg): void {
    $ts = date('H:i:s');
    $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
    error_log("[ig][$ts][{$mem}M] $msg");
}

/**
 * HTTP GET — SAME signature as working code: fetch_url($hostname, $path, $assoc_headers)
 * This is the key: hostname and path are separate, headers are key=>value associative array.
 */
function fetch_url($hostname, $path, $extra_headers = []) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://$hostname$path",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 11; Pixel 5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.6261.119 Mobile Safari/537.36',
        CURLOPT_HTTPHEADER => array_merge([
            'Accept: application/json, text/html, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
        ], array_map(
            function($k, $v) { return "$k: $v"; },
            array_keys($extra_headers),
            $extra_headers
        )),
        CURLOPT_ENCODING => 'gzip, deflate, br',
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0) {
        log_msg("cURL ERROR #$errno: $error | https://$hostname$path");
        return ['status' => 0, 'body' => ''];
    }

    if ($error) {
        log_msg("cURL error: $error");
        return ['status' => 0, 'body' => ''];
    }

    return ['status' => $status, 'body' => $body];
}

/**
 * Convert PK to shortcode using bcmath for big integers.
 * Falls back to GMP, then plain int.
 */
function pk_to_shortcode($pk) {
    $ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $pk = (string)$pk;

    if ($pk === '' || $pk === '0') {
        return '';
    }

    // bcmath — handles arbitrarily large integers
        // bcmath — handles arbitrarily large integers
    if (function_exists('bcmod') && function_exists('bcdiv')) {
        $n = $pk;
        $code = '';
        while (bccomp($n, '0') > 0) {
            $remainder = (int)bcmod($n, '64');
            $code = $ALPHA[$remainder] . $code;
            $n = bcdiv($n, '64', 0);
        }
        return $code;
    }

    // GMP fallback
    if (function_exists('gmp_mod') && function_exists('gmp_div_q')) {
        $n = gmp_init($pk, 10);
        $code = '';
        $zero = gmp_init(0);
        $base = gmp_init(64);
        while (gmp_cmp($n, $zero) > 0) {
            $remainder = gmp_intval(gmp_mod($n, $base));
            $code = $ALPHA[$remainder] . $code;
            $n = gmp_div_q($n, $base);
        }
        return $code;
    }

    // Plain int fallback (may overflow on large PKs)
    log_msg("WARNING: bcmath/gmp unavailable — shortcode may be wrong for large PKs");
    $n = (int)$pk;
    $code = '';
    while ($n > 0) {
        $code = $ALPHA[$n % 64] . $code;
        $n = intdiv($n, 64);
    }
    return $code;
}

/**
 * Convert API item to Instagram URL.
 * Prefers 'code' field from API (always correct), falls back to PK conversion.
 */
function item_to_url($item) {
    // Instagram API returns shortcode directly in 'code' field
    $sc = (string)($item['code'] ?? '');

    // Fallback: compute from PK
    if ($sc === '') {
        $pk = $item['pk'] ?? $item['id'] ?? '';
        if (!$pk) {
            return null;
        }
        $sc = pk_to_shortcode((string)$pk);
        if ($sc === '') {
            return null;
        }
    }

    $media_type = $item['media_type'] ?? 1;
    $product_type = $item['product_type'] ?? '';

    if ($media_type == 2 || $product_type == 'clips' || $product_type == 'igtv') {
        return "https://www.instagram.com/reel/$sc/";
    }
    return "https://www.instagram.com/p/$sc/";
}
?>
