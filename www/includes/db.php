<?php
date_default_timezone_set('Asia/Baghdad'); // کاتی هەرێمی کوردستان (بۆ ناوی فایلی پاڵپشتی)
// ============================================================
// پەیوەندی بە بنکەی زانیاری — SQLite (بێ سێرڤەر، بێ XAMPP)
// ============================================================

/** is_writable لە ویندۆزدا مافەکانی ACL ناپشکنێت، بۆیە بە ڕاستی فایلێک دەنووسین. */
function dir_writable($dir) {
    $probe = $dir . '/.write_test_' . getmypid();
    if (@file_put_contents($probe, '1') === false) return false;
    @unlink($probe);
    return true;
}

/**
 * دۆزینەوەی فۆڵدەرێک کە دەتوانرێت تێیدا بنووسرێت.
 * ١) فۆڵدەری بەرنامەکە (پۆرتەبڵ، یان دامەزراندن لە AppData)
 * ٢) %APPDATA%\TimetableSystem (ئەگەر بەرنامەکە لە Program Files بێت)
 */
function data_dir() {
    $appDir = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
    // ئەگەر بنکەی زانیاری پێشتر لێرە هەبێت، یان فۆڵدەرەکە بنووسرێت → لێرە
    if (dir_writable($appDir)) return $appDir;
    $candidates = [];
    if ($a = getenv('APPDATA'))      $candidates[] = $a;
    if ($u = getenv('USERPROFILE'))  $candidates[] = $u . '/AppData/Roaming';
    if ($l = getenv('LOCALAPPDATA')) $candidates[] = $l;
    foreach ($candidates as $base) {
        $dir = $base . '/TimetableSystem';
        if ((is_dir($dir) || @mkdir($dir, 0777, true)) && dir_writable($dir)) return $dir;
    }
    return $appDir; // دواهەمین هەوڵ — هەڵەکە لە خوارەوە ڕوون دەکرێتەوە
}

$DATA_DIR   = data_dir();
$DB_FILE    = $DATA_DIR . '/timetable.db';
$BACKUP_DIR = $DATA_DIR . '/backups';

// وەشانی کۆن داتاکەی لە فۆڵدەری www/.. دادەنا؛ ئەگەر لێرە نییە و لەوێ هەیە، کۆپی بکە
$legacyDb = __DIR__ . '/../timetable.db';
if (!file_exists($DB_FILE) && file_exists($legacyDb)) {
    @copy($legacyDb, $DB_FILE);
}

function open_db($file) {
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    run_migrations($pdo);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/** هەموو گۆڕانکارییەکانی schema بە ڕیز جێبەجێ دەکرێن (PRAGMA user_version). */
function run_migrations($pdo) {
    $migrations = require __DIR__ . '/migrations.php';
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= count($migrations)) return;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    foreach ($migrations as $i => $sql) {
        $target = $i + 1;
        if ($version >= $target) continue;
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $pdo->exec('PRAGMA user_version = ' . $target);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

try {
    if (!dir_writable($DATA_DIR)) {
        throw new RuntimeException("ناتوانرێت لە فۆڵدەری «{$DATA_DIR}» بنووسرێت. بەرنامەکە لە شوێنێکی تر دابمەزرێنە.");
    }
    $pdo = open_db($DB_FILE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['ok' => false, 'error' => 'کێشە لە بنکەی زانیاری: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}

// ---------------- ڕێکخستنەکان ----------------
const DEFAULT_DAYS = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];

function setting($pdo, $key, $default = null) {
    $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}
function set_setting($pdo, $key, $value) {
    $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}
/** ناوی ڕۆژەکان بە ڕیز (ژمارەکەیان = ژمارەی ڕۆژەکانی هەفتە). */
function cfg_days($pdo) {
    $d = json_decode(setting($pdo, 'days', ''), true);
    return is_array($d) && $d ? array_values($d) : array_slice(DEFAULT_DAYS, 0, 5);
}
function cfg_periods($pdo) {
    $p = (int) setting($pdo, 'periods_per_day', 6);
    return $p > 0 ? $p : 6;
}
function cfg_period_times($pdo) {
    $t = json_decode(setting($pdo, 'period_times', ''), true);
    return is_array($t) ? $t : [];
}
function cfg_all($pdo) {
    return [
        'school_name'  => setting($pdo, 'school_name', ''),
        'days'         => cfg_days($pdo),
        'periods'      => cfg_periods($pdo),
        'period_times' => cfg_period_times($pdo),
    ];
}

function json_out($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail($msg) {
    json_out(['ok' => false, 'error' => $msg]);
}
function body_json() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}
