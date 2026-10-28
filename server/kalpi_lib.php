<?php
// קריאת קובץ תוצאות לפי קלפיות של ועדת הבחירות (CSV או XLSX) והמרתו למבנה JSON מכווץ לפי יישוב.
// משותף ל-admin.php (העלאה) ול-kalpi26.php (הגשה).

const PRIVATE_DIR = __DIR__ . '/../../elections26_private';
const KALPI26_FILE = PRIVATE_DIR . '/kalpi26.json';

// רשימה = 1-3 אותיות עבריות (אותיות הפתק); עמודות אחרות אחרי "כשרים" אינן רשימות
const PARTY_RE = '/^[\x{05D0}-\x{05EA}]{1,3}$/u';

function kalpi_read_rows(string $path, string $name): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') return kalpi_read_xlsx($path);
    if ($ext === 'csv' || $ext === 'txt') return kalpi_read_csv($path);
    throw new RuntimeException('סוג קובץ לא נתמך. צריך קובץ CSV או XLSX.');
}

function kalpi_read_csv(string $path): array {
    $s = file_get_contents($path);
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    if (!mb_check_encoding($s, 'UTF-8')) {
        $c = @iconv('CP1255', 'UTF-8//IGNORE', $s);
        if ($c === false) throw new RuntimeException('לא הצלחתי לזהות את קידוד הקובץ.');
        $s = $c;
    }
    $rows = [];
    foreach (preg_split('/\r\n|\n|\r/', $s) as $line) {
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line);
    }
    return $rows;
}

function kalpi_read_xlsx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('לא הצלחתי לפתוח את קובץ האקסל.');
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $x = new XMLReader();
        $x->XML($ss);
        $cur = null;
        while ($x->read()) {
            if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'si') $cur = '';
            elseif ($x->nodeType === XMLReader::ELEMENT && $x->localName === 't' && $cur !== null) $cur .= $x->readString();
            elseif ($x->nodeType === XMLReader::END_ELEMENT && $x->localName === 'si') { $shared[] = $cur; $cur = null; }
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) throw new RuntimeException('לא נמצא גיליון ראשון בקובץ האקסל.');

    $rows = [];
    $x = new XMLReader();
    $x->XML($sheet);
    $row = null; $col = 0; $type = ''; $val = '';
    while ($x->read()) {
        if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'row') $row = [];
        elseif ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'c') {
            $col = kalpi_col_index(preg_replace('/\d+/', '', (string)$x->getAttribute('r')));
            $type = (string)$x->getAttribute('t');
            $val = '';
        } elseif ($x->nodeType === XMLReader::ELEMENT && ($x->localName === 'v' || $x->localName === 't')) {
            $val .= $x->readString();
        } elseif ($x->nodeType === XMLReader::END_ELEMENT && $x->localName === 'c') {
            if ($type === 's') $val = $shared[(int)$val] ?? '';
            while (count($row) < $col) $row[] = '';
            $row[$col] = $val;
        } elseif ($x->nodeType === XMLReader::END_ELEMENT && $x->localName === 'row') {
            $rows[] = $row;
            $row = null;
        }
    }
    return $rows;
}

function kalpi_col_index(string $letters): int {
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function kalpi_norm_kalpi(string $k): string {
    $k = trim($k);
    if (preg_match('/^(\d+)\.0+$/', $k, $m)) return $m[1];
    return $k;
}

// מחזיר [מבנה הנתונים, סיכום לתצוגה]
function kalpi_build(array $rows, string $fileName): array {
    if (count($rows) < 2) throw new RuntimeException('הקובץ ריק.');
    $head = array_map(fn($h) => trim(str_replace(["\u{FEFF}", '"'], '', (string)$h)), $rows[0]);
    $col = array_flip($head);
    foreach (['סמל ישוב', 'קלפי', 'כשרים'] as $need) {
        if (!isset($col[$need])) throw new RuntimeException("חסרה בקובץ העמודה \"$need\". האם זה קובץ התוצאות לפי קלפיות?");
    }
    $parties = [];
    for ($i = $col['כשרים'] + 1; $i < count($head); $i++) {
        if (preg_match(PARTY_RE, $head[$i])) $parties[$i] = $head[$i];
    }
    if (!$parties) throw new RuntimeException('לא נמצאו עמודות של רשימות אחרי העמודה "כשרים".');

    $num = fn($r, $name) => isset($col[$name]) ? (int)round((float)($r[$col[$name]] ?? 0)) : 0;
    $towns = [];
    $count = 0; $valid = 0;
    foreach (array_slice($rows, 1) as $r) {
        $code = trim((string)($r[$col['סמל ישוב']] ?? ''));
        $k = kalpi_norm_kalpi((string)($r[$col['קלפי']] ?? ''));
        if ($code === '' || $k === '') continue;
        $code = (string)(int)$code;
        if (!isset($towns[$code])) {
            $towns[$code] = ['n' => isset($col['שם ישוב']) ? preg_replace('/\s+/u', ' ', trim((string)$r[$col['שם ישוב']])) : $code, 'k' => []];
        }
        $v = [];
        foreach ($parties as $i => $p) {
            $x = (int)round((float)($r[$i] ?? 0));
            if ($x) $v[$p] = $x;
        }
        $towns[$code]['k'][$k] = [$num($r, 'ברזל'), $num($r, 'בזב'), $num($r, 'מצביעים'), $num($r, 'פסולים'), $num($r, 'כשרים'), (object)$v];
        $count++;
        $valid += $num($r, 'כשרים');
    }
    if (!$count) throw new RuntimeException('לא נמצאו שורות של קלפיות בקובץ.');

    $data = ['election' => 26, 'uploaded' => gmdate('c'), 'file' => $fileName, 'kalpis' => $count, 'towns' => $towns];
    $summary = ['kalpis' => $count, 'towns' => count($towns), 'parties' => count($parties), 'valid' => $valid];
    return [$data, $summary];
}

function kalpi_save(array $data): void {
    if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0700, true);
    $tmp = KALPI26_FILE . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
    rename($tmp, KALPI26_FILE);
}
