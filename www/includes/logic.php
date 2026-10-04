<?php
// ============================================================
// ناوکی سیستەم: ڕێگری لە تێکهەڵچوون و پشکنینەکان
// ============================================================
require_once __DIR__ . '/db.php';

/**
 * ئایا دوو وانە دەتوانن لە یەک کاتدا بۆ یەک پۆل بن؟
 * تەنها ئەگەر هەردووکیان گرووپیان هەبێت و گرووپەکانیان جیاواز بن.
 */
function groups_compatible($a, $b) {
    return $a !== '' && $b !== '' && $a !== $b;
}

/**
 * پشکنینی هەموو ڕێگرییەکان پێش دانانی وانەیەک.
 * $l = [class_id, teacher_id, room_id, group_name, joint_group, day_of_week, period_no]
 * وانەی هاوبەش (joint_group) دەتوانێت هەمان مامۆستا و ژوور لە چەند پۆلێکدا بەکاربهێنێت.
 * ئەگەر کێشەیەک هەبێت، ناوەڕۆکی هەڵەکە دەگەڕێنێتەوە، ئەگەر نا null.
 */
function check_conflicts($pdo, $l, $ignore_ids = []) {
    $day = (int) $l['day_of_week'];
    $period = (int) $l['period_no'];
    $group = trim($l['group_name'] ?? '');
    $joint = trim($l['joint_group'] ?? '');
    // وانەکانی هەمان وانەی هاوبەش ڕێگر نین بۆ مامۆستا و ژوور
    $notSibling = $joint !== '' ? ' AND t.joint_group <> ' . $pdo->quote($joint) : '';
    $ignore = array_map('intval', (array) $ignore_ids);
    $notIgnored = $ignore ? ' AND t.id NOT IN (' . implode(',', $ignore) . ')' : '';
    $days = cfg_days($pdo);

    // ٠) دروستی ژمارەکان
    if ($day < 0 || $day >= count($days)) return 'ڕۆژی هەڵە.';
    if ($period < 1 || $period > cfg_periods($pdo)) return 'بەشە وانەی هەڵە.';

    // ١) ئایا پۆلەکە لەم خانەیەدا وانەی هەیە؟ (گرووپی جیاواز ڕێگەپێدراوە)
    $rows = q($pdo, "SELECT t.group_name, s.name subj, te.full_name teacher
            FROM timetable t
            JOIN subjects s  ON s.id = t.subject_id
            JOIN teachers te ON te.id = t.teacher_id
            WHERE t.class_id = ? AND t.day_of_week = ? AND t.period_no = ? $notIgnored",
        [(int) $l['class_id'], $day, $period]);
    foreach ($rows as $row) {
        if (!groups_compatible($group, $row['group_name'])) {
            $g = $row['group_name'] !== '' ? " (گرووپی {$row['group_name']})" : '';
            return "ئەم پۆلە لەم کاتەدا وانەی «{$row['subj']}»{$g} ی هەیە لەگەڵ مامۆستا {$row['teacher']}.";
        }
    }

    // ٢) ئایا مامۆستاکە لەم خانەیەدا لە پۆلێکی تر وانەی هەیە؟
    $row = q1($pdo, "SELECT c.name cls FROM timetable t JOIN classes c ON c.id = t.class_id
            WHERE t.teacher_id = ? AND t.day_of_week = ? AND t.period_no = ? $notIgnored $notSibling",
        [(int) $l['teacher_id'], $day, $period]);
    if ($row) {
        return "ئەم مامۆستایە لەم کاتەدا لە پۆلی «{$row['cls']}» وانەی هەیە — ناتوانێت لە دوو شوێندا بێت.";
    }

    // ٣) ئایا ئەم کاتە بۆ مامۆستاکە بەتاڵ (off) کراوە؟
    $row = q1($pdo, "SELECT id FROM teacher_offdays
            WHERE teacher_id = ? AND day_of_week = ? AND (period_no IS NULL OR period_no = ?)",
        [(int) $l['teacher_id'], $day, $period]);
    if ($row) {
        return "ئەم مامۆستایە لەم ڕۆژ/کاتەدا بەتاڵە (ناتوانێت دەوام بکات).";
    }

    // ٤) ئایا ژوورەکە لەم کاتەدا گیراوە؟
    if (!empty($l['room_id'])) {
        $row = q1($pdo, "SELECT c.name cls, r.name room FROM timetable t
                JOIN classes c ON c.id = t.class_id JOIN rooms r ON r.id = t.room_id
                WHERE t.room_id = ? AND t.day_of_week = ? AND t.period_no = ? $notIgnored $notSibling",
            [(int) $l['room_id'], $day, $period]);
        if ($row) {
            return "ژووری «{$row['room']}» لەم کاتەدا بۆ پۆلی «{$row['cls']}» گیراوە.";
        }
    }

    return null; // هیچ تێکهەڵچوونێک نییە
}

