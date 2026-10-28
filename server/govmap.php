<?php
// מביא את תוצאות הספירה מ-GovMap ומחזיר סיכום ארצי (אותו מבנה כמו scripts/update.mjs).
// התשובה נשמרת לדקה, כך שכל המבקרים יחד פונים ל-GovMap לכל היותר פעם בדקה.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

const SRC = 'https://elections.govmap.gov.il/data/votes/latest/municipal_data.json';
const TTL = 60;
$cache = dirname(__DIR__, 2) . '/govmap_cache.json';  // מחוץ ל-public_html

function serve($body, $status = 200) {
    http_response_code($status);
    echo $body;
    exit;
}

if (is_file($cache) && time() - filemtime($cache) < TTL) serve(file_get_contents($cache));

$lock = fopen($cache . '.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // מבקר אחר כבר מרענן; מחזירים את מה שיש
    if (is_file($cache)) serve(file_get_contents($cache));
    flock($lock, LOCK_EX);
    if (is_file($cache)) serve(file_get_contents($cache));
}

$ch = curl_init(SRC . '?v=' . time());
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => 'elections26-live/1.0 (+https://moshe-yadua.github.io/elections26-live/)',
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

$d = $raw ? json_decode($raw, true) : null;
if ($code !== 200 || !$d || empty($d['generated']) || empty($d['features'])) {
    // GovMap לא החזיר נתונים: נוגעים בקובץ כדי לא לנסות שוב לפני שעברה דקה
    if (is_file($cache)) { touch($cache); serve(file_get_contents($cache)); }
    serve(json_encode(['error' => 'govmap', 'status' => $code, 'type' => $type,
        'head' => substr((string)$raw, 0, 80)], JSON_UNESCAPED_UNICODE), 502);
}

$sum = ['bzb' => 0, 'voters' => 0, 'invalid' => 0, 'valid' => 0];
$votes = [];
foreach ($d['parties'] as $k) if ($k !== '') $votes[$k] = 0;
foreach ($d['features'] as $f) {
    $sum['bzb'] += $f['בזב'] ?? 0;
    $sum['voters'] += $f['מצביעים'] ?? 0;
    $sum['invalid'] += $f['פסולים'] ?? 0;
    $sum['valid'] += $f['כשרים'] ?? 0;
    foreach ($votes as $k => $_) $votes[$k] += $f[$k] ?? 0;
}

$out = json_encode(array_merge(
    ['generated' => $d['generated'], 'source' => $d['source'] ?? null,
     'kalpi_counted' => $d['totals']['kalpi_counted'] ?? null],
    $sum,
    ['votes' => $votes]
), JSON_UNESCAPED_UNICODE);

file_put_contents($cache, $out, LOCK_EX);
serve($out);
