<?php
error_reporting(0);

// ===== GET CHANNEL ID =====
$id = $_GET['id'] ?? null;

// ===== TYPE =====
$type = $_GET['type'] ?? 'mpd';

if (!$id) {
    echo json_encode([
        "error" => "missing_channel_id"
    ]);
    exit;
}

// ===== VALIDATE TYPE =====
if (!in_array($type, ['mpd', 'm3u8'])) {
    $type = 'mpd';
}

// ===== UNIQUE CACHE FILE PER ID =====
$cacheFile = "cookies_" . preg_replace('/[^a-zA-Z0-9]/', '', $id) . ".json";
$cacheTime = 4 * 60 * 60; // 4 तास सेकंदात

// ===== CACHE CHECK =====
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    header('Content-Type: application/json');
    echo file_get_contents($cacheFile);
    exit;
}

$result = [];

// ===== URL =====
$url = "https://jt.drmlive.net/jiotvplus/{$id}.{$type}";

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT => "Mozilla/5.0",
    CURLOPT_HTTPHEADER => [
        "Referer: https://www.jiotv.com/",
        "Origin: https://www.jiotv.com"
    ]
]);

$response = curl_exec($ch);

preg_match('/Location:\s*(.*)/i', $response, $matches);

if (!isset($matches[1])) {

    $result[] = [
        "channel_id" => $id,
        "type" => $type,
        "stream_url" => null,
        "cookie" => null,
        "status" => "redirect_not_found"
    ];

    curl_close($ch);

} else {

    $redirectUrl = trim($matches[1]);

    curl_close($ch);

    $ch2 = curl_init($redirectUrl);

    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => "Mozilla/5.0",
    ]);

    $response2 = curl_exec($ch2);

    $headerSize = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);

    $headers = substr($response2, 0, $headerSize);

    curl_close($ch2);

    preg_match_all('/Set-Cookie:\s*([^;]*)/mi', $headers, $matches);

    $cookie = "";

    foreach ($matches[1] as $c) {

        if (strpos($c, "__hdnea__") !== false) {
            $cookie = $c;
        }
    }

    $result[] = [
        "channel_id" => $id,
        "mpd_url" => $redirectUrl,
        "cookie" => $cookie ?: null,
        "status" => $cookie ? "success" : "cookie_not_found"
    ];
}

// ===== JSON CLEAN =====
$json = json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

// ===== SAVE FILE =====
file_put_contents($cacheFile, $json);

// ===== OUTPUT =====
header('Content-Type: application/json');

echo $json;
?>