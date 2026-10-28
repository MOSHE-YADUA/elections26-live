<?php
// מונה צופים בזמן אמת: כל דפדפן פתוח שולח מזהה אקראי פעם ב-30 שניות;
// "מחובר" = נראה ב-60 השניות האחרונות. לא נשמרים IP או פרטים אישיים.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

const WINDOW = 60;
$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/', $id)) {
    http_response_code(400);
    exit(json_encode(['error' => 'bad id']));
}

$db = new PDO('sqlite:' . dirname(__DIR__, 2) . '/elections26_presence.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout = 3000');
$db->exec('CREATE TABLE IF NOT EXISTS seen (id TEXT PRIMARY KEY, at INTEGER NOT NULL)');

$now = time();
$db->prepare('INSERT INTO seen (id, at) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET at = excluded.at')
   ->execute([$id, $now]);
$db->prepare('DELETE FROM seen WHERE at < ?')->execute([$now - WINDOW * 3]);
$q = $db->prepare('SELECT COUNT(*) FROM seen WHERE at >= ?');
$q->execute([$now - WINDOW]);

echo json_encode(['online' => (int)$q->fetchColumn()]);
