<?php
/**
 * get_posts.php -- PHP Instagram post URL scraper API.
 * Pure HTTP, no login. Returns JSON array of post/reel URLs.
 *
 * Usage: GET /get_posts.php?username=example
 *        GET /get_posts.php?username=example&debug=1
 */

// CRITICAL: Prevent PHP from killing the script
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

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
$delay_min = (int)($_GET['delay_min'] ?? 4);
$delay_max = (int)($_GET['delay_max'] ?? 8);
$max_pages = (int)($_GET['max_pages'] ?? 5000);
$max_time = (int)($_GET['max_time'] ?? 86400);

// Create persistent cookie jar
$cookie_jar = tempnam(sys_get_temp_dir(), 'ig_cookies_');

log_msg("========================================");
log_msg("START scrape for @$username");
log_msg("Config: delay={$delay_min}-{$delay_max}s, max_pages=$max_pages, max_time={$max_time}s");
log_msg("Cookie jar: $cookie_jar");
log_msg("========================================");

$start_time = microtime(true);

// ──────────────────────────────────────────────
// Step 1: Load profile page to get cookies + user_id
// ──────────────────────────────────────────────
log_msg("[STEP 1] Fetching profile page: https://www.instagram.com/$username/");

$html = fetch_url(
    "https://www.instagram.com/$username/",
    [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1',
    ],
    $cookie_jar
);

log_msg("[STEP 1] Response: HTTP {$html['status']} | Body: " . strlen($html['body']) . " bytes | cURL: " . ($html['error'] ?: 'OK'));

if ($html['status'] !== 200) {
    cleanup($cookie_jar);
    http_response_code($html['status'] ?: 502);
    echo json_encode(['error' => "HTTP {$html['status']} fetching profile"]);
    exit;
}

// Extract CSRF token from cookies
$csrf_token = '';
if (file_exists($cookie_jar)) {
    $cookie_content = file_get_contents($cookie_jar);
    if (preg_match('/csrftoken\s+(\S+)/', $cookie_content, $cm)) {
        $csrf_token = $cm[1];
        log_msg("[STEP 1] CSRF token found: " . substr($csrf_token, 0, 10) . "...");
    } else {
        log_msg("[STEP 1] WARNING: No CSRF token in cookies");
    }
}

// ──────────────────────────────────────────────
// Step 2: Extract user_id (multiple methods)
// ──────────────────────────────────────────────
log_msg("[STEP 2] Extracting user_id...");

$user_id = null;

