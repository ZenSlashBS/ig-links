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

$cookie_jar = tempnam(sys_get_temp_dir(), 'ig_');

log_msg("============ START @$username ============");

$start_time = microtime(true);

// ── Step 1: Fetch profile page for cookies + user_id ──
log_msg("[STEP 1] GET https://www.instagram.com/$username/");

$html = fetch_url(
    "https://www.instagram.com/$username/",
    [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Upgrade-Insecure-Requests: 1',
    ],
    $cookie_jar
);

log_msg("[STEP 1] HTTP {$html['status']} | " . strlen($html['body']) . " bytes | " . ($html['error'] ?: 'OK'));

if ($html['status'] !== 200) {
    cleanup($cookie_jar);
    http_response_code($html['status'] ?: 502);
    echo json_encode(['error' => "HTTP {$html['status']} fetching profile"]);
    exit;
}

// Extract CSRF token
$csrf_token = '';
if (file_exists($cookie_jar)) {
    $cc = file_get_contents($cookie_jar);
    if (preg_match('/csrftoken\s+(\S+)/', $cc, $cm)) {
        $csrf_token = $cm[1];
        log_msg("[STEP 1] CSRF: " . substr($csrf_token, 0, 12) . "...");
    }
}

// ── Step 2: Extract user_id ──
$user_id = null;

if (preg_match('/"user_id"\s*:\s*"(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] user_id=$user_id (method: user_id field)");
}
if (!$user_id && preg_match('/"profilePage_(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] user_id=$user_id (method: profilePage)");
}
if (!$user_id && preg_match('/"id"\s*:\s*"(\d+)"\s*,\s*"username"\s*:\s*"' . preg_quote($username, '/') . '"/i', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] user_id=$user_id (method: id+username)");
}

// Fallback: web_profile_info
if (!$user_id) {
    log_msg("[STEP 2] Trying web_profile_info API...");
    usleep(500000);
    $papi = fetch_url(
        "https://www.instagram.com/api/v1/users/web_profile_info/?username=" . urlencode($username),
        [
            'Accept: application/json',
            'X-IG-App-ID: 936619743392459',
            'X-Requested-With: XMLHttpRequest',
            "X-CSRFToken: $csrf_token",
            "Referer: https://www.instagram.com/$username/",
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
        ],
        $cookie_jar
    );
    log_msg("[STEP 2] web_profile_info: HTTP {$papi['status']}");
    if ($papi['status'] === 200) {
        $pd = json_decode($papi['body'], true);
        $user_id = $pd['data']['user']['id'] ?? null;
        if ($user_id) log_msg("[STEP 2] user_id=$user_id (method: web_profile_info)");
    }
}

if (!$user_id) {
    cleanup($cookie_jar);
    log_msg("[STEP 2] FAILED — no user_id found");
    http_response_code(404);
    $err = ['error' => 'Could not find user_id (private/deleted/geo-blocked?)'];
    if ($debug) {
        $err['html_length'] = strlen($html['body']);
        $err['html_snippet'] = substr($html['body'], 0, 2000);
    }
    echo json_encode($err, JSON_PRETTY_PRINT);
    exit;
}

// ── Step 3: Paginate feed ──
log_msg("============ PAGINATING user_id=$user_id ============");

$all_urls = [];
$seen_pks = [];
$max_id = '';
$page_n = 0;
$more_available = true;
$next_max_id = '';
$consecutive_empty = 0;
$consecutive_fails = 0;
$total_api_items = 0;
$total_retries = 0;
$rate_limit_hits = 0;
$page_success = true;

do {
    $page_n++;

    $api_url = "https://www.instagram.com/api/v1/feed/user/$user_id/?count=33";
    if ($max_id !== '') {
        $api_url .= '&max_id=' . urlencode($max_id);
    }

    log_msg("[PAGE $page_n] GET $api_url");

    // Quick retry: max 3 attempts, short backoff
    $resp = null;
    $page_success = false;

    for ($retry = 0; $retry < 3; $retry++) {
        if ($retry > 0) {
            $wait = 5 * $retry + mt_rand(1, 5);
            $total_retries++;
            log_msg("[PAGE $page_n] RETRY $retry/3 — wait {$wait}s");
            sleep($wait);
        }

        $req_start = microtime(true);

        $resp = fetch_url($api_url, [
            'Accept: application/json',
            'X-IG-App-ID: 936619743392459',
            'X-Requested-With: XMLHttpRequest',
            "X-CSRFToken: $csrf_token",
            "Referer: https://www.instagram.com/$username/",
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
        ], $cookie_jar);

        $req_time = round(microtime(true) - $req_start, 2);
        log_msg("[PAGE $page_n] HTTP {$resp['status']} | " . strlen($resp['body']) . " bytes | {$req_time}s");

        if ($resp['status'] === 200) {
            $page_success = true;
            $consecutive_fails = 0;
            break;
        }

        if (in_array($resp['status'], [429, 401, 403])) {
            $rate_limit_hits++;
            log_msg("[PAGE $page_n] RATE LIMITED ({$resp['status']}) — hit #$rate_limit_hits");
        }
    }

    if (!$page_success) {
        $consecutive_fails++;
        log_msg("[PAGE $page_n] FAILED after retries (consecutive_fails=$consecutive_fails)");
        if ($consecutive_fails >= 2) {
            log_msg("[PAGE $page_n] STOPPING: 2 consecutive failed pages");
            break;
        }
        // Skip this page, try next with same max_id after a pause
        sleep(10);
        continue;
    }

    // Parse JSON
    $body = $resp['body'];
    if (!preg_match('/^\s*[\{\$]/', $body)) {
        log_msg("[PAGE $page_n] NOT JSON — stopping");
        break;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_msg("[PAGE $page_n] JSON ERROR: " . json_last_error_msg());
        break;
    }

    $items = $data['items'] ?? [];
    $more_available = (bool)($data['more_available'] ?? false);
    $next_max_id = (string)($data['next_max_id'] ?? '');

    // Process items
    $new_count = 0;
    $dupes = 0;
    foreach ($items as $item) {
        $total_api_items++;
        $pk = (string)($item['pk'] ?? $item['id'] ?? '');
        if ($pk === '' || isset($seen_pks[$pk])) {
            $dupes++;
            continue;
        }
        $seen_pks[$pk] = true;

        $url = item_to_url($item);
        if ($url !== null) {
            $all_urls[] = $url;
            $new_count++;
        }
    }

    log_msg("[PAGE $page_n] +$new_count new | $dupes dupes | total=" . count($all_urls) . " | more=$more_available | next=" . ($next_max_id ? substr($next_max_id, 0, 15) . '...' : 'NONE'));

    // Sample URL
    if ($new_count > 0) {
        log_msg("[PAGE $page_n] Sample: " . $all_urls[count($all_urls) - $new_count]);
    }

    // Empty page detection
    if ($new_count === 0) {
        $consecutive_empty++;
        if ($consecutive_empty >= 3) {
            log_msg("STOPPING: 3 consecutive empty pages");
            break;
        }
    } else {
        $consecutive_empty = 0;
    }

    $max_id = $next_max_id;

    // Your original fast delay: 0.6-1.1s
    if ($more_available && $next_max_id) {
        $delay_us = 600000 + mt_rand(0, 500000);
        usleep($delay_us);
    }

    // Progress every 25 pages
    if ($page_n % 25 === 0) {
        $elapsed = round(microtime(true) - $start_time, 1);
        $rate = count($all_urls) > 0 ? round(count($all_urls) / ($elapsed / 60), 1) : 0;
        log_msg("──── PROGRESS: {$page_n} pages | " . count($all_urls) . " URLs | {$elapsed}s | ~{$rate}/min ────");
    }

} while ($more_available && $next_max_id !== '');

// ── Done ──
cleanup($cookie_jar);

$duration = round(microtime(true) - $start_time, 2);

// Determine why we stopped
$stop_reason = 'unknown';
if (!$more_available && $next_max_id === '') $stop_reason = 'reached_end';
elseif (!$more_available) $stop_reason = 'no_more_available';
elseif ($next_max_id === '') $stop_reason = 'no_next_cursor';
elseif ($consecutive_empty >= 3) $stop_reason = 'empty_pages';
elseif ($consecutive_fails >= 2) $stop_reason = 'request_failures';

log_msg("============ DONE @$username ============");
log_msg("URLs: " . count($all_urls) . " | Pages: $page_n | Time: {$duration}s | Retries: $total_retries | Rate limits: $rate_limit_hits | Stop: $stop_reason");
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
        'total_api_items' => $total_api_items,
        'unique_pks' => count($seen_pks),
        'total_retries' => $total_retries,
        'rate_limit_hits' => $rate_limit_hits,
        'final_more_available' => $more_available,
        'final_next_max_id' => $next_max_id,
    ];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;


// ============================================================
// FUNCTIONS
// ============================================================

function log_msg(string $msg): void {
    $ts = date('H:i:s');
    $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
    error_log("[ig][$ts][{$mem}M] $msg");
}

function fetch_url(string $url, array $headers = [], string $cookie_jar = ''): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.82 Mobile Safari/537.36',
        CURLOPT_HTTPHEADER => array_merge([
            'Accept-Language: en-US,en;q=0.9',
        ], $headers),
        CURLOPT_SSL_VERIFYPEER => true,
    ];
        if ($cookie_jar !== '') {
        $opts[CURLOPT_COOKIEJAR] = $cookie_jar;
        $opts[CURLOPT_COOKIEFILE] = $cookie_jar;
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($errno !== 0) {
        log_msg("cURL ERROR #$errno: $error | $url");
        return ['status' => 0, 'body' => '', 'error' => $error];
    }

    if ($effective_url !== $url) {
        log_msg("REDIRECT: $url -> $effective_url");
    }

    return ['status' => $status, 'body' => ($body !== false ? $body : ''), 'error' => ''];
}

