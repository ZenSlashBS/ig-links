<?php
/**
 * get_posts.php -- PHP Instagram post URL scraper API.
 * VERSION: 2.4
 *
 * Strategy:
 *   1. Try v1 feed API (both app IDs)
 *   2. If rate-limited mid-scrape, switch to GraphQL for remaining posts
 *   3. If v1 never works, use GraphQL from the start
 */

define('SCRIPT_VERSION', '2.4');

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

// ── Step 3: Scrape using v1 API first, fallback to GraphQL ──
log_msg("[STEP 3] Starting scrape for user_id=$user_id");

$all_urls = [];
$seen_pks = [];
$page_n = 0;
$total_retries = 0;
$rate_limit_hits = 0;
$method_used = 'v1_feed_api';
$app_id_used = '';

// --- Try v1 API with both app IDs ---
$APP_IDS = [
    'mobile' => '1217981644879628',
    'web' => '936619743392459',
];

$working_app_id = null;
$max_id = '';
$more_available = false;

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

    if ($test_resp['status'] === 200 && preg_match('/^\s*\{/', $test_resp['body'])) {
        $parsed = json_decode($test_resp['body'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $items = $parsed['items'] ?? [];
            $item_count = count($items);
            $more_available = (bool)($parsed['more_available'] ?? false);
            $max_id = (string)($parsed['next_max_id'] ?? '');

            log_msg("[STEP 3] $label: items=$item_count more=" . ($more_available ? 'Y' : 'N') . " next=" . ($max_id ? 'Y' : 'N'));

            if ($item_count > 0) {
                $working_app_id = $app_id;
                $app_id_used = $app_id;
                $page_n = 1;

                foreach ($items as $item) {
                    $pk = (string)($item['pk'] ?? $item['id'] ?? '');
                    if (!$pk || isset($seen_pks[$pk])) continue;
                    $seen_pks[$pk] = true;
                    $url = item_to_url($item);
                    if ($url) $all_urls[] = $url;
                }

                log_msg("[PAGE 1] (test) +" . count($all_urls) . " | more=" . ($more_available ? 'Y' : 'N'));
                if (count($all_urls) > 0) log_msg("[PAGE 1] Sample: " . $all_urls[0]);
                break;
            }
        }
    }
    usleep(500000);
}

// --- Continue v1 API pagination ---
$v1_failed = false;