// Method 1: "user_id":"12345"
if (preg_match('/"user_id"\s*:\s*"(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] Found user_id via method 1 (user_id field): $user_id");
}

// Method 2: "profilePage_12345"
if (!$user_id && preg_match('/"profilePage_(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] Found user_id via method 2 (profilePage): $user_id");
}

// Method 3: "id":"12345" near username
if (!$user_id && preg_match('/"id"\s*:\s*"(\d+)"\s*,\s*"username"\s*:\s*"' . preg_quote($username, '/') . '"/i', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] Found user_id via method 3 (id+username pair): $user_id");
}

// Method 4: Try web_profile_info API
if (!$user_id) {
    log_msg("[STEP 2] user_id not in HTML — trying web_profile_info API...");
    sleep(2);

    $profile_api = fetch_url(
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

    log_msg("[STEP 2] web_profile_info: HTTP {$profile_api['status']} | Body: " . strlen($profile_api['body']) . " bytes");

    if ($profile_api['status'] === 200) {
        $pdata = json_decode($profile_api['body'], true);
        $user_id = $pdata['data']['user']['id'] ?? null;
        if ($user_id) {
            log_msg("[STEP 2] Found user_id via method 4 (web_profile_info): $user_id");
        }
    }
}

if (!$user_id) {
    cleanup($cookie_jar);
    log_msg("[STEP 2] FAILED: Could not extract user_id");
    $err = ['error' => 'Could not find user_id — profile may be private, deleted, or geo-blocked'];
    if ($debug) {
        $err['html_length'] = strlen($html['body']);
        $err['html_snippet'] = substr($html['body'], 0, 2000);
    }
    http_response_code(404);
    echo json_encode($err, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ──────────────────────────────────────────────
// Step 3: Paginate feed API to get ALL posts
// ──────────────────────────────────────────────
log_msg("========================================");
log_msg("[STEP 3] Starting feed pagination for user_id=$user_id");
log_msg("========================================");

$all_urls = [];
$seen_pks = [];
$max_id = '';
$page_n = 0;
$more_available = true;
$next_max_id = '';
$consecutive_empty = 0;
$total_api_items = 0;
$total_retries = 0;
$rate_limit_hits = 0;

do {
    // Time check
    $elapsed = microtime(true) - $start_time;
    if ($elapsed > $max_time) {
        log_msg("[STEP 3] TIME LIMIT reached: " . round($elapsed) . "s > {$max_time}s");
        break;
    }

    $page_n++;
    if ($page_n > $max_pages) {
        log_msg("[STEP 3] PAGE LIMIT reached: $max_pages");
        break;
    }

    // Build URL
    $api_url = "https://www.instagram.com/api/v1/feed/user/$user_id/?count=33";
    if ($max_id !== '') {
        $api_url .= '&max_id=' . urlencode($max_id);
    }

    log_msg("[PAGE $page_n] Requesting: $api_url");
    log_msg("[PAGE $page_n] max_id=" . ($max_id ?: '(first page)'));

    // Retry loop with exponential backoff
    $resp = null;
    $max_retries = 10;
    $page_success = false;

    for ($retry = 0; $retry <= $max_retries; $retry++) {
        if ($retry > 0) {
            $backoff = min(30 * pow(2, $retry - 1), 900);
            $jitter = mt_rand(0, 30);
            $wait = $backoff + $jitter;
            $total_retries++;
            log_msg("[PAGE $page_n] RETRY $retry/$max_retries — backing off {$wait}s (backoff={$backoff}s + jitter={$jitter}s)");
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

        $req_duration = round(microtime(true) - $req_start, 2);

        log_msg("[PAGE $page_n] Response: HTTP {$resp['status']} | Body: " . strlen($resp['body']) . " bytes | Time: {$req_duration}s | cURL: " . ($resp['error'] ?: 'OK'));

        if ($resp['status'] === 200) {
            $page_success = true;
            break;
        }

        // Track rate limits
        if (in_array($resp['status'], [429, 401, 403])) {
            $rate_limit_hits++;
            log_msg("[PAGE $page_n] RATE LIMITED (HTTP {$resp['status']}) — total rate limit hits: $rate_limit_hits");

            // Refresh cookies on auth errors
            if (in_array($resp['status'], [401, 403]) && $retry === 0) {
                log_msg("[PAGE $page_n] Refreshing session cookies...");
                $refresh = fetch_url("https://www.instagram.com/$username/", [
                    'Accept: text/html,application/xhtml+xml,*/*',
                    'Sec-Fetch-Dest: document',
                    'Sec-Fetch-Mode: navigate',
                ], $cookie_jar);
                log_msg("[PAGE $page_n] Cookie refresh: HTTP {$refresh['status']}");

                // Re-extract CSRF
                if (file_exists($cookie_jar)) {
                    $cc = file_get_contents($cookie_jar);
                    if (preg_match('/csrftoken\s+(\S+)/', $cc, $cm2)) {
                        $csrf_token = $cm2[1];
                        log_msg("[PAGE $page_n] New CSRF token: " . substr($csrf_token, 0, 10) . "...");
                    }
                }
                sleep(5);
            }
        }

        if ($resp['status'] === 0) {
            log_msg("[PAGE $page_n] CONNECTION FAILED: {$resp['error']}");
        }
    }

    if (!$page_success) {
        log_msg("[PAGE $page_n] FAILED after $max_retries retries — stopping pagination");
        break;
    }

    // Parse JSON
    $body = $resp['body'];
    if (!preg_match('/^\s*[\{\$]/', $body)) {
        log_msg("[PAGE $page_n] ERROR: Response is not JSON — stopping");
        if ($debug) {
            log_msg("[PAGE $page_n] Body preview: " . substr($body, 0, 300));
        }
        break;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_msg("[PAGE $page_n] ERROR: JSON decode failed: " . json_last_error_msg());
        break;
    }

    $items = $data['items'] ?? [];
    $more_available = (bool)($data['more_available'] ?? false);
    $next_max_id = (string)($data['next_max_id'] ?? '');

    log_msg("[PAGE $page_n] Parsed: " . count($items) . " items | more_available=" . ($more_available ? 'true' : 'false') . " | next_max_id=" . ($next_max_id ? substr($next_max_id, 0, 20) . '...' : '(none)'));

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

    log_msg("[PAGE $page_n] Result: +$new_count new | $dupes dupes | Running total: " . count($all_urls) . " URLs");

    // Log a sample URL from this page (just the first new one)
    if ($new_count > 0) {
        $sample_idx = count($all_urls) - $new_count;
        log_msg("[PAGE $page_n] Sample URL: " . $all_urls[$sample_idx]);
    }

    // Detect stalls
    if ($new_count === 0) {
        $consecutive_empty++;
        log_msg("[PAGE $page_n] WARNING: Empty page ($consecutive_empty consecutive)");
        if ($consecutive_empty >= 3) {
            log_msg("[PAGE $page_n] STOPPING: 3 consecutive empty pages");
            break;
        }
    } else {
        $consecutive_empty = 0;
    }

    // Update cursor
    $max_id = $next_max_id;

    // Delay between pages
    if ($more_available && $next_max_id) {
        $delay = $delay_min + (mt_rand(0, 1000) / 1000.0) * ($delay_max - $delay_min);
        log_msg("[PAGE $page_n] Sleeping {$delay}s before next page...");
        usleep((int)($delay * 1000000));
    }

    // Progress summary every 10 pages
    if ($page_n % 10 === 0) {
        $elapsed = round(microtime(true) - $start_time, 1);
        $rate = $page_n > 0 ? round(count($all_urls) / ($elapsed / 60), 1) : 0;
        log_msg("──── PROGRESS: $page_n pages | " . count($all_urls) . " URLs | {$elapsed}s elapsed | ~{$rate} URLs/min | $total_retries retries | $rate_limit_hits rate limits ────");
    }

} while ($more_available && $next_max_id !== '');

// ──────────────────────────────────────────────
// Step 4: Final output
// ──────────────────────────────────────────────
cleanup($cookie_jar);

$duration = round(microtime(true) - $start_time, 2);
$stop_reason = 'unknown';
if (!$more_available) {
    $stop_reason = 'no_more_available';
} elseif ($next_max_id === '') {
    $stop_reason = 'no_next_max_id';
} elseif ($page_n > $max_pages) {
    $stop_reason = 'max_pages_reached';
} elseif ((microtime(true) - $start_time) > $max_time) {
    $stop_reason = 'max_time_reached';
} elseif ($consecutive_empty >= 3) {
    $stop_reason = 'consecutive_empty_pages';
} elseif (!$page_success) {
    $stop_reason = 'request_failed';
}

log_msg("========================================");
log_msg("SCRAPE COMPLETE for @$username");
log_msg("  Total URLs:        " . count($all_urls));
log_msg("  Pages fetched:     $page_n");
log_msg("  Total API items:   $total_api_items");
log_msg("  Unique PKs:        " . count($seen_pks));
log_msg("  Duration:          {$duration}s");
log_msg("  Total retries:     $total_retries");
log_msg("  Rate limit hits:   $rate_limit_hits");
log_msg("  Stop reason:       $stop_reason");
log_msg("========================================");

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
        'consecutive_empty_at_end' => $consecutive_empty,
    ];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;


// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Log a message with timestamp to PHP error_log
 */
function log_msg(string $msg): void {
    $timestamp = date('Y-m-d H:i:s');
    $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
    error_log("[get_posts][$timestamp][{$mem}MB] $msg");
}

/**
 * Fetch a URL using cURL with cookie persistence
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
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $total_time = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
    curl_close($ch);

    if ($curl_errno !== 0) {
        log_msg("cURL ERROR #$curl_errno: $curl_error | URL: $url | Time: {$total_time}s");
        return ['status' => 0, 'body' => '', 'error' => $curl_error];
    }

    // Log redirect if it happened
    if ($effective_url !== $url) {
        log_msg("cURL REDIRECT: $url -> $effective_url");
    }

    return ['status' => $status, 'body' => ($body !== false ? $body : ''), 'error' => ''];
}

/**
 * Convert an Instagram PK (big integer string) to a shortcode.
 * Uses bcmath to avoid integer overflow.
 */
function pk_to_shortcode(string $pk): string {
    $ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    if ($pk === '' || $pk === '0') {
        return '';
    }

    // bcmath (preferred)
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

    // Last resort (may overflow on large PKs)
    log_msg("WARNING: bcmath/gmp unavailable — shortcode may be incorrect for large PKs");
    $n = (int)$pk;
    $code = '';
    while ($n > 0) {
        $code = $ALPHA[$n % 64] . $code;
        $n = intdiv($n, 64);
    }
    return $code;
}

/**
 * Convert an API item to an Instagram URL.
 * Prefers the 'code' field (shortcode) from the API when available.
 */
function item_to_url(array $item): ?string {
    // Instagram API usually provides the shortcode directly
    $shortcode = (string)($item['code'] ?? '');

    // Fallback: compute from PK
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

    // media_type 2 = video | product_type clips = reel | igtv = old IGTV
    if ($media_type === 2 || $product_type === 'clips' || $product_type === 'igtv') {
        return "https://www.instagram.com/reel/$shortcode/";
    }

    return "https://www.instagram.com/p/$shortcode/";
}

/**
 * Clean up temporary cookie file
 */
function cleanup(string $cookie_jar): void {
    if ($cookie_jar !== '' && file_exists($cookie_jar)) {
        @unlink($cookie_jar);
        log_msg("Cleaned up cookie jar: $cookie_jar");
    }
}
?>
