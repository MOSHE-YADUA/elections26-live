<?php
// מגיש את נתוני הקלפיות של כנסת 26 שהועלו דרך admin.php.
//   ?index=1   רשימת יישובים (קוד -> שם, מספר קלפיות) וזמן ההעלאה
//   ?town=קוד  כל הקלפיות של יישוב אחד
//   ?barzel=N  קלפי אחת לפי מספר ברזל
require __DIR__ . '/kalpi_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60');

if (!is_file(KALPI26_FILE)) exit(json_encode(['available' => false]));
$d = json_decode(file_get_contents(KALPI26_FILE), true);
$meta = ['available' => true, 'uploaded' => $d['uploaded'], 'file' => $d['file'], 'kalpis' => $d['kalpis']];

if (isset($_GET['town'])) {
    $t = $d['towns'][(string)(int)$_GET['town']] ?? null;
    exit(json_encode($meta + ['town' => $t], JSON_UNESCAPED_UNICODE));
}
if (isset($_GET['barzel'])) {
    $b = (int)$_GET['barzel'];
    foreach ($d['towns'] as $code => $t) {
        foreach ($t['k'] as $k => $row) {
            if ($row[0] === $b) exit(json_encode($meta + ['found' => ['code' => (string)$code, 'name' => $t['n'], 'kalpi' => (string)$k, 'row' => $row]], JSON_UNESCAPED_UNICODE));
        }
    }
    exit(json_encode($meta + ['found' => null]));
}
$idx = [];
foreach ($d['towns'] as $code => $t) $idx[$code] = [$t['n'], count($t['k'])];
echo json_encode($meta + ['towns' => $idx], JSON_UNESCAPED_UNICODE);
