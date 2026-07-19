<?php
// ============================================================
// ناوکی سیستەم: ڕێگری لە تێکهەڵچوون و پشکنینەکان
// ============================================================
require_once __DIR__ . '/db.php';

/**
 * پشکنینی هەموو ڕێگرییەکان پێش دانانی وانەیەک.
 * ئەگەر کێشەیەک هەبێت، ناوەڕۆکی هەڵەکە دەگەڕێنێتەوە، ئەگەر نا null.
 */
function check_conflicts($pdo, $class_id, $teacher_id, $day, $period, $ignore_id = null) {

    // ٠) دروستی ژمارەکان
    if ($day < 0 || $day >= DAYS_COUNT) return 'ڕۆژی هەڵە.';
    if ($period < 1 || $period > PERIODS_PER_DAY) return 'بەشە وانەی هەڵە.';

    // ١) ئایا پۆلەکە لەم خانەیەدا وانەی هەیە؟
    $sql = "SELECT t.id, s.name subj, te.full_name teacher
            FROM timetable t
            JOIN subjects s  ON s.id = t.subject_id
            JOIN teachers te ON te.id = t.teacher_id
            WHERE t.class_id = ? AND t.day_of_week = ? AND t.period_no = ?";
    $params = [$class_id, $day, $period];
    if ($ignore_id) { $sql .= " AND t.id <> ?"; $params[] = $ignore_id; }
    $row = q1($pdo, $sql, $params);
    if ($row) {
        return "ئەم پۆلە لەم کاتەدا وانەی «{$row['subj']}» ی هەیە لەگەڵ مامۆستا {$row['teacher']}.";
    }

    // ٢) ئایا مامۆستاکە لەم خانەیەدا لە پۆلێکی تر وانەی هەیە؟
    $sql = "SELECT t.id, c.name cls
            FROM timetable t
            JOIN classes c ON c.id = t.class_id
            WHERE t.teacher_id = ? AND t.day_of_week = ? AND t.period_no = ?";
    $params = [$teacher_id, $day, $period];
    if ($ignore_id) { $sql .= " AND t.id <> ?"; $params[] = $ignore_id; }
    $row = q1($pdo, $sql, $params);
    if ($row) {
        return "ئەم مامۆستایە لەم کاتەدا لە پۆلی «{$row['cls']}» وانەی هەیە — ناتوانێت لە دوو پۆلدا بێت.";
    }

    // ٣) ئایا ئەم کاتە بۆ مامۆستاکە بەتاڵ (off) کراوە؟
    $sql = "SELECT id FROM teacher_offdays
            WHERE teacher_id = ? AND day_of_week = ?
              AND (period_no IS NULL OR period_no = ?)";
    $row = q1($pdo, $sql, [$teacher_id, $day, $period]);
    if ($row) {
        return "ئەم مامۆستایە لەم ڕۆژ/کاتەدا بەتاڵە (ناتوانێت دەوام بکات).";
    }

    return null; // هیچ تێکهەڵچوونێک نییە
}

/** میلاکی مامۆستا: چەند بەشە وانەی دراوەتێ لە کۆی چەند. */
function teacher_load($pdo, $teacher_id) {
    $assigned = (int) q1($pdo,
        "SELECT COUNT(*) c FROM timetable WHERE teacher_id = ?",
        [$teacher_id])['c'];
    $max = (int) q1($pdo,
        "SELECT max_periods m FROM teachers WHERE id = ?",
        [$teacher_id])['m'];
    return ['assigned' => $assigned, 'max' => $max, 'remaining' => $max - $assigned];
}

/** پێشنیاری ئۆتۆماتیکی مامۆستایانی بەردەست بۆ خانەیەکی دیاریکراو. */
function suggest_teachers($pdo, $class_id, $subject_id, $day, $period) {
    // مامۆستایانێک کە:
    //  - لەم کاتەدا بەردەستن (تێکهەڵچوونیان نییە)
    //  - میلاکەکەیان پڕ نەبووە
    $rows = q($pdo, "SELECT id, full_name FROM teachers ORDER BY full_name");
    $out = [];
    foreach ($rows as $t) {
        $conflict = check_conflicts($pdo, $class_id, $t['id'], $day, $period);
        if ($conflict) continue;
        $load = teacher_load($pdo, $t['id']);
        if ($load['remaining'] <= 0) continue;
        $out[] = [
            'id'        => $t['id'],
            'name'      => $t['full_name'],
            'remaining' => $load['remaining'],
        ];
    }
    // ئەوانەی زۆرترین بەشە وانەی ماوەیان هەیە یەکەم
    usort($out, fn($a, $b) => $b['remaining'] - $a['remaining']);
    return $out;
}

// --- یارمەتیدەرەکان ---
function q($pdo, $sql, $params = []) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}
function q1($pdo, $sql, $params = []) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetch() ?: null;
}
