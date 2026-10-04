<?php
// ============================================================
// پاڵپشتی (Backup)، گەڕاندنەوە (Restore) و هەناردەکردن بۆ Excel
// ============================================================
require_once __DIR__ . '/logic.php';

const BACKUP_KEEP = 30;

function backup_dir() {
    global $BACKUP_DIR;
    if (!is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0777, true);
    return $BACKUP_DIR;
}

/** ناوی فایلی پاڵپشتی پاک دەکاتەوە بۆ ئەوەی نەتوانرێت بچێتە دەرەوەی فۆڵدەرەکە. */
function backup_path($file) {
    $file = basename((string) $file);
    if (!preg_match('/^[\w\-.]+\.db$/', $file)) throw new RuntimeException('ناوی فایلی پاڵپشتی هەڵەیە.');
    $path = backup_dir() . '/' . $file;
    if (!file_exists($path)) throw new RuntimeException('فایلی پاڵپشتی نەدۆزرایەوە.');
    return $path;
}

/** کۆپییەکی سەلامەتی بنکەی زانیاری (VACUUM INTO کۆپییەکی تەواو و ڕێکخراو دروست دەکات). */
function backup_create($pdo, $kind = 'manual') {
    $stamp = 'timetable-' . date('Y-m-d_His') . "-{$kind}";
    for ($i = 1, $name = "$stamp.db"; file_exists(backup_dir() . '/' . $name); $i++) $name = "$stamp-$i.db";
    $path = backup_dir() . '/' . $name;
    $pdo->prepare('VACUUM INTO ?')->execute([$path]);
    // تەنها نوێترینەکان بهێڵەوە
    $all = glob(backup_dir() . '/*.db') ?: [];
    usort($all, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($all, BACKUP_KEEP) as $old) @unlink($old);
    return $name;
}

/** ڕۆژانە یەک پاڵپشتی ئۆتۆماتیکی (لە کاتی کردنەوەی بەرنامەکە). */
function backup_auto_daily($pdo) {
    $today = date('Y-m-d');
    if (setting($pdo, 'last_auto_backup') === $today) return;
    if ((int) q1($pdo, "SELECT COUNT(*) c FROM teachers")['c'] === 0) return; // هێشتا داتا نییە
    backup_create($pdo, 'auto');
    set_setting($pdo, 'last_auto_backup', $today);
}

function backup_list() {
    $out = [];
    foreach (glob(backup_dir() . '/*.db') ?: [] as $f) {
        $out[] = ['file' => basename($f), 'size' => filesize($f), 'time' => date('Y-m-d H:i', filemtime($f))];
    }
    usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}

/**
 * گۆڕینی بنکەی زانیاری ئێستا بە فایلێکی تر.
 * پێشتر فایلەکە دەپشکنرێت و بۆ وەشانی نوێ نوێ دەکرێتەوە، و پاڵپشتییەک لە داتای ئێستا دەگیرێت.
 */
function replace_database(&$pdo, $source) {
    global $DB_FILE;
    $head = @file_get_contents($source, false, null, 0, 16);
    if ($head !== "SQLite format 3\0") throw new RuntimeException('ئەم فایلە بنکەی زانیاری SQLite نییە.');

    $tmp = dirname($DB_FILE) . '/restore-' . getmypid() . '.tmp';
    if (!@copy($source, $tmp)) throw new RuntimeException('نەتوانرا فایلەکە کۆپی بکرێت.');
    try {
        $check = open_db($tmp); // migrations جێبەجێ دەکات
        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('فایلەکە تێکچووە (integrity_check).');
        }
        $check->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
        $check = null;
    } catch (Throwable $e) {
        $check = null;
        @unlink($tmp);
        throw $e instanceof RuntimeException ? $e
            : new RuntimeException('ئەم فایلە بنکەی زانیاری ئەم بەرنامەیە نییە.');
    }

    backup_create($pdo, 'before-restore');
    $pdo = null;               // داخستنی پەیوەندی پێش گۆڕینی فایلەکە
    gc_collect_cycles();
    if (!@rename($tmp, $DB_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('نەتوانرا بنکەی زانیاری بگۆڕدرێت. بەرنامەکە دابخە و دووبارە هەوڵ بدەوە.');
    }
    $pdo = open_db($DB_FILE);
}

function backup_restore(&$pdo, $file) {
    replace_database($pdo, backup_path($file));
}

function backup_upload(&$pdo, $upload) {
    if (!$upload || ($upload['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('فایلەکە بارنەکرا.');
    replace_database($pdo, $upload['tmp_name']);
}

function backup_download($pdo, $file) {
    $path = $file === '' ? null : backup_path($file);
    if (!$path) { // داگرتنی داتای ئێستا
        $file = backup_create($pdo, 'export');
        $path = backup_path($file);
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/** هەناردەکردنی خشتەکان بۆ CSV کە Excel بە کوردی دەیکاتەوە (UTF-8 BOM). */
function export_csv($pdo, $view) {
    $days = cfg_days($pdo);
    $periods = cfg_periods($pdo);
    $times = cfg_period_times($pdo);
    [$table, $nameCol, $fk] = match ($view) {
        'teacher' => ['teachers', 'full_name', 'teacher_id'],
        'room'    => ['rooms', 'name', 'room_id'],
        default   => ['classes', 'name', 'class_id'],
    };
    $entities = q($pdo, "SELECT id, $nameCol AS name FROM $table ORDER BY $nameCol");
    $rows = q($pdo, "SELECT t.*, s.name subj, te.full_name teacher, c.name cls, r.name room
                     FROM timetable t JOIN subjects s ON s.id = t.subject_id
                     JOIN teachers te ON te.id = t.teacher_id JOIN classes c ON c.id = t.class_id
                     LEFT JOIN rooms r ON r.id = t.room_id ORDER BY t.group_name");
    $grid = [];
    foreach ($rows as $r) {
        if (!$r[$fk]) continue;
        $parts = [$r['subj']];
        if ($view !== 'class')   $parts[] = $r['cls'];
        if ($view !== 'teacher') $parts[] = $r['teacher'];
        if ($view !== 'room' && $r['room']) $parts[] = $r['room'];
        $text = implode(' - ', $parts) . ($r['group_name'] !== '' ? " ({$r['group_name']})" : '');
        $grid[$r[$fk]][$r['day_of_week']][$r['period_no']][] = $text;
    }

    $title = ['class' => 'پۆلەکان', 'teacher' => 'مامۆستایان', 'room' => 'ژوورەکان'][$view] ?? 'پۆلەکان';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timetable-' . preg_replace('/\W/', '', $view) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $school = setting($pdo, 'school_name', '');
    fputcsv($out, [trim("$school — خشتەی $title", ' —')]);
    foreach ($entities as $e) {
        fputcsv($out, []);
        fputcsv($out, [$e['name']]);
        fputcsv($out, array_merge(['بەشە وانە'], $days));
        for ($p = 1; $p <= $periods; $p++) {
            $line = [$p . (!empty($times[$p - 1]) ? " ({$times[$p - 1]})" : '')];
            foreach (array_keys($days) as $d) {
                $line[] = implode(' / ', $grid[$e['id']][$d][$p] ?? []);
            }
            fputcsv($out, $line);
        }
    }
    fclose($out);
    exit;
}
