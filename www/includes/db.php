<?php
// ============================================================
// پەیوەندی بە بنکەی زانیارییەوە — SQLite (بێ سێرڤەر، بێ XAMPP)
// بنکەی زانیاری وەک فایلێک لە تەنیشت بەرنامەکە هەڵدەگیرێت
// ============================================================

// شوێنی فایلی بنکەی زانیاری — لە فۆڵدەری بەرنامەکە
$DB_FILE = __DIR__ . '/../timetable.db';
$firstRun = !file_exists($DB_FILE);

try {
    $pdo = new PDO('sqlite:' . $DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['ok' => false, 'error' => 'کێشە لە بنکەی زانیاری: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
}

// یەکەم جار: خشتەکان دروست بکە
if ($firstRun) {
    $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
}

const DAYS = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە'];
const PERIODS_PER_DAY = 6;
const DAYS_COUNT = 5;

function json_out($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function body_json() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}