function pk_to_shortcode(string $pk): string {
    $ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    if ($pk === '' || $pk === '0') {
        return '';
    }

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

    log_msg("WARNING: bcmath/gmp unavailable — shortcode may overflow");
    $n = (int)$pk;
    $code = '';
    while ($n > 0) {
        $code = $ALPHA[$n % 64] . $code;
        $n = intdiv($n, 64);
    }
    return $code;
}

function item_to_url(array $item): ?string {
    // Prefer shortcode directly from API
    $shortcode = (string)($item['code'] ?? '');

    if ($shortcode === '') {
        $pk = (string)($item['pk'] ?? $item['id'] ?? '');
        if ($pk === '') return null;
        $shortcode = pk_to_shortcode($pk);
        if ($shortcode === '') return null;
    }

    $media_type = (int)($item['media_type'] ?? 1);
    $product_type = (string)($item['product_type'] ?? '');

    if ($media_type === 2 || $product_type === 'clips' || $product_type === 'igtv') {
        return "https://www.instagram.com/reel/$shortcode/";
    }

    return "https://www.instagram.com/p/$shortcode/";
}

function cleanup(string $cookie_jar): void {
    if ($cookie_jar !== '' && file_exists($cookie_jar)) {
        @unlink($cookie_jar);
        log_msg("Cookie jar cleaned up");
    }
}
?>
