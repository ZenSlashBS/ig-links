<?php
/**
 * get_posts.php -- PHP Instagram post URL scraper API.
 * Pure HTTP, no login. Returns JSON array of post/reel URLs.
 *
 * VERSION: 2.1
 *
 * Usage: GET /get_posts.php?username=example
 *        GET /get_posts.php?username=example&debug=1
 */

define('SCRIPT_VERSION', '2.1');

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
    echo json_encode(['error' => 'Username parameter required', 'version' => SCRIPT_VERSION]);
    exit;
}

$username = trim($_GET['username']);
$debug = isset($_GET['debug']) && $_GET['debug'];

log_msg("============ START v" . SCRIPT_VERSION . " @$username ============");

$start_time = microtime(true);

// ── Step 1: Fetch profile HTML -> user_id ──
log_msg("[STEP 1] Fetching profile page...");

$html = fetch_url("www.instagram.com", "/$username/", [
    'Accept' => 'text/html,application/xhtml+xml,*/*',
    'Sec-Fetch-Dest' => 'document',
    'Sec-Fetch-Mode' => 'navigate',
    'Upgrade-Insecure-Requests' => '1',
]);

log_msg("[STEP 1] HTTP {$html['status']} | " . strlen($html['body']) . " bytes");

if ($html['status'] !== 200) {
    http_response_code($html['status']);
    echo json_encode(['error' => "HTTP {$html['status']} fetching profile", 'version' => SCRIPT_VERSION]);
    exit;
}

// ── Step 2: Extract user_id ──
$user_id = null;