if ($working_app_id && $more_available && $max_id) {
    log_msg("============ V1 PAGINATION (app_id=$working_app_id) ============");

    while ($more_available && $max_id) {
        $page_n++;
        $path = "/api/v1/feed/user/$user_id/?count=12&max_id=" . urlencode($max_id);

        log_msg("[PAGE $page_n] GET max_id=" . substr($max_id, 0, 20) . "...");

        $resp = null;
        $page_ok = false;

        for ($retry = 0; $retry < 4; $retry++) {
            if ($retry > 0) {
                $wait = min(10 * pow(2, $retry - 1), 60) + mt_rand(1, 5);
                $total_retries++;
                log_msg("[PAGE $page_n] RETRY $retry/4 — wait {$wait}s");
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

            // Log the actual error response so we can see what's happening
            if ($resp['status'] !== 200) {
                log_msg("[PAGE $page_n] ERROR BODY: " . substr($resp['body'], 0, 500));
            }

            if ($resp['status'] === 200) {
                $page_ok = true;
                break;
            }

            if (in_array($resp['status'], [429, 401, 403])) {
                $rate_limit_hits++;
                log_msg("[PAGE $page_n] RATE LIMITED ({$resp['status']}) — hit #$rate_limit_hits");
            }
        }

        if (!$page_ok) {
            log_msg("[PAGE $page_n] V1 API FAILED — switching to GraphQL for remaining posts");
            $v1_failed = true;
            break;
        }

        $body = $resp['body'];
        if (!preg_match('/^\s*\{/', $body)) {
            log_msg("[PAGE $page_n] NOT JSON — switching to GraphQL");
            $v1_failed = true;
            break;
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_msg("[PAGE $page_n] JSON ERROR — switching to GraphQL");
            $v1_failed = true;
            break;
        }

        $items = $data['items'] ?? [];
        $more_available = (bool)($data['more_available'] ?? false);
        $next_max_id = (string)($data['next_max_id'] ?? '');

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

        log_msg("[PAGE $page_n] +$new_count new | total=" . count($all_urls) . " | more=" . ($more_available ? 'Y' : 'N') . " | next=" . ($next_max_id ? substr($next_max_id, 0, 20) . '...' : 'NONE'));
        if ($new_count > 0) log_msg("[PAGE $page_n] Sample: " . $all_urls[count($all_urls) - $new_count]);

        $max_id = $next_max_id;

        if ($more_available && $max_id) {
            $delay = ($rate_limit_hits > 0) ? 2000000 + mt_rand(0, 2000000) : 600000 + mt_rand(0, 500000);
            usleep($delay);
        }

        if ($page_n % 25 === 0) {
            $elapsed = round(microtime(true) - $start_time, 1);
            log_msg("──── PROGRESS: $page_n pages | " . count($all_urls) . " URLs | {$elapsed}s ────");
        }
    }
}

// --- GraphQL fallback: either v1 never worked, or it got rate-limited mid-scrape ---
$need_graphql = (!$working_app_id) || ($v1_failed && $more_available);

if ($need_graphql) {
    log_msg("============ GRAPHQL FALLBACK ============");
    log_msg("[GQL] Already have " . count($all_urls) . " URLs from v1 API");
    log_msg("[GQL] Will fetch ALL posts via GraphQL and merge (deduplicating)");

    if ($v1_failed) {
        $method_used = 'v1_then_graphql';
    } else {
        $method_used = 'graphql';
    }

    // Wait before switching to GraphQL to let rate limit cool down
    if ($rate_limit_hits > 0) {
        $cooldown = 15 + mt_rand(0, 10);
        log_msg("[GQL] Cooling down {$cooldown}s before GraphQL...");
        sleep($cooldown);
    }

    // Re-fetch profile page to get fresh session
    log_msg("[GQL] Refreshing session...");
    $refresh = fetch_url("www.instagram.com", "/$username/", [
        'Accept' => 'text/html,application/xhtml+xml,*/*',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
    ]);
    log_msg("[GQL] Session refresh: HTTP {$refresh['status']}");
    sleep(2);

    $graphql_hash = '69cba40317214236af40e7efa697781d';
    $has_next = true;
    $end_cursor = '';
    $gql_page = 0;
    $consecutive_gql_fails = 0;

    while ($has_next) {
        $gql_page++;
        $page_n++;

        $vars = ['id' => $user_id, 'first' => 12];
        if ($end_cursor) $vars['after'] = $end_cursor;

        $gql_path = "/graphql/query/?query_hash=$graphql_hash&variables=" . urlencode(json_encode($vars));

        log_msg("[GQL PAGE $gql_page] GET cursor=" . ($end_cursor ? substr($end_cursor, 0, 20) . '...' : '(first)'));

        // Retry for GraphQL too
        $gr = null;
        $gql_ok = false;

        for ($retry = 0; $retry < 4; $retry++) {
            if ($retry > 0) {
                $wait = min(10 * pow(2, $retry - 1), 60) + mt_rand(1, 5);
                $total_retries++;
                log_msg("[GQL PAGE $gql_page] RETRY $retry/4 — wait {$wait}s");
                sleep($wait);
            }

            $req_start = microtime(true);
            $gr = fetch_url("www.instagram.com", $gql_path, [
                'X-IG-App-ID' => '936619743392459',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => "https://www.instagram.com/$username/",
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-origin',
            ]);
            $req_time = round(microtime(true) - $req_start, 2);

                        log_msg("[GQL PAGE $gql_page] Attempt " . ($retry + 1) . ": HTTP {$gr['status']} | " . strlen($gr['body']) . " bytes | {$req_time}s");

            if ($gr['status'] !== 200) {
                log_msg("[GQL PAGE $gql_page] ERROR BODY: " . substr($gr['body'], 0, 500));
                if (in_array($gr['status'], [429, 401, 403])) {
                    $rate_limit_hits++;
                    log_msg("[GQL PAGE $gql_page] RATE LIMITED — hit #$rate_limit_hits");
                }
                continue;
            }

            $gql_ok = true;
            break;
        }

        if (!$gql_ok) {
            $consecutive_gql_fails++;
            log_msg("[GQL PAGE $gql_page] FAILED after retries (consecutive=$consecutive_gql_fails)");
            if ($consecutive_gql_fails >= 2) {
                log_msg("[GQL] STOPPING: 2 consecutive failures");
                break;
            }
            sleep(30 + mt_rand(0, 15));
            continue;
        }

        $consecutive_gql_fails = 0;

        $gd = json_decode($gr['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_msg("[GQL PAGE $gql_page] JSON ERROR: " . json_last_error_msg());
            break;
        }

        $md = $gd['data']['user']['edge_owner_to_timeline_media'] ?? null;
        if (!$md) {
            log_msg("[GQL PAGE $gql_page] No media data in response");
            log_msg("[GQL PAGE $gql_page] Response keys: " . implode(', ', array_keys($gd)));
            break;
        }

        $gedges = $md['edges'] ?? [];
        $has_next = (bool)($md['page_info']['has_next_page'] ?? false);
        $end_cursor = (string)($md['page_info']['end_cursor'] ?? '');

        $nc = 0;
        $dupes = 0;
        foreach ($gedges as $ge) {
            $gn = $ge['node'] ?? [];
            $gsc = $gn['shortcode'] ?? '';
            if (!$gsc) continue;

            // Deduplicate against v1 results using shortcode
            if (isset($seen_pks[$gsc])) {
                $dupes++;
                continue;
            }
            $seen_pks[$gsc] = true;

            // Also deduplicate by pk if present
            $gpk = (string)($gn['id'] ?? '');
            if ($gpk && isset($seen_pks[$gpk])) {
                $dupes++;
                continue;
            }
            if ($gpk) $seen_pks[$gpk] = true;

            $gvid = $gn['is_video'] ?? false;
            $all_urls[] = $gvid
                ? "https://www.instagram.com/reel/$gsc/"
                : "https://www.instagram.com/p/$gsc/";
            $nc++;
        }

        log_msg("[GQL PAGE $gql_page] +$nc new | $dupes dupes | total=" . count($all_urls) . " | more=" . ($has_next ? 'Y' : 'N') . " | cursor=" . ($end_cursor ? substr($end_cursor, 0, 20) . '...' : 'NONE'));

        if ($nc > 0) {
            log_msg("[GQL PAGE $gql_page] Sample: " . $all_urls[count($all_urls) - $nc]);
        }

        if ($nc === 0 && $dupes === 0) {
            log_msg("[GQL PAGE $gql_page] No items at all — stopping");
            break;
        }

        if ($has_next && $end_cursor) {
            $delay = ($rate_limit_hits > 0) ? 2000000 + mt_rand(0, 2000000) : 600000 + mt_rand(0, 500000);
            usleep($delay);
        }

        if ($gql_page % 25 === 0) {
            $elapsed = round(microtime(true) - $start_time, 1);
            log_msg("──── GQL PROGRESS: $gql_page pages | " . count($all_urls) . " URLs | {$elapsed}s ────");
        }
    }
}

// ── Final output ──
$duration = round(microtime(true) - $start_time, 2);

$stop_reason = 'unknown';
if (!$more_available && !($has_next ?? false)) $stop_reason = 'reached_end';
elseif (!($has_next ?? true)) $stop_reason = 'reached_end';
elseif (!$more_available && !$v1_failed) $stop_reason = 'no_more_available';
elseif (($consecutive_gql_fails ?? 0) >= 2) $stop_reason = 'graphql_failures';
elseif ($v1_failed && !$need_graphql) $stop_reason = 'v1_rate_limited';

log_msg("============ DONE @$username v" . SCRIPT_VERSION . " ============");
log_msg("URLs=" . count($all_urls) . " | Pages=$page_n | Time={$duration}s | Retries=$total_retries | RateLimits=$rate_limit_hits | Method=$method_used | Stop=$stop_reason");

$response = [
    'username' => $username,
    'user_id' => $user_id,
    'count' => count($all_urls),
    'pages_fetched' => $page_n,
    'duration_seconds' => $duration,
    'stop_reason' => $stop_reason,
    'method' => $method_used,
    'app_id_used' => $app_id_used,
    'rate_limit_hits' => $rate_limit_hits,
    'total_retries' => $total_retries,
    'version' => SCRIPT_VERSION,
    'urls' => $all_urls,
];

if ($debug) {
    $response['debug'] = [
        'unique_pks' => count($seen_pks),
        'v1_failed_mid_scrape' => $v1_failed ?? false,
        'graphql_used' => $need_graphql ?? false,
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

// VERSION: 2.4
?>
