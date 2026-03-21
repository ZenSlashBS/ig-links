<?php
/**
 * get_posts.php -- PHP Instagram post URL scraper API.
 * Pure HTTP, no login. Returns JSON array of post/reel URLs.
 * 
 * Usage: GET /get_posts.php?username=example
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

$username = trim($_GET['username'] ?? '');
$max_pages = (int)($_GET['max_pages'] ?? 500); // Increased for comprehensive scraping
$delay_min = (int)($_GET['delay_min'] ?? 3);    // Min delay between requests (seconds)
$delay_max = (int)($_GET['delay_max'] ?? 7);    // Max delay between requests (seconds)
$max_total_time = (int)($_GET['max_time'] ?? 3600); // Max runtime 1hr default
error_log("Fetching posts for @$username");

$start_time = microtime(true);

// Step 1: Get profile HTML -> user_id
$html = fetch_url("www.instagram.com", "/$username/", [
    'Accept' => 'text/html,application/xhtml+xml,*/*',
    'Sec-Fetch-Dest' => 'document',
    'Sec-Fetch-Mode' => 'navigate',
    'Upgrade-Insecure-Requests' => '1',
]);

error_log("Profile HTML: status={$html['status']}, length=" . strlen($html['body']));
error_log("Config: max_pages=$max_pages, delay=$delay_min-$delay_max, max_time=$max_total_time");

if ($html['status'] !== 200) {
    http_response_code($html['status']);
    echo json_encode(['error' => "HTTP {$html['status']} fetching profile"]);
    exit;
}

$debug = isset($_GET['debug']) && $_GET['debug'];

if (preg_match('/"user_id":"(\d+)"/', $html['body'], $matches)) {
    $user_id = $matches[1];
} else {
    $error_details = [
        'error' => 'Could not find user_id',
        'html_length' => strlen($html['body']),
        'html_preview' => substr($html['body'], 0, 1000),
        'regex_test' => preg_match('/"user_id"/', $html['body']) ? 'user_id key found, no match' : 'user_id key missing'
    ];
    if ($debug) {
        echo json_encode($error_details, JSON_PRETTY_PRINT);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Could not find user_id (private/deleted/geo-blocked?)']);
    }
    exit;
}

error_log("user_id = $user_id");

// Step 2: Paginate API
$all_urls = [];
$seen_pks = [];
$max_id = '';
$page_n = 0;

// Check total runtime limit before each page
do {
    if ((microtime(true) - $start_time) > $max_total_time) {
        error_log("Hit max total time: " . round(microtime(true) - $start_time, 1) . "s");
        break;
    }
    
    $page_n++;
    if ($page_n > $max_pages) {
        error_log("Hit max_pages limit: $max_pages");
        break;
    }
    
    $path = "/api/v1/feed/user/$user_id/?count=50"; // More posts per page
=======
    if ($max_id) {
        $path .= '&max_id=' . urlencode($max_id);
    }

    // Retry logic: up to 3 attempts with backoff
    $retry = 0;
    $max_retries = 3;
    $resp = null;
    do {
        $resp = fetch_url("www.instagram.com", $path, [
            'X-IG-App-ID' => '1217981644879628',
            'Referer' => "https://www.instagram.com/$username/",
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
        ]);
        
        if ($resp['status'] === 200) break;
        
        $retry++;
        error_log("Page $page_n retry $retry/{$max_retries} failed (status={$resp['status']})");
        if ($retry >= $max_retries) break;
        sleep(5 * $retry); // Progressive backoff 5s,10s,15s
    } while ($retry < $max_retries);

    error_log("API page $page_n final: status={$resp['status']}, body_length=" . strlen($resp['body']));
=======

    if ($resp['status'] !== 200) {
        error_log("Page $page_n HTTP {$resp['status']}");
        break;
    }

    $body = $resp['body'];
    if (!preg_match('/^\s*\{/', $body)) {
        error_log("Page $page_n not JSON");
        break;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Page $page_n JSON decode error: " . json_last_error_msg());
        if ($debug) {
            echo json_encode(['error' => 'JSON decode fail', 'preview' => substr($body, 0, 500)], JSON_PRETTY_PRINT);
        }
        break;
    }

    $items = $data['items'] ?? [];
    $more_available = $data['more_available'] ?? false;
    $next_max_id = $data['next_max_id'] ?? '';
    
    $new_count = 0;
    foreach ($items as $item) {
        $pk = (string)($item['pk'] ?? $item['id'] ?? '');
        if (!$pk || isset($seen_pks[$pk])) {
            continue;
        }
        $seen_pks[$pk] = true;
        
        $url = item_to_url($item);
        if ($url) {
            $all_urls[] = $url;
            $new_count++;
        }
    }

    error_log("Page $page_n +$new_count posts, total=" . count($all_urls) . ", more_available=$more_available, next_max_id=" . substr($next_max_id, 0, 20) . "...");

    $max_id = $next_max_id;
    $delay_us = ($delay_min * 1000000) + mt_rand(0, ($delay_max - $delay_min) * 1000000);
    usleep($delay_us);
    error_log("Sleep after page $page_n: " . round($delay_us / 1000000, 1) . "s");

} while (($more_available && $next_max_id) || $page_n < 20); // Force at least 20 pages for complete scrape
=======

$end_time = microtime(true);
$duration = round($end_time - $start_time, 2);

error_log("Scraping complete: " . count($all_urls) . " urls in {$duration}s");

$response = [
    'urls' => $all_urls,
    'count' => count($all_urls),
    'took_seconds' => $duration,
    'pages_fetched' => $page_n,
    'user_id' => $user_id,
    'config' => [
        'max_pages' => $max_pages,
        'delay_range' => "$delay_min-$delay_max",
        'max_total_time' => $max_total_time,
        'stopped_reason' => $page_n >= $max_pages ? 'max_pages' : 
                          ((microtime(true) - $start_time) > $max_total_time ? 'max_time' : 
                          (!$more_available && !$next_max_id ? 'no_more_available' : 'min_pages_reached'))
    ]
=======
];

if ($debug) {
    $response['debug'] = [
        'final_more_available' => $more_available ?? false,
        'final_next_max_id' => $next_max_id ?? '',
        'total_requests' => $page_n
    ];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);

function fetch_url($hostname, $path, $extra_headers = []) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://$hostname$path",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 11; Pixel 5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.6261.119 Mobile Safari/537.36',
        CURLOPT_HTTPHEADER => array_merge([
            'Accept: application/json, text/html, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
        ], array_map(function($k, $v) { return "$k: $v"; }, array_keys($extra_headers), $extra_headers)),
        CURLOPT_ENCODING => 'gzip, deflate, br',
    ]);
    
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("cURL error: $error");
        return ['status' => 0, 'body' => ''];
    }
    
    return ['status' => $status, 'body' => $body];
}

function pk_to_shortcode($pk) {
    $ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $n = (int)$pk;
    $code = '';
    while ($n > 0) {
        $code = $ALPHA[$n % 64] . $code;
        $n = intval($n / 64);
    }
    return $code;
}

function item_to_url($item) {
    $pk = $item['pk'] ?? $item['id'] ?? '';
    if (!$pk) {
        return null;
    }
    
    $sc = pk_to_shortcode($pk);
    $media_type = $item['media_type'] ?? 1;
    $product_type = $item['product_type'] ?? '';
    
    if ($media_type == 2 || $product_type == 'clips') {
        return "https://www.instagram.com/reel/$sc/";
    }
    return "https://www.instagram.com/p/$sc/";
}
?>
