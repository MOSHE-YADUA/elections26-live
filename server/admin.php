<?php
// העלאת קובץ התוצאות לפי קלפיות של כנסת 26. מוגן בסיסמה; הגיבוב נשמר מחוץ ל-public_html.
require __DIR__ . '/kalpi_lib.php';

session_set_cookie_params(['lifetime' => 0, 'path' => '/elections26/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

const LOCK_FILE = PRIVATE_DIR . '/login_failures.json';
const MAX_FAILS = 8;          // ניסיונות שגויים
const FAIL_WINDOW = 900;      // בתוך 15 דקות

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function recent_failures(): array {
    $f = is_file(LOCK_FILE) ? (json_decode(file_get_contents(LOCK_FILE), true) ?: []) : [];
    return array_values(array_filter($f, fn($t) => $t > time() - FAIL_WINDOW));
}

function check_password(string $pw): bool {
    $cfg = require PRIVATE_DIR . '/admin_config.php';
    $calc = hash_pbkdf2('sha256', $pw, $cfg['salt'], $cfg['iter'], 64);
    return hash_equals($cfg['hash'], $calc);
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$msg = null; $err = null; $summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $err = 'פג תוקף הטופס. רעננו את הדף ונסו שוב.';
    } elseif (($_POST['action'] ?? '') === 'login') {
        $fails = recent_failures();
        if (count($fails) >= MAX_FAILS) {
            $err = 'יותר מדי ניסיונות שגויים. נסו שוב בעוד רבע שעה.';
        } elseif (check_password($_POST['password'] ?? '')) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            @unlink(LOCK_FILE);
        } else {
            $fails[] = time();
            if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0700, true);
            file_put_contents(LOCK_FILE, json_encode($fails));
            sleep(1);
            $err = 'סיסמה שגויה.';
        }
    } elseif (($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php');
        exit;
    } elseif (($_POST['action'] ?? '') === 'upload' && !empty($_SESSION['admin'])) {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $err = 'הקובץ לא הועלה. בחרו קובץ ונסו שוב.';
        } else {
            try {
                [$data, $summary] = kalpi_build(kalpi_read_rows($f['tmp_name'], $f['name']), $f['name']);
                if (!empty($_POST['dry'])) {
                    $msg = 'בדיקה בלבד: הקובץ נקרא בהצלחה ולא נשמר.';
                } else {
                    kalpi_save($data);
                    $msg = 'הקובץ נקלט והאתר מציג אותו עכשיו.';
                }
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }
        }
    }
}

$logged = !empty($_SESSION['admin']);
$current = is_file(KALPI26_FILE) ? json_decode(file_get_contents(KALPI26_FILE), true) : null;
?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>העלאת קלפיות · כנסת 26</title>
<style>
:root{--night:#0b1633;--bg:#eef1f6;--surface:#fff;--ink:#111a33;--muted:#5f6a83;--line:#d9dfea;--accent:#2f55e4;--ok:#13895a;--bad:#cf3326}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:"Assistant","Segoe UI",Arial,sans-serif;font-size:16px;line-height:1.55}
header{background:var(--night);color:#eef2ff;padding:22px 16px}
header h1{margin:0;font-size:24px}
main{max-width:640px;margin:24px auto;padding:0 16px;display:grid;gap:16px}
.box{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:20px;display:grid;gap:12px}
label{font-weight:700}
input[type=password],input[type=file]{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff}
button{font:inherit;font-weight:700;padding:10px 18px;border-radius:8px;border:0;background:var(--accent);color:#fff;cursor:pointer;justify-self:start}
button.ghost{background:transparent;color:var(--muted);border:1px solid var(--line)}
.msg{padding:12px 14px;border-radius:8px}
.msg.ok{background:#e3f5ec;color:var(--ok)} .msg.bad{background:#fbe6e4;color:var(--bad)}
dl{display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0}
dt{color:var(--muted)} dd{margin:0;font-weight:700}
ol{margin:0;padding-inline-start:20px;color:var(--muted)}
a{color:var(--accent)}
</style>
</head>
<body>
<header><h1>העלאת תוצאות לפי קלפיות · כנסת 26</h1></header>
<main>
<?php if ($err): ?><div class="msg bad"><?= h($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>

<?php if (!$logged): ?>
  <form class="box" method="post">
    <label for="password">סיסמה</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="login">
    <button type="submit">כניסה</button>
  </form>
<?php else: ?>
  <?php if ($summary): ?>
  <div class="box">
    <strong>מה נקלט</strong>
    <dl>
      <dt>קלפיות</dt><dd><?= number_format($summary['kalpis']) ?></dd>
      <dt>יישובים</dt><dd><?= number_format($summary['towns']) ?></dd>
      <dt>רשימות</dt><dd><?= $summary['parties'] ?></dd>
      <dt>קולות כשרים</dt><dd><?= number_format($summary['valid']) ?></dd>
    </dl>
  </div>
  <?php endif; ?>

  <form class="box" method="post" enctype="multipart/form-data">
    <label for="file">קובץ התוצאות לפי קלפיות (CSV או XLSX)</label>
    <input type="file" id="file" name="file" accept=".csv,.xlsx,.txt" required>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="upload">
    <button type="submit">העלאה</button>
    <ol>
      <li>באתר הוועדה, votes26.bechirot.gov.il, מורידים את קובץ התוצאות לפי קלפיות.</li>
      <li>מעלים אותו כאן כמו שהוא, בלי לערוך. קובץ חדש מחליף את הקודם.</li>
    </ol>
  </form>

  <div class="box">
    <strong>הקובץ שמוצג באתר עכשיו</strong>
    <?php if ($current): ?>
      <dl>
        <dt>שם הקובץ</dt><dd><?= h($current['file']) ?></dd>
        <dt>הועלה</dt><dd><?= h((new DateTime($current['uploaded']))->setTimezone(new DateTimeZone('Asia/Jerusalem'))->format('d.m.Y H:i')) ?></dd>
        <dt>קלפיות</dt><dd><?= number_format($current['kalpis']) ?></dd>
      </dl>
    <?php else: ?>
      <span>עוד לא הועלה קובץ.</span>
    <?php endif; ?>
    <a href="https://moshe-yadua.github.io/elections26-live/#kalpi" target="_blank" rel="noopener">לצפייה באתר</a>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="ghost">יציאה</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
