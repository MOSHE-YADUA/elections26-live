<?php
// מגיש לכל הקלפיות של כנסת 26 רק את הקולות של רשימות מסוימות (לאזור "קולות חרדים").
//   ?p=שס,ג   עד 6 רשימות, לפי אותיות הפתק
// לכל קלפי: [ברזל, בזב, מצביעים, כשרים, קולות לרשימה 1, קולות לרשימה 2, ...]
require __DIR__ . '/kalpi_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

if (!is_file(KALPI26_FILE)) exit(json_encode(['available' => false]));
$d = json_decode(file_get_contents(KALPI26_FILE), true);

$ps = array_values(array_unique(array_filter(explode(',', (string)($_GET['p'] ?? '')), fn($p) => preg_match(PARTY_RE, $p))));
$ps = array_slice($ps, 0, 6);

$towns = [];
foreach ($d['towns'] as $code => $t) {
    $k = [];
    foreach ($t['k'] as $num => $row) {
        $r = [$row[0], $row[1], $row[2], $row[4]];
        foreach ($ps as $p) $r[] = $row[5][$p] ?? 0;
        $k[$num] = $r;
    }
    $towns[$code] = $k;
}
echo json_encode(['available' => true, 'uploaded' => $d['uploaded'], 'file' => $d['file'], 'kalpis' => $d['kalpis'],
    'parties' => $ps, 'towns' => $towns], JSON_UNESCAPED_UNICODE);