if (preg_match('/"user_id":"(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] user_id=$user_id (user_id field)");
}
if (!$user_id && preg_match('/"profilePage_(\d+)"/', $html['body'], $m)) {
    $user_id = $m[1];
    log_msg("[STEP 2] user_id=$user_id (profilePage)");
}

if (!$user_id) {
    log_msg("[STEP 2] FAILED — no user_id");
    http_response_code(404);
    $err = ['error' => 'Could not find user_id', 'version' => SCRIPT_VERSION];
    if ($debug) {
        $err['html_length'] = strlen($html['body']);
        $err['html_preview'] = substr($html['body'], 0, 1000);
    }
    echo json_encode($err, JSON_PRETTY_PRINT);
    exit;
}

// ── Step 3: Try to find which App ID works ──
log_msg("[STEP 3] Testing API with both App IDs...");

$APP_IDS = [
    'mobile' => '1217981644879628',
    'web'    => '936619743392459',
];

$working_app_id = null;
$test_data = null;

foreach ($APP_IDS as $label => $app_id) {
    log_msg("[STEP 3] Testing $label app_id=$app_id ...");

    $test_resp = fetch_url("www.instagram.com", "/api/v1/feed/user/$user_id/?count=12", [
        'X-IG-App-ID' => $app_id,
        'Referer' => "https://www.instagram.com/$username/",
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-origin',
    ]);

    log_msg("[STEP 3] $label: HTTP {$test_resp['status']} | " . strlen($test_resp['body']) . " bytes");

    if ($test_resp['status'] === 200 && preg_match('/^\s*\{/', $test_resp['body'])) {
        $parsed = json_decode($test_resp['body'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $item_count = count($parsed['items'] ?? []);
            $has_more = $parsed['more_available'] ?? false;
            $has_next = !empty($parsed['next_max_id'] ?? '');

            log_msg("[STEP 3] $label: items=$item_count more_available=" . ($has_more ? 'Y' : 'N') . " has_next_max_id=" . ($has_next ? 'Y' : 'N'));

            // Log first 300 chars of raw response for debugging
            log_msg("[STEP 3] $label raw preview: " . substr($test_resp['body'], 0, 300));

            if ($item_count > 0) {
                $working_app_id = $app_id;
                $test_data = $parsed;
                log_msg("[STEP 3] WINNER: $label ($app_id) returned $item_count items");
                break;
            }
        }
    } else {
        log_msg("[STEP 3] $label: FAILED — body preview: " . substr($test_resp['body'], 0, 200));
    }

    usleep(500000); // 0.5s between tests
}

// If neither worked, try the graphql endpoint as last resort
if (!$working_app_id) {
    log_msg("[STEP 3] Both App IDs failed. Trying GraphQL endpoint...");

    $graphql_hash = '69cba40317214236af40e7efa697781d';
    $variables = json_encode(['id' => $user_id, 'first' => 12]);
    $gql_path = "/graphql/query/?query_hash=$graphql_hash&variables=" . urlencode($variables);

    $gql_resp = fetch_url("www.instagram.com", $gql_path, [
        'X-IG-App-ID' => '936619743392459',
        'X-Requested-With' => 'XMLHttpRequest',
        'Referer' => "https://www.instagram.com/$username/",
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-origin',
    ]);

    log_msg("[STEP 3] GraphQL: HTTP {$gql_resp['status']} | " . strlen($gql_resp['body']) . " bytes");
    log_msg("[STEP 3] GraphQL preview: " . substr($gql_resp['body'], 0, 300));

    if ($gql_resp['status'] === 200) {
        $gql_data = json_decode($gql_resp['body'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $edges = $gql_data['data']['user']['edge_owner_to_timeline_media']['edges'] ?? [];
            if (count($edges) > 0) {
                log_msg("[STEP 3] GraphQL returned " . count($edges) . " edges — using GraphQL mode");

                // Switch to GraphQL pagination
                $all_urls = [];
                $seen_pks = [];
                $page_n = 0;
                $has_next_page = true;
                $end_cursor = '';

                // Process first batch
                $media_data = $gql_data['data']['user']['edge_owner_to_timeline_media'];
                $has_next_page = $media_data['page_info']['has_next_page'] ?? false;
                $end_cursor = $media_data['page_info']['end_cursor'] ?? '';

                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    $sc = $node['shortcode'] ?? '';
                    if (!$sc || isset($seen_pks[$sc])) continue;
                    $seen_pks[$sc] = true;
                    $is_video = $node['is_video'] ?? false;
                    $all_urls[] = $is_video
                        ? "https://www.instagram.com/reel/$sc/"
                        : "https://www.instagram.com/p/$sc/";
                }
                $page_n = 1;
                log_msg("[GQL PAGE 1] +" . count($all_urls) . " URLs | more=$has_next_page");

                while ($has_next_page && $end_cursor) {
                    $page_n++;
                    usleep(600000 + mt_rand(0, 500000));

                    $vars = json_encode(['id' => $user_id, 'first' => 12, 'after' => $end_cursor]);
                    $gpath = "/graphql/query/?query_hash=$graphql_hash&variables=" . urlencode($vars);

                    $gr = fetch_url("www.instagram.com", $gpath, [
                        'X-IG-App-ID' => '936619743392459',
                        'X-Requested-With' => 'XMLHttpRequest',
                        'Referer' => "https://www.instagram.com/$username/",
                        'Sec-Fetch-Dest' => 'empty',
                        'Sec-Fetch-Mode' => 'cors',
                        'Sec-Fetch-Site' => 'same-origin',
                    ]);

                    log_msg("[GQL PAGE $page_n] HTTP {$gr['status']} | " . strlen($gr['body']) . " bytes");

                    if ($gr['status'] !== 200) {
                        log_msg("[GQL PAGE $page_n] FAILED");
                        break;
                    }

                    $gd = json_decode($gr['body'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) break;

                    $md = $gd['data']['user']['edge_owner_to_timeline_media'] ?? [];
                    $gedges = $md['edges'] ?? [];
                    $has_next_page = $md['page_info']['has_next_page'] ?? false;
                    $end_cursor = $md['page_info']['end_cursor'] ?? '';

                    $nc = 0;
                    foreach ($gedges as $ge) {
                        $gn = $ge['node'] ?? [];
                        $gsc = $gn['shortcode'] ?? '';
                        if (!$gsc || isset($seen_pks[$gsc])) continue;
                        $seen_pks[$gsc] = true;
                        $gvid = $gn['is_video'] ?? false;
                        $all_urls[] = $gvid
                            ? "https://www.instagram.com/reel/$gsc/"
                            : "https://www.instagram.com/p/$gsc/";
                        $nc++;
                    }

                    log_msg("[GQL PAGE $page_n] +$nc new | total=" . count($all_urls) . " | more=$has_next_page");

                    if ($nc === 0) break;

                    if ($page_n % 25 === 0) {
                        $elapsed = round(microtime(true) - $start_time, 1);
                        log_msg("──── PROGRESS: $page_n pages | " . count($all_urls) . " URLs | {$elapsed}s ────");
                    }
                }

                // Output GraphQL results
                $duration = round(microtime(true) - $start_time, 2);
                log_msg("============ DONE (GraphQL) @$username v" . SCRIPT_VERSION . " ============");
                log_msg("URLs=" . count($all_urls) . " | Pages=$page_n | Time={$duration}s");

                echo json_encode([
                    'username' => $username,
                    'user_id' => $user_id,
                    'count' => count($all_urls),
                    'pages_fetched' => $page_n,
                    'duration_seconds' => $duration,
                    'stop_reason' => $has_next_page ? 'error' : 'reached_end',
                    'method' => 'graphql',
                    'version' => SCRIPT_VERSION,
                    'urls' => $all_urls,
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }

    // Nothing worked
    log_msg("[STEP 3] ALL METHODS FAILED");
    echo json_encode([
        'error' => 'Could not fetch posts — all API methods failed',
        'user_id' => $user_id,
        'version' => SCRIPT_VERSION,
    ]);
    exit;
}

// ── Step 4: Paginate using the working App ID ──
log_msg("============ PAGINATING (v1 API, app_id=$working_app_id) ============");

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

// Process the test page we already fetched (don't waste it)
if ($test_data) {
    $page_n = 1;
    $items = $test_data['items'] ?? [];
    $more_available = $test_data['more_available'] ?? false;
    $next_max_id = (string)($test_data['next_max_id'] ?? '');

    $new_count = 0;
    foreach ($items as $item) {
        $pk = (string)($item['pk'] ?? $item['id'] ?? '');
        if (!$pk || isset($seen_pks[$pk])) continue;
        $seen_pks[$pk] = true;
        $url = item_to_url($item);
        if ($url) {
            $all_urls[] = $url;
            $new_count++;
        }
    }

    $max_id = $next_max_id;
    log_msg("[PAGE 1] (from test) +$new_count new | total=" . count($all_urls) . " | more=" . ($more_available ? 'Y' : 'N') . " | next=" . ($next_max_id ? substr($next_max_id, 0, 20) . '...' : 'NONE'));

    if ($new_count > 0) {
        log_msg("[PAGE 1] Sample: " . $all_urls[0]);
    }
}

// Continue paginating
    // Retry loop
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

        $resp = fetch_url("www.instagram.com", $path, [
            'X-IG-App-ID' => $working_app_id,
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
            log_msg("[PAGE $page_n] RATE LIMITED ({$resp['status']}) — total: $rate_limit_hits");
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
    $more_available = (bool)($data['more_available'] ?? false);
    $next_max_id = (string)($data['next_max_id'] ?? '');

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
    if ($more_available && $max_id) {
        usleep(600000 + mt_rand(0, 500000));
    }

    // Progress every 25 pages
    if ($page_n % 25 === 0) {
        $elapsed = round(microtime(true) - $start_time, 1);
        $rate = count($all_urls) > 0 ? round(count($all_urls) / ($elapsed / 60), 1) : 0;
        log_msg("──── PROGRESS: $page_n pages | " . count($all_urls) . " URLs | {$elapsed}s | ~{$rate}/min ────");
    }
}

// ── Output ──
$duration = round(microtime(true) - $start_time, 2);

$stop_reason = 'unknown';
if (!$more_available && empty($max_id)) $stop_reason = 'reached_end';
elseif (!$more_available) $stop_reason = 'no_more_available';
elseif (empty($max_id)) $stop_reason = 'no_next_cursor';
elseif ($consecutive_empty >= 3) $stop_reason = 'empty_pages';
elseif ($consecutive_fails >= 2) $stop_reason = 'request_failures';

log_msg("============ DONE @$username v" . SCRIPT_VERSION . " ============");
log_msg("URLs=" . count($all_urls) . " | Pages=$page_n | Time={$duration}s | Retries=$total_retries | RateLimits=$rate_limit_hits | Stop=$stop_reason");
log_msg("==========================================");

$response = [
    'username' => $username,
    'user_id' => $user_id,
    'count' => count($all_urls),
    'pages_fetched' => $page_n,
    'duration_seconds' => $duration,
    'stop_reason' => $stop_reason,
    'method' => 'v1_feed_api',
    'app_id_used' => $working_app_id,
    'version' => SCRIPT_VERSION,
    'urls' => $all_urls,
];

if ($debug) {
    $response['debug'] = [
        'unique_pks' => count($seen_pks),
        'total_retries' => $total_retries,
        'rate_limit_hits' => $rate_limit_hits,
        'final_more_available' => $more_available,
        'final_next_max_id' => $next_max_id ?? '',
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

/**
 * HTTP GET — same signature as working code: fetch_url($hostname, $path, $assoc_headers)
 */
function fetch_url($hostname, $path, $extra_headers = []) {
    $url = "https://$hostname$path";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
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
        log_msg("cURL ERROR #$errno: $error | $url");
        return ['status' => 0, 'body' => ''];
    }

    return ['status' => $status, 'body' => ($body !== false ? $body : '')];
}

/**
 * Convert PK to shortcode — bcmath for big integers
 */
function pk_to_shortcode($pk) {
    $ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $pk = (string)$pk;

    if ($pk === '' || $pk === '0') return '';

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

    log_msg("WARNING: bcmath/gmp unavailable");
    $n = (int)$pk;
    $code = '';
    while ($n > 0) {
        $code = $ALPHA[$n % 64] . $code;
        $n = intdiv($n, 64);
    }
    return $code;
}

/**
 * Convert API item to URL — prefers 'code' field from API
 */
function item_to_url($item) {
    $sc = (string)($item['code'] ?? '');

    if ($sc === '') {
        $pk = $item['pk'] ?? $item['id'] ?? '';
        if (!$pk) return null;
        $sc = pk_to_shortcode((string)$pk);
        if ($sc === '') return null;
    }

    $media_type = $item['media_type'] ?? 1;
    $product_type = $item['product_type'] ?? '';

    if ($media_type == 2 || $product_type == 'clips' || $product_type == 'igtv') {
        return "https://www.instagram.com/reel/$sc/";
    }
    return "https://www.instagram.com/p/$sc/";
}

// VERSION: 2.1
?>
