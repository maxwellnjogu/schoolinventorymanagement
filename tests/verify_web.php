<?php
// 1. Fetch login page to get CSRF and initial cookie
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "Accept: text/html\r\n"
    ]
];
$ctx = stream_context_create($opts);
$loginHtml = file_get_contents("http://127.0.0.1:8000/public/login.php", false, $ctx);

// Extract cookie and csrf token
$cookie = '';
foreach ($http_response_header as $hdr) {
    if (stripos($hdr, 'Set-Cookie:') === 0) {
        $cookie = trim(explode(';', substr($hdr, 11))[0]);
        break;
    }
}
preg_match('/name="csrf_token" value="([^"]+)"/', $loginHtml, $matches);
$csrf = $matches[1] ?? '';

echo "CSRF Extracted: " . (!empty($csrf) ? "YES" : "NO") . "\n";
echo "Initial Cookie: " . $cookie . "\n";

// 2. Submit login POST
$postData = http_build_query([
    'csrf_token' => $csrf,
    'username'   => 'admin',
    'password'   => 'Admin@123'
]);

$optsPost = [
    'http' => [
        'method'          => 'POST',
        'header'          => "Content-Type: application/x-www-form-urlencoded\r\n" .
                             "Cookie: {$cookie}\r\n" .
                             "Content-Length: " . strlen($postData) . "\r\n",
        'content'         => $postData,
        'follow_location' => 0
    ]
];
$ctxPost = stream_context_create($optsPost);
$postRes = file_get_contents("http://127.0.0.1:8000/public/login.php", false, $ctxPost);

// Look for new session cookie after session regeneration
foreach ($http_response_header as $hdr) {
    if (stripos($hdr, 'Set-Cookie:') === 0) {
        $cookie = trim(explode(';', substr($hdr, 11))[0]);
    }
}
echo "Auth Session Cookie: " . $cookie . "\n";

function get_auth_page($url, $cookie) {
    $opts = [
        'http' => [
            'method'          => 'GET',
            'header'          => "Cookie: {$cookie}\r\n",
            'follow_location' => 1
        ]
    ];
    return file_get_contents($url, false, stream_context_create($opts));
}

// 3. Test Dashboard
$dashHtml = get_auth_page("http://127.0.0.1:8000/public/dashboard.php", $cookie);
echo "Dashboard Status: " . (strpos($dashHtml, 'Welcome, Dr. Peter Kamau') !== false ? "OK_LOGGED_IN" : "FAILED") . "\n";
echo "Low Stock Alerts on Dashboard: " . (strpos($dashHtml, 'Active Low-Stock Alerts') !== false ? "FOUND" : "NOT_FOUND") . "\n";
echo "Chart.js Canvas: " . (strpos($dashHtml, 'id="categoryChart"') !== false ? "FOUND" : "NOT_FOUND") . "\n";

// 4. Test Items Page
$itemsHtml = get_auth_page("http://127.0.0.1:8000/public/items.php", $cookie);
echo "Items Page Status: " . (strpos($itemsHtml, 'ITM-PAP-A4') !== false ? "OK_ITEMS_LOADED" : "FAILED") . "\n";

// 5. Test Categories Page
$catsHtml = get_auth_page("http://127.0.0.1:8000/public/categories.php", $cookie);
echo "Categories Page Status: " . (strpos($catsHtml, 'CAT-STAT') !== false ? "OK_CATS_LOADED" : "FAILED") . "\n";

// 6. Test Locations Page
$locsHtml = get_auth_page("http://127.0.0.1:8000/public/locations.php", $cookie);
echo "Locations Page Status: " . (strpos($locsHtml, 'LOC-MAIN') !== false ? "OK_LOCS_LOADED" : "FAILED") . "\n";

// 7. Test Movements Page
$movsHtml = get_auth_page("http://127.0.0.1:8000/public/movements.php", $cookie);
echo "Movements Page Status: " . (strpos($movsHtml, 'Stock Movement Ledger') !== false ? "OK_MOVS_LOADED" : "FAILED") . "\n";
