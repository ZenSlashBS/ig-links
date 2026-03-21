<?php
/**
 * get_posts.php -- PHP Instagram post URL scraper API.
 * Pure HTTP, no login. Returns JSON array of post/reel URLs.
 *
 * Usage: GET /get_posts.php?username=example
 *        GET /get_posts.php?username=example&debug=1
 *        GET /get_posts.php?username=example&max_pages=100&delay_min=5&delay_max=15
 */

// Prevent PHP from killing the script early
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

// If running behind a web server, flush output at the end
ignore_user_abort(true);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$username = trim($_GET['username'] ?? '');
$max_pages = (int)($_GET['max_pages'] ?? 5000);
$delay_min = (int)($_GET['delay_min'] ?? 15);
$delay_max = (int)($_GET['delay_max'] ?? 45);
$max_total_time = (int)($_GET['max_time'] ?? 86400); // 24 hours
$debug = isset($_GET['debug']) && $_GET['debug'];
$count_per_page = (int)($_GET['count'] ?? 33); // Instagram supports up to 33

// ---------- Validate username ----------
if ($username === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ?username= parameter']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid username format']);
    exit;
}

error_log("[get_posts] START @$username | max_pages=$max_pages delay={$delay_min}-{$delay_max}s max_time={$max_total_time}s");

$start_time = microtime(true);

// ---------- Cookie jar for session persistence ----------
$cookie_jar = tempnam(sys_get_temp_dir(), 'ig_cookies_');

// ---------- Step 1: Fetch profile HTML to extract user_id ----------
$html = fetch_url("https://www.instagram.com/$username/", [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Sec-Fetch-Dest: document',
    'Sec-Fetch-Mode: navigate',
    'Sec-Fetch-Site: none',
    'Sec-Fetch-User: ?1',
    'Upgrade-Insecure-Requests: 1',
], $cookie_jar);

error_log("[get_posts] Profile fetch: status={$html['status']}, body_len=" . strlen($html['body']));

if ($html['status'] !== 200) {
    cleanup_cookies($cookie_jar);
    http_response_code($html['status'] ?: 502);
    echo json_encode([
        'error' => "HTTP {$html['status']} fetching profile",
        'hint' => 'Profile may be private, deleted, or geo-blocked'
    ]);
    exit;
}

// Extract user_id from multiple possible locations in the HTML
$user_id = null;

