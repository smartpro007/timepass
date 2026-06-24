<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

/* --------------------------------------
   1. INPUT
-------------------------------------- */
$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(["error" => "Channel ID missing"], JSON_PRETTY_PRINT);
    exit;
}

/* --------------------------------------
   2. CACHE
-------------------------------------- */
$cache_dir = __DIR__ . "/cache/";
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0777, true);
}
$cache_file = $cache_dir . "cache_" . preg_replace('/[^a-zA-Z0-9]/','', $id) . ".json";

/* --------------------------------------
   3. MPD URL
-------------------------------------- */
$mpd_url = "https://elitebeam.shop/Premium/tp/get-mpd.php?id=" . urlencode($id);

/* --------------------------------------
   4. FETCH MPD
-------------------------------------- */
$context = stream_context_create([
    "http" => [
        "method"  => "GET",
        "header"  => "User-Agent: Mozilla/5.0\r\nAccept: */*\r\n",
        "timeout" => 10
    ]
]);

$mpd_data = @file_get_contents($mpd_url, false, $context);
if (!$mpd_data) {
    echo json_encode(["error" => "Failed to fetch MPD"], JSON_PRETTY_PRINT);
    exit;
}

/* --------------------------------------
   5. ROBUST WIDEVINE PSSH
-------------------------------------- */
function extractWidevinePssh(string $mpd)
{
    $WIDEVINE_SYSTEM_ID = 'edef8ba979d64acea3c827dcd51d21ed';

    if (!preg_match_all('/<[^>]*pssh[^>]*>(.*?)<\/[^>]*pssh>/is', $mpd, $m)) {
        return null;
    }

    foreach ($m[1] as $pssh) {
        $pssh = trim($pssh);
        if ($pssh === '') continue;

        $raw = base64_decode($pssh, true);
        if ($raw === false || strlen($raw) < 32) continue;

        $systemId = bin2hex(substr($raw, 12, 16));
        if ($systemId === $WIDEVINE_SYSTEM_ID) {
            return $pssh;
        }
    }
    return null;
}

$widevine_pssh = extractWidevinePssh($mpd_data);
if (!$widevine_pssh) {
    echo json_encode(["error" => "Widevine PSSH not found"], JSON_PRETTY_PRINT);
    exit;
}

/* --------------------------------------
   6. CACHE HIT (PSSH BASED)
-------------------------------------- */
if (file_exists($cache_file)) {
    $cache = json_decode(file_get_contents($cache_file), true);
    if (!empty($cache['pssh']) && $cache['pssh'] === $widevine_pssh) {
        echo json_encode([
            "id"   => $id,
            "keys" => $cache['keys'],
            "mode" => "cache"
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

/* --------------------------------------
   7. PSSH → HEX
-------------------------------------- */
$pssh_hex = bin2hex(base64_decode($widevine_pssh));

/* --------------------------------------
   8. SECURE-KID API
-------------------------------------- */
$ch = curl_init("https://tp.secure-kid.workers.dev/");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(["pssh" => $pssh_hex]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 10
]);

$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);
$hex_kid = $data['encryptedKID'] ?? null;

if (!$hex_kid) {
    echo json_encode([
        "error" => "Secure-KID failed",
        "raw"   => $res
    ], JSON_PRETTY_PRINT);
    exit;
}

/* --------------------------------------
   9. LICENSE API
-------------------------------------- */
$base64_kid = base64_encode(hex2bin($hex_kid));

$ch2 = curl_init("https://tp.drmlive-01.workers.dev/?id=" . urlencode($id));
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        "kids" => [$base64_kid],
        "type" => "temporary"
    ]),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'User-Agent: OTT Navigator/1.6.9 (Linux;Android 10)'
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false
]);

$final_res = curl_exec($ch2);
curl_close($ch2);

$keys = json_decode($final_res, true);
if (empty($keys['keys'])) {
    echo json_encode([
        "error" => "License API failed",
        "raw"   => $final_res
    ], JSON_PRETTY_PRINT);
    exit;
}

/* --------------------------------------
   10. SAVE CACHE
-------------------------------------- */
file_put_contents($cache_file, json_encode([
    "pssh" => $widevine_pssh,
    "keys" => $keys,
    "time" => time()
], JSON_PRETTY_PRINT));

/* --------------------------------------
   11. OUTPUT
-------------------------------------- */
echo json_encode([
    "keys" => $keys['keys'],
    "type" => $keys['type'] ?? "temporary"
], JSON_PRETTY_PRINT);
exit;