/** میلاکی هەموو مامۆستایان بە یەک query: [teacher_id => assigned]
 *  وانەی هاوبەش (چەند پۆل لە یەک کاتدا) یەکجار دەژمێردرێت. */
function teacher_loads($pdo) {
    $out = [];
    foreach (q($pdo, "SELECT teacher_id, COUNT(DISTINCT day_of_week * 100 + period_no) c
                      FROM timetable GROUP BY teacher_id") as $r) {
        $out[(int) $r['teacher_id']] = (int) $r['c'];
    }
    return $out;
}

/** لیستی مامۆستایان لەگەڵ میلاک و بابەتەکانیان. */
function teachers_with_load($pdo) {
    $loads = teacher_loads($pdo);
    $subj = [];
    foreach (q($pdo, "SELECT teacher_id, subject_id FROM teacher_subjects") as $r) {
        $subj[(int) $r['teacher_id']][] = (int) $r['subject_id'];
    }
    $rows = q($pdo, "SELECT * FROM teachers ORDER BY full_name");
    foreach ($rows as &$r) {
        $id = (int) $r['id'];
        $r['assigned']    = $loads[$id] ?? 0;
        $r['remaining']   = (int) $r['max_periods'] - $r['assigned'];
        $r['subject_ids'] = $subj[$id] ?? [];
    }
    return $rows;
}

/**
 * پێشنیاری ئۆتۆماتیکی مامۆستایانی بەردەست بۆ خانەیەکی دیاریکراو.
 * تەنها ئەو مامۆستایانەی ئەم بابەتە دەڵێنەوە (ئەگەر بابەتەکەیان دیاری کرابێت)،
 * و مامۆستای دیاریکراوی پرۆگرامی خوێندن یەکەم دێت.
 */
function suggest_teachers($pdo, $l, $subject_id, $ignore_ids = []) {
    $subject_id = (int) $subject_id;
    $qualified = array_map('intval', array_column(
        q($pdo, "SELECT teacher_id FROM teacher_subjects WHERE subject_id = ?", [$subject_id]), 'teacher_id'));
    $planned = q1($pdo, "SELECT teacher_id FROM class_subjects
                         WHERE class_id = ? AND subject_id = ? AND group_name = ?",
        [(int) $l['class_id'], $subject_id, trim($l['group_name'] ?? '')]);
    $plannedId = $planned ? (int) $planned['teacher_id'] : 0;

    // وانەی دەستکاریکراو لە میلاکی مامۆستاکەی خۆی ناژمێردرێت
    $freed = [];
    foreach ($ignore_ids as $iid) {
        $r = q1($pdo, "SELECT teacher_id FROM timetable WHERE id = ?", [(int) $iid]);
        if ($r) $freed[(int) $r['teacher_id']] = ($freed[(int) $r['teacher_id']] ?? 0) + 1;
    }

    $out = [];
    foreach (teachers_with_load($pdo) as $t) {
        $id = (int) $t['id'];
        $t['remaining'] += $freed[$id] ?? 0;
        // ئەگەر هیچ مامۆستایەک بۆ ئەم بابەتە دیاری نەکرابێت، هەمووان پیشان بدە
        if ($qualified && !in_array($id, $qualified, true) && $id !== $plannedId) continue;
        if ($t['remaining'] <= 0) continue;
        if (check_conflicts($pdo, ['teacher_id' => $id] + $l, $ignore_ids)) continue;
        $out[] = [
            'id'        => $id,
            'name'      => $t['full_name'],
            'remaining' => $t['remaining'],
            'planned'   => $id === $plannedId,
        ];
    }
    // مامۆستای پرۆگرام یەکەم، پاشان ئەوانەی زۆرترین بەشە وانەیان ماوە
    usort($out, fn($a, $b) => [$b['planned'], $b['remaining']] <=> [$a['planned'], $a['remaining']]);
    return $out;
}

/** کۆی وانەکانی هەر بابەتێکی پۆلێک کە دانراون: "class|subject|group" => count */
function placed_counts($pdo) {
    $out = [];
    foreach (q($pdo, "SELECT class_id, subject_id, group_name, COUNT(*) c FROM timetable
                      GROUP BY class_id, subject_id, group_name") as $r) {
        $out["{$r['class_id']}|{$r['subject_id']}|{$r['group_name']}"] = (int) $r['c'];
    }
    return $out;
}

/** پرۆگرامی خوێندنی پۆلێک (یان هەموو پۆلەکان) لەگەڵ ژمارەی وانە دانراوەکان. */
function curriculum($pdo, $class_id = null) {
    $sql = "SELECT cs.*, s.name subject_name, te.full_name teacher_name, r.name room_name, c.name class_name
            FROM class_subjects cs
            JOIN subjects s ON s.id = cs.subject_id
            JOIN classes  c ON c.id = cs.class_id
            LEFT JOIN teachers te ON te.id = cs.teacher_id
            LEFT JOIN rooms r ON r.id = cs.room_id";
    $rows = $class_id ? q($pdo, "$sql WHERE cs.class_id = ? ORDER BY s.name, cs.group_name", [(int) $class_id])
                      : q($pdo, "$sql ORDER BY c.grade_level, c.name, s.name, cs.group_name");
    $placed = placed_counts($pdo);
    $joint = [];
    foreach (q($pdo, "SELECT cs.joint_group, c.name FROM class_subjects cs JOIN classes c ON c.id = cs.class_id
                      WHERE cs.joint_group <> '' ORDER BY c.name") as $j) {
        $joint[$j['joint_group']][] = $j['name'];
    }
    foreach ($rows as &$r) {
        $r['placed'] = $placed["{$r['class_id']}|{$r['subject_id']}|{$r['group_name']}"] ?? 0;
        $r['joint_classes'] = $r['joint_group'] !== '' ? ($joint[$r['joint_group']] ?? []) : [];
    }
    return $rows;
}

/**
 * پشکنینی گشتی خشتەکە — هەموو کێشەکان دەگەڕێنێتەوە.
 * هەر کێشەیەک: [level => error|warn, text]
 */
function validate_all($pdo) {
    $issues = [];
    $days = cfg_days($pdo);
    $periods = cfg_periods($pdo);
    $dayName = fn($d) => $days[$d] ?? ('ڕۆژی ' . ($d + 1));

    // ١) وانە کە لەگەڵ کاتی بەتاڵی مامۆستا تێکدەگیرێت
    foreach (q($pdo, "SELECT t.day_of_week, t.period_no, te.full_name, c.name cls, s.name subj
            FROM timetable t
            JOIN teacher_offdays o ON o.teacher_id = t.teacher_id AND o.day_of_week = t.day_of_week
                                  AND (o.period_no IS NULL OR o.period_no = t.period_no)
            JOIN teachers te ON te.id = t.teacher_id
            JOIN classes c ON c.id = t.class_id
            JOIN subjects s ON s.id = t.subject_id
            ORDER BY te.full_name, t.day_of_week, t.period_no") as $r) {
        $issues[] = ['level' => 'error', 'text' =>
            "مامۆستا {$r['full_name']} لە {$dayName($r['day_of_week'])}، بەشە وانەی {$r['period_no']} بەتاڵە، بەڵام وانەی «{$r['subj']}» ی پۆلی «{$r['cls']}» ی هەیە."];
    }

    // ٢) میلاک تێپەڕێنراو
    foreach (teachers_with_load($pdo) as $t) {
        if ($t['remaining'] < 0) {
            $issues[] = ['level' => 'warn', 'text' =>
                "مامۆستا {$t['full_name']} میلاکی تێپەڕاندووە ({$t['assigned']}/{$t['max_periods']})."];
        }
    }

    // ٣) پرۆگرامی خوێندن: کەم یان زیاد
    foreach (curriculum($pdo) as $c) {
        $g = $c['group_name'] !== '' ? " (گرووپی {$c['group_name']})" : '';
        if ($c['placed'] < $c['periods_per_week']) {
            $issues[] = ['level' => 'warn', 'text' =>
                "پۆلی «{$c['class_name']}»: «{$c['subject_name']}»{$g} {$c['placed']} لە {$c['periods_per_week']} وانەی دانراوە."];
        } elseif ($c['placed'] > $c['periods_per_week']) {
            $issues[] = ['level' => 'warn', 'text' =>
                "پۆلی «{$c['class_name']}»: «{$c['subject_name']}»{$g} {$c['placed']} وانەی هەیە، زیاترە لە {$c['periods_per_week']}."];
        }
        if (!$c['teacher_id']) {
            $issues[] = ['level' => 'warn', 'text' =>
                "پۆلی «{$c['class_name']}»: هیچ مامۆستایەک بۆ «{$c['subject_name']}»{$g} دیاری نەکراوە."];
        }
    }

    // ٤) مامۆستا بابەتێک دەڵێتەوە کە لە لیستی بابەتەکانیدا نییە
    foreach (q($pdo, "SELECT DISTINCT te.full_name, s.name subj FROM timetable t
            JOIN teachers te ON te.id = t.teacher_id JOIN subjects s ON s.id = t.subject_id
            WHERE EXISTS (SELECT 1 FROM teacher_subjects x WHERE x.teacher_id = t.teacher_id)
              AND NOT EXISTS (SELECT 1 FROM teacher_subjects x
                              WHERE x.teacher_id = t.teacher_id AND x.subject_id = t.subject_id)") as $r) {
        $issues[] = ['level' => 'warn', 'text' =>
            "مامۆستا {$r['full_name']} وانەی «{$r['subj']}» دەڵێتەوە، بەڵام ئەم بابەتە لە لیستی بابەتەکانیدا نییە."];
    }

    // ٥) وانە لە دەرەوەی ڕۆژ/بەشە وانەکانی ئێستا (دوای گۆڕینی ڕێکخستن)
    $n = (int) q1($pdo, "SELECT COUNT(*) c FROM timetable WHERE day_of_week >= ? OR period_no > ?",
        [count($days), $periods])['c'];
    if ($n) {
        $issues[] = ['level' => 'error', 'text' => "{$n} وانە لە دەرەوەی ڕۆژ/بەشە وانەکانی ئێستادان."];
    }

    return $issues;
}

// ---------------- گەڕانەوە (Undo) ----------------
const UNDO_KEEP = 30;

/** پێش هەر گۆڕانکارییەکی خشتە، وێنەیەکی تەواوی خشتەکە هەڵدەگیرێت. */
function undo_push($pdo, $label) {
    $rows = q($pdo, "SELECT id, class_id, teacher_id, subject_id, room_id, group_name, joint_group,
                            day_of_week, period_no, locked, created_at FROM timetable");
    $pdo->prepare("INSERT INTO undo_log (label, snapshot) VALUES (?, ?)")
        ->execute([$label, json_encode($rows)]);
    $pdo->exec("DELETE FROM undo_log WHERE id NOT IN (SELECT id FROM undo_log ORDER BY id DESC LIMIT " . UNDO_KEEP . ")");
}

function undo_pop($pdo) {
    $last = q1($pdo, "SELECT * FROM undo_log ORDER BY id DESC LIMIT 1");
    if (!$last) return null;
    $rows = json_decode($last['snapshot'], true) ?: [];
    // ئەو مامۆستا/پۆل/بابەتانەی دواتر سڕاونەتەوە ناگەڕێنەوە
    $exists = fn($table, $id) => (bool) q1($pdo, "SELECT 1 FROM $table WHERE id = ?", [$id]);
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM timetable");
        $st = $pdo->prepare("INSERT INTO timetable (id, class_id, teacher_id, subject_id, room_id, group_name,
                             joint_group, day_of_week, period_no, locked, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $r) {
            if (!$exists('classes', $r['class_id']) || !$exists('teachers', $r['teacher_id'])
                || !$exists('subjects', $r['subject_id'])) continue;
            $room = $r['room_id'] && $exists('rooms', $r['room_id']) ? $r['room_id'] : null;
            $st->execute([$r['id'], $r['class_id'], $r['teacher_id'], $r['subject_id'], $room,
                $r['group_name'], $r['joint_group'] ?? '', $r['day_of_week'], $r['period_no'], $r['locked'], $r['created_at']]);
        }
        $pdo->prepare("DELETE FROM undo_log WHERE id = ?")->execute([$last['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $last['label'];
}

function undo_peek($pdo) {
    $r = q1($pdo, "SELECT label, created_at FROM undo_log ORDER BY id DESC LIMIT 1");
    return $r ? $r + ['count' => (int) q1($pdo, "SELECT COUNT(*) c FROM undo_log")['c']] : null;
}

/** هەموو ڕیزەکانی هەمان وانەی هاوبەش لە هەمان کاتدا (بۆ وانەی ئاسایی تەنها خۆی). */
function lesson_siblings($pdo, $row) {
    if (($row['joint_group'] ?? '') === '') return [$row];
    return q($pdo, "SELECT * FROM timetable WHERE joint_group = ? AND teacher_id = ?
                    AND day_of_week = ? AND period_no = ?",
        [$row['joint_group'], $row['teacher_id'], $row['day_of_week'], $row['period_no']]);
}

/** ڕێکخستنی ڕێگرییەکان. کێشەکان: 0 = ناچالاک، 1 = ئاسایی، 3 = گرنگ */
const CONSTRAINT_DEFAULTS = [
    'max_teacher_gaps' => 1,   // زۆرترین بۆشایی مامۆستا لە ڕۆژێکدا
    'max_consecutive'  => 0,   // زۆرترین وانەی بەدوای یەکدا (0 = بێ سنوور)
    'w_teacher_gaps'   => 1,
    'w_class_gaps'     => 3,
    'w_subject_time'   => 1,
    'w_relations'      => 3,
    'w_min_per_day'    => 1,
];
function cfg_constraints($pdo) {
    $saved = json_decode(setting($pdo, 'constraints', ''), true) ?: [];
    $out = [];
    foreach (CONSTRAINT_DEFAULTS as $k => $v) $out[$k] = isset($saved[$k]) ? (int) $saved[$k] : $v;
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
