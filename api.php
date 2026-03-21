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

if (!isset($_GET['username']) || empty(trim($_GET['username']))) {
    http_response_code(400);
    echo json_encode(['error' => 'Username parameter required']);
    exit;
}

$username = trim($_GET['username']);
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

do {
    $page_n++;
    $path = "/api/v1/feed/user/$user_id/?count=24";
    if ($max_id) {
        $path .= '&max_id=' . urlencode($max_id);
    }

    $resp = fetch_url("www.instagram.com", $path, [
        'X-IG-App-ID' => '1217981644879628',
        'Referer' => "https://www.instagram.com/$username/",
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-origin',
    ]);

    error_log("API page $page_n: status={$resp['status']}, body_length=" . strlen($resp['body']));

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
    usleep((600000 + mt_rand(0, 500000))); // 0.6-1.1s delay - slower to avoid rate limit

} while ($more_available && $next_max_id);

$end_time = microtime(true);
$duration = round($end_time - $start_time, 2);

error_log("Scraping complete: " . count($all_urls) . " urls in {$duration}s");

$response = [
    'urls' => $all_urls,
    'count' => count($all_urls),
    'took_seconds' => $duration,
    'pages_fetched' => $page_n,
    'user_id' => $user_id
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