// Method 1: "user_id":"12345"
if (preg_match('/"user_id"\s*:\s*"(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
}

// Method 2: "profilePage_12345"
if (!$user_id && preg_match('/"profilePage_(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
}

// Method 3: "id":"12345" near the username in shared_data
if (!$user_id && preg_match('/"id"\s*:\s*"(\d+)"\s*,\s*"username"\s*:\s*"' . preg_quote($username, '/') . '"/i', $html['body'], $m)) {
    $user_id = $m[1];
}

// Method 4: data-id or similar attributes
if (!$user_id && preg_match('/data-id="(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
}

// Method 5: Try the web profile info endpoint as fallback
if (!$user_id) {
    error_log("[get_posts] user_id not in HTML, trying web_profile_info API...");
    sleep(2);

    $api_resp = fetch_url(
        "https://www.instagram.com/api/v1/users/web_profile_info/?username=" . urlencode($username),
        [
            'Accept: application/json',
            'X-IG-App-ID: 936619743392459',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://www.instagram.com/' . $username . '/',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
        ],
        $cookie_jar
    );

    if ($api_resp['status'] === 200) {
        $profile_data = json_decode($api_resp['body'], true);
        $user_id = $profile_data['data']['user']['id'] ?? null;
    }
}

if (!$user_id) {
    cleanup_cookies($cookie_jar);
    $error_response = ['error' => 'Could not find user_id — profile may be private, deleted, or geo-blocked'];
    if ($debug) {
        $error_response['html_length'] = strlen($html['body']);
        $error_response['html_snippet'] = substr($html['body'], 0, 2000);
    }
    http_response_code(404);
    echo json_encode($error_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

error_log("[get_posts] user_id=$user_id");

// ---------- Step 2: Paginate the feed API ----------
$all_urls = [];
$seen_pks = [];
$max_id = '';
$page_n = 0;
$more_available = true;
$next_max_id = '';
$consecutive_empty = 0;
$total_items_seen = 0;

do {
    // Check time limit
    $elapsed = microtime(true) - $start_time;
    if ($elapsed > $max_total_time) {
        error_log("[get_posts] Time limit reached: " . round($elapsed) . "s");
        break;
    }

    // Check page limit
    $page_n++;
    if ($page_n > $max_pages) {
        error_log("[get_posts] Page limit reached: $max_pages");
        break;
    }

    // Build API URL
    $api_path = "https://www.instagram.com/api/v1/feed/user/$user_id/?count=$count_per_page";
    if ($max_id !== '') {
        $api_path .= '&max_id=' . urlencode($max_id);
    }

    // Retry loop with exponential backoff
    $resp = null;
    $max_retries = 10;

    for ($retry = 0; $retry <= $max_retries; $retry++) {
        if ($retry > 0) {
            // Exponential backoff: 30s, 60s, 120s, 240s ... capped at 900s
            $backoff = min(30 * pow(2, $retry - 1), 900);
            $jitter = mt_rand(0, 30);
            $wait = $backoff + $jitter;
            error_log("[get_posts] Page $page_n retry $retry/$max_retries — waiting {$wait}s");
            sleep($wait);
        }

        $resp = fetch_url($api_path, [
            'Accept: application/json',
            'X-IG-App-ID: 936619743392459',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://www.instagram.com/' . $username . '/',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
        ], $cookie_jar);

        if ($resp['status'] === 200) {
            break;
        }

        error_log("[get_posts] Page $page_n attempt " . ($retry + 1) . " failed: HTTP {$resp['status']}");

        // If we get a 401/403, cookies may have expired — try refreshing
        if (in_array($resp['status'], [401, 403]) && $retry === 0) {
            error_log("[get_posts] Refreshing session cookies...");
            fetch_url("https://www.instagram.com/$username/", [
                'Accept: text/html,application/xhtml+xml,*/*',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
            ], $cookie_jar);
            sleep(3);
        }
    }

    if ($resp['status'] !== 200) {
        error_log("[get_posts] Page $page_n FAILED after all retries: HTTP {$resp['status']}");
        break;
    }

    // Validate JSON
    $body = $resp['body'];
    if (!preg_match('/^\s*[\{\$]/', $body)) {
        error_log("[get_posts] Page $page_n response is not JSON");
        if ($debug) {
            error_log("[get_posts] Body preview: " . substr($body, 0, 300));
        }
        break;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("[get_posts] Page $page_n JSON error: " . json_last_error_msg());
        break;
    }

    $items = $data['items'] ?? [];
    $more_available = (bool)($data['more_available'] ?? false);
    $next_max_id = (string)($data['next_max_id'] ?? '');

    // Process items
    $new_count = 0;
    foreach ($items as $item) {
        $total_items_seen++;
        $pk = (string)($item['pk'] ?? $item['id'] ?? '');
        if ($pk === '' || isset($seen_pks[$pk])) {
            continue;
        }
        $seen_pks[$pk] = true;

        $url = item_to_url($item);
        if ($url !== null) {
            $all_urls[] = $url;
            $new_count++;
        }
    }

    error_log(sprintf(
        "[get_posts] Page %d: +%d new, %d total, more=%s, next_max_id=%s, elapsed=%ds",
        $page_n,
        $new_count,
        count($all_urls),
        $more_available ? 'yes' : 'no',
        $next_max_id ? substr($next_max_id, 0, 20) . '...' : '(none)',
        round(microtime(true) - $start_time)
    ));

    // Track consecutive empty pages to detect stalls
    if ($new_count === 0) {
        $consecutive_empty++;
        if ($consecutive_empty >= 3) {
            error_log("[get_posts] 3 consecutive empty pages — stopping");
            break;
        }
    } else {
        $consecutive_empty = 0;
    }

    // Update cursor
    $max_id = $next_max_id;

    // Delay between pages (randomized within range)
    if ($more_available && $next_max_id) {
        $delay_seconds = $delay_min + (mt_rand(0, 1000) / 1000.0) * ($delay_max - $delay_min);
        $delay_us = (int)($delay_seconds * 1000000);
        usleep($delay_us);
    }

} while ($more_available && $next_max_id !== '');

// ---------- Done ----------
cleanup_cookies($cookie_jar);

$duration = round(microtime(true) - $start_time, 2);
error_log("[get_posts] DONE: " . count($all_urls) . " URLs in {$duration}s across $page_n pages");

$response = [
    'username' => $username,
    'user_id' => $user_id,
    'count' => count($all_urls),
    'pages_fetched' => $page_n,
    'duration_seconds' => $duration,
    'urls' => $all_urls,
];

if ($debug) {
    $response['debug'] = [
        'total_items_seen' => $total_items_seen,
        'unique_pks' => count($seen_pks),
        'final_more_available' => $more_available,
        'final_next_max_id' => $next_max_id,
        'consecutive_empty_at_end' => $consecutive_empty,
    ];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;


// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Fetch a URL using cURL with cookie persistence.
 */
function fetch_url(string $url, array $headers = [], string $cookie_jar = ''): array {
    $ch = curl_init();

    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_ENCODING => '',  // Accept all encodings, auto-decompress
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
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    if ($curl_errno !== 0) {
        error_log("[get_posts] cURL error #$curl_errno: $curl_error | URL: $url");
        return ['status' => 0, 'body' => '', 'error' => $curl_error];
    }

    return ['status' => $status, 'body' => ($body !== false ? $body : ''), 'error' => ''];
}

/**
 * Convert an Instagram PK (big integer as string) to a shortcode.
 * Instagram PKs exceed PHP_INT_MAX on 64-bit systems, so we use
 * bcmath (arbitrary precision) to avoid overflow.
 */
function pk_to_shortcode(string $pk): string {
    $ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    // Use bcmath for arbitrary-precision division
    if (function_exists('bcmod') && function_exists('bcdiv')) {
        $n = $pk;
        $code = '';

        // Guard: if pk is 0 or empty
        if ($n === '' || $n === '0') {
            return '';
        }

        while (bccomp($n, '0') > 0) {
            $remainder = (int)bcmod($n, '64');
            $code = $ALPHA[$remainder] . $code;
            $n = bcdiv($n, '64', 0);
        }

        return $code;
    }

    // Fallback: use GMP if available
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

    // Last resort: plain PHP int (will overflow on very large PKs)
    error_log("[get_posts] WARNING: bcmath and gmp unavailable — shortcode may be wrong for large PKs");
    $n = (int)$pk;
    $code = '';
    if ($n <= 0) {
        return '';
    }
    while ($n > 0) {
        $code = $ALPHA[$n % 64] . $code;
        $n = intdiv($n, 64);
    }
    return $code;
}

/**
 * Convert an API item to an Instagram URL.
 * Uses the shortcode directly from the item if available (most reliable),
 * otherwise computes it from the PK.
 */
function item_to_url(array $item): ?string {
    // Prefer the shortcode Instagram already provides
    $shortcode = $item['code'] ?? '';

    // Fall back to computing from PK
    if ($shortcode === '') {
        $pk = (string)($item['pk'] ?? $item['id'] ?? '');
        if ($pk === '') {
            return null;
        }
        $shortcode = pk_to_shortcode($pk);
        if ($shortcode === '') {
            return null;
        }
    }

    $media_type = (int)($item['media_type'] ?? 1);
    $product_type = (string)($item['product_type'] ?? '');

    // media_type 2 = video, product_type "clips" = reel
    if ($media_type === 2 || $product_type === 'clips' || $product_type === 'igtv') {
        return "https://www.instagram.com/reel/$shortcode/";
    }

    return "https://www.instagram.com/p/$shortcode/";
}

/**
 * Clean up the temporary cookie file.
 */
function cleanup_cookies(string $cookie_jar): void {
    if ($cookie_jar !== '' && file_exists($cookie_jar)) {
        @unlink($cookie_jar);
    }
}
?>
