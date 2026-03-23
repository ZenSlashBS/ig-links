<?php
/**
 * get_posts.php -- PHP Instagram post URL scraper API
 * VERSION: 2.3
 */

define('SCRIPT_VERSION', '2.3');

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

// ── Step 1: Fetch profile HTML ──
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

// ── Step 3: Test both App IDs ──
log_msg("[STEP 3] Testing API endpoints...");

$APP_IDS = [
    'mobile' => '1217981644879628',
    'web' => '936619743392459',
];

$working_app_id = null;
$test_data = null;

foreach ($APP_IDS as $label => $app_id) {
    log_msg("[STEP 3] Testing $label app_id=$app_id");

    $test_resp = fetch_url("www.instagram.com", "/api/v1/feed/user/$user_id/?count=12", [
        'X-IG-App-ID' => $app_id,
        'Referer' => "https://www.instagram.com/$username/",
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-origin',
    ]);

    log_msg("[STEP 3] $label: HTTP {$test_resp['status']} | " . strlen($test_resp['body']) . " bytes");
    log_msg("[STEP 3] $label preview: " . substr($test_resp['body'], 0, 300));

    if ($test_resp['status'] === 200 && preg_match('/^\s*\{/', $test_resp['body'])) {
        $parsed = json_decode($test_resp['body'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $item_count = count($parsed['items'] ?? []);
            $has_more = $parsed['more_available'] ?? false;
            $has_next = !empty($parsed['next_max_id'] ?? '');

            log_msg("[STEP 3] $label: items=$item_count more=" . ($has_more ? 'Y' : 'N') . " next=" . ($has_next ? 'Y' : 'N'));

            if ($item_count > 0) {
                $working_app_id = $app_id;
                $test_data = $parsed;
                log_msg("[STEP 3] WINNER: $label ($app_id)");
                break;
            }
        }
    }

    usleep(500000);
}

// GraphQL fallback
if (!$working_app_id) {
    log_msg("[STEP 3b] v1 API failed — trying GraphQL...");
    $result = try_graphql($user_id, $username, $start_time, $debug);
    if ($result !== null) {
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }

    log_msg("[STEP 3b] ALL METHODS FAILED");
    echo json_encode([
        'error' => 'Could not fetch posts — all API methods failed',
        'user_id' => $user_id,
        'version' => SCRIPT_VERSION,
    ]);
    exit;
}

// ── Step 4: Paginate ──
log_msg("============ PAGINATING (app_id=$working_app_id) ============");

$all_urls = [];
$seen_pks = [];
$max_id = '';
$page_n = 0;
$more_available = false;
$next_max_id = '';
$consecutive_empty = 0;
$total_retries = 0;
$rate_limit_hits = 0;

// Process test page
if ($test_data) {
    $page_n = 1;
    $items = $test_data['items'] ?? [];
    $more_available = (bool)($test_data['more_available'] ?? false);
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
    log_msg("[PAGE 1] (test) +$new_count | total=" . count($all_urls) . " | more=" . ($more_available ? 'Y' : 'N') . " | next=" . ($next_max_id ? substr($next_max_id, 0, 20) . '...' : 'NONE'));

    if ($new_count > 0) {
        log_msg("[PAGE 1] Sample: " . $all_urls[0]);
    }
}

// Continue paginating
while ($more_available && $max_id) {
    $page_n++;

    $path = "/api/v1/feed/user/$user_id/?count=12&max_id=" . urlencode($max_id);

    log_msg("[PAGE $page_n] GET max_id=" . substr($max_id, 0, 20) . "...");

    // Smart retry: up to 6 attempts with increasing backoff for rate limits
    $resp = null;
    $page_ok = false;
    $max_retries = 6;

    for ($retry = 0; $retry < $max_retries; $retry++) {
        if ($retry > 0) {
            // Exponential-ish backoff: 5s, 15s, 30s, 60s, 120s
            $wait = min(5 * pow(2, $retry), 120) + mt_rand(1, 10);
            $total_retries++;
            log_msg("[PAGE $page_n] RETRY $retry/$max_retries — backoff {$wait}s (total retries: $total_retries)");
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
        log_msg("[PAGE $page_n] Attempt " . ($retry + 1) . ": HTTP {$resp['status']} | " . strlen($resp['body']) . " bytes | {$req_time}s");

        if ($resp['status'] === 200) {
            $page_ok = true;
            break;
        }

        // Rate limit — worth retrying with longer wait
        if (in_array($resp['status'], [429, 401, 403])) {
            $rate_limit_hits++;
            log_msg("[PAGE $page_n] RATE LIMITED ({$resp['status']}) — hit #$rate_limit_hits — will retry with backoff");
            continue; // let the backoff loop handle it
        }

        // Other errors (500, 0, etc) — retry a couple times then give up
        if ($retry >= 2) {
            log_msg("[PAGE $page_n] Non-rate-limit error after 3 attempts — stopping");
            break;
        }
    }

    if (!$page_ok) {
        log_msg("[PAGE $page_n] FAILED after $max_retries attempts — trying to continue...");

        // Instead of stopping immediately, skip this cursor and try to recover
        // by fetching the profile page again to reset cookies/session
        log_msg("[PAGE $page_n] Refreshing session...");
        $refresh = fetch_url("www.instagram.com", "/$username/", [
            'Accept' => 'text/html,application/xhtml+xml,*/*',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
        ]);
        log_msg("[PAGE $page_n] Session refresh: HTTP {$refresh['status']}");
        sleep(10 + mt_rand(0, 5));

        // One more try after session refresh
        $resp = fetch_url("www.instagram.com", $path, [
            'X-IG-App-ID' => $working_app_id,
            'Referer' => "https://www.instagram.com/$username/",
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
        ]);

        log_msg("[PAGE $page_n] Post-refresh attempt: HTTP {$resp['status']} | " . strlen($resp['body']) . " bytes");

        if ($resp['status'] !== 200) {
            log_msg("[PAGE $page_n] Still failing after session refresh — stopping pagination");
            break;
        }

        $page_ok = true;
    }

    // Parse JSON
    $body = $resp['body'];
    if (!preg_match('/^\s*\{/', $body)) {
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

    // Adaptive delay: slow down if we've been rate limited
    if ($more_available && $max_id) {
        if ($rate_limit_hits > 0) {
            // Been rate limited before — use slower delay: 2-4s
            $delay_us = 2000000 + mt_rand(0, 2000000);
            log_msg("[PAGE $page_n] Using slow delay (rate limited before): " . round($delay_us / 1000000, 1) . "s");
        } else {
            // No rate limits yet — use original fast delay: 0.6-1.1s
            $delay_us = 600000 + mt_rand(0, 500000);
        }
        usleep($delay_us);
    }

    if ($page_n % 25 === 0) {
        $elapsed = round(microtime(true) - $start_time, 1);
        $rate = count($all_urls) > 0 ? round(count($all_urls) / ($elapsed / 60), 1) : 0;
        log_msg("──── PROGRESS: $page_n pages | " . count($all_urls) . " URLs | {$elapsed}s | ~{$rate}/min | rate_limits=$rate_limit_hits ────");
    }
}

// ── Final output ──
$duration = round(microtime(true) - $start_time, 2);

$stop_reason = 'unknown';
if (!$more_available && empty($max_id)) $stop_reason = 'reached_end';
elseif (!$more_available) $stop_reason = 'no_more_available';
elseif (empty($max_id)) $stop_reason = 'no_next_cursor';
elseif ($consecutive_empty >= 3) $stop_reason = 'empty_pages';

log_msg("============ DONE @$username v" . SCRIPT_VERSION . " ============");
log_msg("URLs=" . count($all_urls) . " | Pages=$page_n | Time={$duration}s | Retries=$total_retries | RateLimits=$rate_limit_hits | Stop=$stop_reason");

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

function try_graphql($user_id, $username, $start_time, $debug) {
    log_msg("[GQL] Trying GraphQL endpoint...");

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

    log_msg("[GQL] HTTP {$gql_resp['status']} | " . strlen($gql_resp['body']) . " bytes");
    log_msg("[GQL] Preview: " . substr($gql_resp['body'], 0, 300));

    if ($gql_resp['status'] !== 200) {
        return null;
    }

    $gql_data = json_decode($gql_resp['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    $media_data = $gql_data['data']['user']['edge_owner_to_timeline_media'] ?? null;
    if (!$media_data) {
        return null;
    }

    $edges = $media_data['edges'] ?? [];
    if (count($edges) === 0) {
        return null;
    }

    log_msg("[GQL] First page: " . count($edges) . " edges");

    $all_urls = [];
    $seen = [];
    $page_n = 0;
    $has_next = $media_data['page_info']['has_next_page'] ?? false;
    $end_cursor = $media_data['page_info']['end_cursor'] ?? '';

    foreach ($edges as $edge) {
        $node = $edge['node'] ?? [];
        $sc = $node['shortcode'] ?? '';
        if (!$sc || isset($seen[$sc])) continue;
        $seen[$sc] = true;
        $is_video = $node['is_video'] ?? false;
        $all_urls[] = $is_video
            ? "https://www.instagram.com/reel/$sc/"
            : "https://www.instagram.com/p/$sc/";
    }
    $page_n = 1;
    log_msg("[GQL PAGE 1] +" . count($all_urls) . " URLs | more=" . ($has_next ? 'Y' : 'N'));

    while ($has_next && $end_cursor) {
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

        if ($gr['status'] !== 200) break;

        $gd = json_decode($gr['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) break;

        $md = $gd['data']['user']['edge_owner_to_timeline_media'] ?? [];
        $gedges = $md['edges'] ?? [];
        $has_next = $md['page_info']['has_next_page'] ?? false;
        $end_cursor = $md['page_info']['end_cursor'] ?? '';

        $nc = 0;
        foreach ($gedges as $ge) {
            $gn = $ge['node'] ?? [];
            $gsc = $gn['shortcode'] ?? '';
            if (!$gsc || isset($seen[$gsc])) continue;
            $seen[$gsc] = true;
            $gvid = $gn['is_video'] ?? false;
            $all_urls[] = $gvid
                ? "https://www.instagram.com/reel/$gsc/"
                : "https://www.instagram.com/p/$gsc/";
            $nc++;
        }

        log_msg("[GQL PAGE $page_n] +$nc new | total=" . count($all_urls) . " | more=" . ($has_next ? 'Y' : 'N'));
        if ($nc === 0) break;
    }

    $duration = round(microtime(true) - $start_time, 2);
    log_msg("[GQL] DONE: " . count($all_urls) . " URLs in {$duration}s");

    return [
        'username' => $username,
        'user_id' => $user_id,
        'count' => count($all_urls),
        'pages_fetched' => $page_n,
        'duration_seconds' => $duration,
        'stop_reason' => $has_next ? 'error' : 'reached_end',
        'method' => 'graphql',
        'version' => SCRIPT_VERSION,
        'urls' => $all_urls,
    ];
}

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
        while (gmp_cmp($n, gmp_init(0)) > 0) {
            $remainder = gmp_intval(gmp_mod($n, gmp_init(64)));
            $code = $ALPHA[$remainder] . $code;
            $n = gmp_div_q($n, gmp_init(64));
        }
        return $code;
    }

    $n = (int)$pk;
    $code = '';
    while ($n > 0) {
        $code = $ALPHA[$n % 64] . $code;
        $n = intdiv($n, 64);
    }
    return $code;
}

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

// VERSION: 2.3
?>
