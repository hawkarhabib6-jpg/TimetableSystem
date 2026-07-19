<?php
// ============================================================
// API — هەموو داواکارییەکان لێرەوە دەڕۆن
// ?action=... بۆ دیاریکردنی کردار
// ============================================================
require_once __DIR__ . '/../includes/logic.php';

$action = $_GET['action'] ?? '';

try {
switch ($action) {

    // ---------- مامۆستایان ----------
    case 'teachers_list':
        $rows = q($pdo, "SELECT * FROM teachers ORDER BY full_name");
        foreach ($rows as &$r) {
            $load = teacher_load($pdo, $r['id']);
            $r['assigned']  = $load['assigned'];
            $r['remaining'] = $load['remaining'];
        }
        json_out(['ok' => true, 'data' => $rows]);

    case 'teacher_add':
        $b = body_json();
        $st = $pdo->prepare("INSERT INTO teachers (full_name, phone, max_periods) VALUES (?,?,?)");
        $st->execute([trim($b['full_name']), trim($b['phone'] ?? ''), (int)($b['max_periods'] ?? 22)]);
        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);

    case 'teacher_update':
        $b = body_json();
        $st = $pdo->prepare("UPDATE teachers SET full_name=?, phone=?, max_periods=? WHERE id=?");
        $st->execute([trim($b['full_name']), trim($b['phone'] ?? ''), (int)$b['max_periods'], (int)$b['id']]);
        json_out(['ok' => true]);

    case 'teacher_delete':
        $b = body_json();
        $pdo->prepare("DELETE FROM teachers WHERE id=?")->execute([(int)$b['id']]);
        json_out(['ok' => true]);

    // ---------- بابەتەکان ----------
    case 'subjects_list':
        json_out(['ok' => true, 'data' => q($pdo, "SELECT * FROM subjects ORDER BY name")]);

    case 'subject_add':
        $b = body_json();
        $st = $pdo->prepare("INSERT INTO subjects (name) VALUES (?)");
        $st->execute([trim($b['name'])]);
        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);

    case 'subject_delete':
        $b = body_json();
        $pdo->prepare("DELETE FROM subjects WHERE id=?")->execute([(int)$b['id']]);
        json_out(['ok' => true]);

    // ---------- پۆلەکان ----------
    case 'classes_list':
        json_out(['ok' => true, 'data' => q($pdo, "SELECT * FROM classes ORDER BY grade_level, name")]);

    case 'class_add':
        $b = body_json();
        $st = $pdo->prepare("INSERT INTO classes (name, grade_level) VALUES (?,?)");
        $st->execute([trim($b['name']), (int)($b['grade_level'] ?? 0) ?: null]);
        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);

    case 'class_delete':
        $b = body_json();
        $pdo->prepare("DELETE FROM classes WHERE id=?")->execute([(int)$b['id']]);
        json_out(['ok' => true]);

    // ---------- ڕۆژە بەتاڵەکانی مامۆستا ----------
    case 'offdays_list':
        $tid = (int)($_GET['teacher_id'] ?? 0);
        json_out(['ok' => true, 'data' => q($pdo,
            "SELECT * FROM teacher_offdays WHERE teacher_id=? ORDER BY day_of_week, period_no", [$tid])]);

    case 'offday_add':
        $b = body_json();
        $period = isset($b['period_no']) && $b['period_no'] !== '' ? (int)$b['period_no'] : null;
        $st = $pdo->prepare("INSERT INTO teacher_offdays (teacher_id, day_of_week, period_no) VALUES (?,?,?)");
        $st->execute([(int)$b['teacher_id'], (int)$b['day_of_week'], $period]);
        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);

    case 'offday_delete':
        $b = body_json();
        $pdo->prepare("DELETE FROM teacher_offdays WHERE id=?")->execute([(int)$b['id']]);
        json_out(['ok' => true]);

    // ---------- خشتە ----------
    case 'timetable_get':
        $class_id = (int)($_GET['class_id'] ?? 0);
        $rows = q($pdo,
            "SELECT t.*, s.name subject_name, te.full_name teacher_name
             FROM timetable t
             JOIN subjects s  ON s.id=t.subject_id
             JOIN teachers te ON te.id=t.teacher_id
             WHERE t.class_id=?", [$class_id]);
        json_out(['ok' => true, 'data' => $rows]);

    case 'timetable_suggest':
        $b = body_json();
        json_out(['ok' => true, 'data' => suggest_teachers(
            $pdo, (int)$b['class_id'], (int)$b['subject_id'], (int)$b['day_of_week'], (int)$b['period_no'])]);

    case 'timetable_assign':
        $b = body_json();
        $class_id   = (int)$b['class_id'];
        $teacher_id = (int)$b['teacher_id'];
        $subject_id = (int)$b['subject_id'];
        $day        = (int)$b['day_of_week'];
        $period     = (int)$b['period_no'];

        // پشکنینی تێکهەڵچوون پێش دانان
        $conflict = check_conflicts($pdo, $class_id, $teacher_id, $day, $period);
        if ($conflict) {
            json_out(['ok' => false, 'error' => $conflict]);
        }

        // ئاگاداری میلاک (بەڵام ڕێگری ناکات، تەنها ئاگادار دەکاتەوە)
        $load = teacher_load($pdo, $teacher_id);
        $warning = null;
        if ($load['remaining'] <= 0) {
            $warning = "ئاگاداری: میلاکی ئەم مامۆستایە پڕ بووە ({$load['assigned']}/{$load['max']}).";
        }

        try {
            $st = $pdo->prepare("INSERT INTO timetable (class_id, teacher_id, subject_id, day_of_week, period_no)
                                 VALUES (?,?,?,?,?)");
            $st->execute([$class_id, $teacher_id, $subject_id, $day, $period]);
        } catch (PDOException $e) {
            // ئەگەر UNIQUE KEY تێکهەڵچوونی دۆزییەوە (پاڵپشتی دووەم)
            json_out(['ok' => false, 'error' => 'تێکهەڵچوون هەیە — ئەم خانەیە پێشتر پڕ کراوەتەوە.']);
        }
        json_out(['ok' => true, 'id' => $pdo->lastInsertId(), 'warning' => $warning]);

    case 'timetable_remove':
        $b = body_json();
        $pdo->prepare("DELETE FROM timetable WHERE id=?")->execute([(int)$b['id']]);
        json_out(['ok' => true]);

    // ---------- ڕاپۆرتی میلاکی هەموو مامۆستایان ----------
    case 'load_report':
        $rows = q($pdo, "SELECT * FROM teachers ORDER BY full_name");
        foreach ($rows as &$r) {
            $load = teacher_load($pdo, $r['id']);
            $r['assigned']  = $load['assigned'];
            $r['remaining'] = $load['remaining'];
            $r['status']    = $load['remaining'] < 0 ? 'over'
                            : ($load['remaining'] == 0 ? 'full' : 'under');
        }
        json_out(['ok' => true, 'data' => $rows]);

    default:
        json_out(['ok' => false, 'error' => 'کرداری نەناسراو: ' . $action]);
}
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()]);
}
