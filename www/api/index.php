<?php
// ============================================================
// API — هەموو داواکارییەکان لێرەوە دەڕۆن
// ?action=... بۆ دیاریکردنی کردار
// ============================================================
require_once __DIR__ . '/../includes/logic.php';
require_once __DIR__ . '/../includes/generator.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/extras.php';

$action = $_GET['action'] ?? '';
$b = in_array($action, ['backup_upload', 'import_asc', 'import_csv'], true) ? [] : body_json();

/** پاککردنەوەی خانەیەکی وانە لە داواکارییەکەوە. */
function lesson_from($b) {
    return [
        'class_id'    => (int) ($b['class_id'] ?? 0),
        'teacher_id'  => (int) ($b['teacher_id'] ?? 0),
        'subject_id'  => (int) ($b['subject_id'] ?? 0),
        'room_id'     => !empty($b['room_id']) ? (int) $b['room_id'] : null,
        'group_name'  => trim($b['group_name'] ?? ''),
        'joint_group' => trim($b['joint_group'] ?? ''),
        'day_of_week' => (int) ($b['day_of_week'] ?? -1),
        'period_no'   => (int) ($b['period_no'] ?? 0),
    ];
}
function require_name($v, $what) {
    $v = trim((string) $v);
    if ($v === '') fail("ناوی {$what} بنووسە.");
    return $v;
}
/** ئاگاداری میلاک و بابەت (ڕێگری ناکات، تەنها ئاگادار دەکاتەوە). */
function lesson_warnings($pdo, $l, $extra = 0) {
    $w = [];
    $t = q1($pdo, "SELECT max_periods FROM teachers WHERE id = ?", [$l['teacher_id']]);
    $assigned = teacher_loads($pdo)[$l['teacher_id']] ?? 0;
    if ($t && $assigned + $extra > (int) $t['max_periods']) {
        $w[] = "میلاکی ئەم مامۆستایە تێدەپەڕێت (" . ($assigned + $extra) . "/{$t['max_periods']}).";
    }
    $hasList = q1($pdo, "SELECT 1 FROM teacher_subjects WHERE teacher_id = ?", [$l['teacher_id']]);
    $teaches = q1($pdo, "SELECT 1 FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ?",
        [$l['teacher_id'], $l['subject_id']]);
    if ($hasList && !$teaches) $w[] = 'ئەم بابەتە لە لیستی بابەتەکانی ئەم مامۆستایەدا نییە.';
    // سنووری ڕۆژانە و وانەی بەدوای یەکدا
    $tt = q1($pdo, "SELECT max_per_day FROM teachers WHERE id = ?", [$l['teacher_id']]);
    $slots = array_map('intval', array_column(q($pdo, "SELECT DISTINCT period_no FROM timetable
              WHERE teacher_id = ? AND day_of_week = ?", [$l['teacher_id'], $l['day_of_week']]), 'period_no'));
    for ($k = 0; $k < max(1, $extra); $k++) $slots[] = $l['period_no'] + $k;
    $slots = array_unique($slots);
    if ($tt && $tt['max_per_day'] && count($slots) > (int) $tt['max_per_day']) {
        $w[] = "ئەم مامۆستایە لەم ڕۆژەدا " . count($slots) . " وانەی دەبێت (زۆرترین {$tt['max_per_day']}).";
    }
    $maxCons = cfg_constraints($pdo)['max_consecutive'];
    if ($maxCons) {
        $set = array_flip($slots);
        $lo = $l['period_no']; while (isset($set[$lo - 1])) $lo--;
        $hi = $l['period_no']; while (isset($set[$hi + 1])) $hi++;
        if ($hi - $lo + 1 > $maxCons) $w[] = ($hi - $lo + 1) . " وانەی بەدوای یەکدا (زۆرترین {$maxCons}).";
    }
    return $w ? 'ئاگاداری: ' . implode(' ', $w) : null;
}
function save_teacher_subjects($pdo, $tid, $ids) {
    $pdo->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?")->execute([$tid]);
    $st = $pdo->prepare("INSERT OR IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
    foreach ((array) $ids as $sid) $st->execute([$tid, (int) $sid]);
}

try {
switch ($action) {

    // ---------- ڕێکخستنەکان ----------
    case 'settings_get':
        json_out(['ok' => true, 'data' => cfg_all($pdo)]);

    case 'settings_save':
        $days = array_values(array_filter(array_map('trim', (array) ($b['days'] ?? []))));
        $periods = (int) ($b['periods'] ?? 0);
        if (count($days) < 1 || count($days) > 7) fail('ژمارەی ڕۆژەکان دەبێت لە ١ تا ٧ بێت.');
        if ($periods < 1 || $periods > 12) fail('ژمارەی بەشە وانەکان دەبێت لە ١ تا ١٢ بێت.');
        $out = (int) q1($pdo, "SELECT COUNT(*) c FROM timetable WHERE day_of_week >= ? OR period_no > ?",
            [count($days), $periods])['c'];
        if ($out) fail("{$out} وانە لە دەرەوەی ئەم ڕۆژ/بەشە وانانەدا دانراون. سەرەتا ئەوانە بسڕەوە یان بگوازەوە.");
        $pdo->prepare("DELETE FROM teacher_offdays WHERE day_of_week >= ? OR period_no > ?")
            ->execute([count($days), $periods]);
        set_setting($pdo, 'school_name', trim($b['school_name'] ?? ''));
        set_setting($pdo, 'days', json_encode($days, JSON_UNESCAPED_UNICODE));
        set_setting($pdo, 'periods_per_day', $periods);
        $times = array_slice(array_map(fn($t) => trim((string) $t), (array) ($b['period_times'] ?? [])), 0, $periods);
        set_setting($pdo, 'period_times', json_encode($times, JSON_UNESCAPED_UNICODE));
        json_out(['ok' => true, 'data' => cfg_all($pdo)]);

    case 'constraints_get':
        json_out(['ok' => true, 'data' => cfg_constraints($pdo)]);

    case 'constraints_save':
        $c = [];
        foreach (CONSTRAINT_DEFAULTS as $k => $v) $c[$k] = max(0, min(12, (int) ($b[$k] ?? $v)));
        set_setting($pdo, 'constraints', json_encode($c));
        json_out(['ok' => true, 'data' => $c]);

    // ---------- مامۆستایان ----------
    case 'teachers_list':
        json_out(['ok' => true, 'data' => teachers_with_load($pdo)]);

    case 'teacher_add':
    case 'teacher_update':
        $name = require_name($b['full_name'] ?? '', 'مامۆستا');
        $max = (int) ($b['max_periods'] ?? 22);
        if ($max < 1 || $max > 60) fail('میلاک دەبێت لە ١ تا ٦٠ بێت.');
        $phone = trim($b['phone'] ?? '');
        $opt = fn($k) => isset($b[$k]) && $b[$k] !== '' && $b[$k] !== null ? max(0, (int) $b[$k]) : null;
        $limits = [$opt('max_per_day') ?: null, $opt('min_per_day') ?: null, $opt('max_gaps')];
        if ($limits[0] && $limits[1] && $limits[1] > $limits[0]) fail('کەمترین وانەی ڕۆژانە لە زۆرترین زیاترە.');
        if ($action === 'teacher_add') {
            $pdo->prepare("INSERT INTO teachers (full_name, phone, max_periods, max_per_day, min_per_day, max_gaps)
                           VALUES (?,?,?,?,?,?)")->execute([$name, $phone, $max, ...$limits]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $id = (int) $b['id'];
            $pdo->prepare("UPDATE teachers SET full_name=?, phone=?, max_periods=?, max_per_day=?, min_per_day=?,
                           max_gaps=? WHERE id=?")->execute([$name, $phone, $max, ...$limits, $id]);
        }
        if (isset($b['subject_ids'])) save_teacher_subjects($pdo, $id, $b['subject_ids']);
        json_out(['ok' => true, 'id' => $id]);

    case 'teacher_delete':
        undo_push($pdo, 'سڕینەوەی مامۆستا');
        $pdo->prepare("DELETE FROM teachers WHERE id=?")->execute([(int) $b['id']]);
        json_out(['ok' => true]);

    // ---------- بابەتەکان ----------
    case 'subjects_list':
        json_out(['ok' => true, 'data' => q($pdo, "SELECT * FROM subjects ORDER BY name")]);

    case 'subject_add':
        $pdo->prepare("INSERT INTO subjects (name) VALUES (?)")->execute([require_name($b['name'] ?? '', 'بابەت')]);
        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);

    case 'subject_update':
        $pref = in_array($b['time_pref'] ?? 'any', ['any', 'early', 'late'], true) ? ($b['time_pref'] ?? 'any') : 'any';
        $pdo->prepare("UPDATE subjects SET name=?, time_pref=? WHERE id=?")
            ->execute([require_name($b['name'] ?? '', 'بابەت'), $pref, (int) $b['id']]);
        json_out(['ok' => true]);

    case 'relations_list':
        json_out(['ok' => true, 'data' => q($pdo, "SELECT r.*, a.name a_name, b.name b_name FROM subject_relations r
            JOIN subjects a ON a.id = r.subject_a JOIN subjects b ON b.id = r.subject_b ORDER BY a.name")]);

    case 'relation_add':
        $a = (int) $b['subject_a']; $c = (int) $b['subject_b'];
        if (!$a || !$c || $a === $c) fail('دوو بابەتی جیاواز هەڵبژێرە.');
        if (!in_array($b['kind'] ?? '', ['not_same_day', 'consecutive'], true)) fail('جۆری پەیوەندی هەڵەیە.');
        $pdo->prepare("INSERT OR IGNORE INTO subject_relations (subject_a, subject_b, kind) VALUES (?,?,?)")
            ->execute([min($a, $c), max($a, $c), $b['kind']]);
        json_out(['ok' => true]);

    case 'relation_delete':
        $pdo->prepare("DELETE FROM subject_relations WHERE id=?")->execute([(int) $b['id']]);
        json_out(['ok' => true]);

    case 'subject_delete':
        undo_push($pdo, 'سڕینەوەی بابەت');
        $pdo->prepare("DELETE FROM subjects WHERE id=?")->execute([(int) $b['id']]);
        json_out(['ok' => true]);

    // ---------- پۆلەکان ----------
    case 'classes_list':
        json_out(['ok' => true, 'data' => q($pdo, "SELECT * FROM classes ORDER BY grade_level, name")]);

    case 'class_add':
        $pdo->prepare("INSERT INTO classes (name, grade_level) VALUES (?,?)")
            ->execute([require_name($b['name'] ?? '', 'پۆل'), (int) ($b['grade_level'] ?? 0) ?: null]);
        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);

    case 'class_update':
        $pdo->prepare("UPDATE classes SET name=?, grade_level=? WHERE id=?")
            ->execute([require_name($b['name'] ?? '', 'پۆل'), (int) ($b['grade_level'] ?? 0) ?: null, (int) $b['id']]);
        json_out(['ok' => true]);

    case 'class_delete':
        undo_push($pdo, 'سڕینەوەی پۆل');
        $pdo->prepare("DELETE FROM classes WHERE id=?")->execute([(int) $b['id']]);
        json_out(['ok' => true]);

    // ---------- ژوورەکان ----------
    case 'rooms_list':
        json_out(['ok' => true, 'data' => q($pdo, "SELECT * FROM rooms ORDER BY name")]);

    case 'room_add':
        $pdo->prepare("INSERT INTO rooms (name) VALUES (?)")->execute([require_name($b['name'] ?? '', 'ژوور')]);
        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);

    case 'room_update':
        $pdo->prepare("UPDATE rooms SET name=? WHERE id=?")
            ->execute([require_name($b['name'] ?? '', 'ژوور'), (int) $b['id']]);
        json_out(['ok' => true]);

    case 'room_delete':
        $pdo->prepare("DELETE FROM rooms WHERE id=?")->execute([(int) $b['id']]);
        json_out(['ok' => true]);

    // ---------- پرۆگرامی خوێندن (پێداویستی وانەی هەر پۆلێک) ----------
    case 'curriculum_list':
        $cid = (int) ($_GET['class_id'] ?? 0);
        json_out(['ok' => true, 'data' => curriculum($pdo, $cid ?: null)]);

    case 'curriculum_save':
        $ppw = (int) ($b['periods_per_week'] ?? 0);
        $dbl = (int) ($b['double_count'] ?? 0);
        if ($ppw < 1 || $ppw > 40) fail('ژمارەی وانەی هەفتانە دەبێت لە ١ تا ٤٠ بێت.');
        if ($dbl < 0 || $dbl * 2 > $ppw) fail('ژمارەی وانە دووانییەکان زۆرە بەراورد بە کۆی وانەکان.');
        $joint = trim($b['joint_group'] ?? '');
        $vals = [(int) $b['class_id'], (int) $b['subject_id'], ((int) ($b['teacher_id'] ?? 0)) ?: null,
                 ((int) ($b['room_id'] ?? 0)) ?: null, trim($b['group_name'] ?? ''), $ppw, $dbl, $joint];
        if ($joint !== '') {
            // هەموو بەشەکانی وانەیەکی هاوبەش دەبێت هەمان بابەت و ژمارەی وانەیان هەبێت
            $other = q1($pdo, "SELECT subject_id, periods_per_week FROM class_subjects WHERE joint_group = ? AND id <> ?",
                [$joint, (int) ($b['id'] ?? 0)]);
            if ($other && ((int) $other['subject_id'] !== $vals[1] || (int) $other['periods_per_week'] !== $ppw)) {
                fail("وانەی هاوبەشی «{$joint}» بابەت یان ژمارەی وانەی جیاوازی هەیە لە پۆلەکانی تردا.");
            }
        }
        try {
            if (!empty($b['id'])) {
                $pdo->prepare("UPDATE class_subjects SET class_id=?, subject_id=?, teacher_id=?, room_id=?,
                               group_name=?, periods_per_week=?, double_count=?, joint_group=? WHERE id=?")
                    ->execute([...$vals, (int) $b['id']]);
            } else {
                $pdo->prepare("INSERT INTO class_subjects (class_id, subject_id, teacher_id, room_id,
                               group_name, periods_per_week, double_count, joint_group) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute($vals);
            }
        } catch (PDOException $e) {
            fail('ئەم بابەتە (بەم گرووپە) پێشتر بۆ ئەم پۆلە زیادکراوە.');
        }
        json_out(['ok' => true]);

    case 'curriculum_delete':
        $pdo->prepare("DELETE FROM class_subjects WHERE id=?")->execute([(int) $b['id']]);
        json_out(['ok' => true]);

    case 'curriculum_copy':
        // کۆپیکردنی پرۆگرامی پۆلێک بۆ پۆلێکی تر (بۆ نموونە ٧/أ → ٧/ب)
        $pdo->prepare("INSERT OR IGNORE INTO class_subjects (class_id, subject_id, teacher_id, room_id,
                       group_name, periods_per_week, double_count)
                       SELECT ?, subject_id, NULL, room_id, group_name, periods_per_week, double_count
                       FROM class_subjects WHERE class_id = ?")
            ->execute([(int) $b['to_class_id'], (int) $b['from_class_id']]);
        json_out(['ok' => true]);

    // ---------- ڕۆژە بەتاڵەکانی مامۆستا ----------
    case 'offdays_list':
        $tid = (int) ($_GET['teacher_id'] ?? 0);
        json_out(['ok' => true, 'data' => q($pdo,
            "SELECT * FROM teacher_offdays WHERE teacher_id=? ORDER BY day_of_week, period_no", [$tid])]);

    case 'offday_add':
        $tid = (int) $b['teacher_id'];
        $day = (int) $b['day_of_week'];
        $period = isset($b['period_no']) && $b['period_no'] !== '' ? (int) $b['period_no'] : null;
        try {
            $pdo->prepare("INSERT INTO teacher_offdays (teacher_id, day_of_week, period_no) VALUES (?,?,?)")
                ->execute([$tid, $day, $period]);
        } catch (PDOException $e) {
            fail('ئەم کاتە پێشتر وەک بەتاڵ دیاری کراوە.');
        }
        // ئایا وانەی پێشتر دانراو لەگەڵ ئەم کاتە بەتاڵە تێکدەگیرێت؟
        $clash = q($pdo, "SELECT t.period_no, c.name cls, s.name subj FROM timetable t
                JOIN classes c ON c.id = t.class_id JOIN subjects s ON s.id = t.subject_id
                WHERE t.teacher_id = ? AND t.day_of_week = ? AND (? IS NULL OR t.period_no = ?)
                ORDER BY t.period_no", [$tid, $day, $period, $period]);
        $warning = null;
        if ($clash) {
            $list = implode('، ', array_map(fn($r) => "بەشە وانەی {$r['period_no']} ({$r['subj']} — {$r['cls']})", $clash));
            $warning = "ئاگاداری: ئەم مامۆستایە لەم کاتەدا وانەی هەیە: {$list}. ئەو وانانە بگوازەوە.";
        }
        json_out(['ok' => true, 'id' => $pdo->lastInsertId(), 'warning' => $warning]);

    case 'offday_delete':
        $pdo->prepare("DELETE FROM teacher_offdays WHERE id=?")->execute([(int) $b['id']]);
        json_out(['ok' => true]);

    // ---------- خشتە ----------
    case 'timetable_get':
        // view = class | teacher | room | all
        $view = $_GET['view'] ?? 'class';
        $id = (int) ($_GET['id'] ?? 0);
        $col = ['class' => 'class_id', 'teacher' => 'teacher_id', 'room' => 'room_id'][$view] ?? null;
        $sql = "SELECT t.*, s.name subject_name, te.full_name teacher_name, c.name class_name, r.name room_name
                FROM timetable t
                JOIN subjects s  ON s.id = t.subject_id
                JOIN teachers te ON te.id = t.teacher_id
                JOIN classes  c  ON c.id = t.class_id
                LEFT JOIN rooms r ON r.id = t.room_id";
        $rows = $col ? q($pdo, "$sql WHERE t.$col = ? ORDER BY t.group_name", [$id])
                     : q($pdo, "$sql ORDER BY t.group_name");
        json_out(['ok' => true, 'data' => $rows]);

    case 'timetable_suggest':
        $l = lesson_from($b);
        $ignore = !empty($b['id']) ? [(int) $b['id']] : [];
        json_out(['ok' => true, 'data' => suggest_teachers($pdo, $l, $l['subject_id'], $ignore)]);

    case 'timetable_assign':
        $l = lesson_from($b);
        if (!$l['class_id'] || !$l['teacher_id'] || !$l['subject_id']) fail('پۆل، بابەت و مامۆستا هەڵبژێرە.');
        $len = !empty($b['double']) ? 2 : 1;
        // وانەی هاوبەش: بۆ هەموو پۆلەکانی هەمان joint_group دادەنرێت
        $targets = [[$l['class_id'], $l['group_name']]];
        if ($l['joint_group'] !== '') {
            $targets = array_map(fn($r) => [(int) $r['class_id'], $r['group_name']],
                q($pdo, "SELECT class_id, group_name FROM class_subjects WHERE joint_group = ?", [$l['joint_group']]));
            if (!$targets) $targets = [[$l['class_id'], $l['group_name']]];
        }
        foreach ($targets as [$cid, $grp]) {
            for ($k = 0; $k < $len; $k++) {
                $c = check_conflicts($pdo, ['period_no' => $l['period_no'] + $k, 'class_id' => $cid, 'group_name' => $grp] + $l);
                if ($c && count($targets) > 1) {
                    $c = 'پۆلی «' . q1($pdo, "SELECT name FROM classes WHERE id = ?", [$cid])['name'] . '»: ' . $c;
                }
                if ($c) fail(($len > 1 ? "بەشە وانەی " . ($l['period_no'] + $k) . ": " : '') . $c);
            }
        }
        $warning = lesson_warnings($pdo, $l, $len);
        undo_push($pdo, 'دانانی وانە');
        $st = $pdo->prepare("INSERT INTO timetable (class_id, teacher_id, subject_id, room_id, group_name, joint_group,
                             day_of_week, period_no, locked) VALUES (?,?,?,?,?,?,?,?,?)");
        $pdo->beginTransaction();
        foreach ($targets as [$cid, $grp]) {
            for ($k = 0; $k < $len; $k++) {
                $st->execute([$cid, $l['teacher_id'], $l['subject_id'], $l['room_id'], $grp, $l['joint_group'],
                              $l['day_of_week'], $l['period_no'] + $k, isset($b['locked']) ? (int) !!$b['locked'] : 1]);
            }
        }
        $pdo->commit();
        json_out(['ok' => true, 'warning' => $warning]);

    case 'timetable_update':
        // گۆڕینی بابەت/مامۆستا/ژوور/گرووپ/شوێنی وانەیەکی هەبوو
        $id = (int) $b['id'];
        $old = q1($pdo, "SELECT * FROM timetable WHERE id = ?", [$id]);
        if (!$old) fail('ئەم وانەیە نەدۆزرایەوە.');
        $l = lesson_from(array_merge($old, array_intersect_key($b, $old)));
        $moving = $l['day_of_week'] != $old['day_of_week'] || $l['period_no'] != $old['period_no'];
        if ($old['joint_group'] !== '' && $moving) {
            // وانەی هاوبەش: هەموو پۆلەکان پێکەوە دەگوازرێنەوە
            $sibs = lesson_siblings($pdo, $old);
            $ids = array_map('intval', array_column($sibs, 'id'));
            foreach ($sibs as $s) {
                $e = check_conflicts($pdo, ['day_of_week' => $l['day_of_week'], 'period_no' => $l['period_no']] + $s, $ids);
                if ($e) fail($e);
            }
            undo_push($pdo, 'گواستنەوەی وانەی هاوبەش');
            $pdo->prepare("UPDATE timetable SET day_of_week=?, period_no=?, locked=1 WHERE id IN (" . implode(',', $ids) . ")")
                ->execute([$l['day_of_week'], $l['period_no']]);
            json_out(['ok' => true, 'warning' => null]);
        }
        if ($c = check_conflicts($pdo, $l, [$id])) fail($c);
        $warning = $l['teacher_id'] != $old['teacher_id'] ? lesson_warnings($pdo, $l, 1) : null;
        undo_push($pdo, isset($b['day_of_week']) ? 'گواستنەوەی وانە' : 'دەستکاریکردنی وانە');
        $locked = isset($b['locked']) ? (int) !!$b['locked']
                : (isset($b['day_of_week']) ? 1 : (int) $old['locked']); // گواستنەوەی دەستی = قوفڵ
        $pdo->prepare("UPDATE timetable SET class_id=?, teacher_id=?, subject_id=?, room_id=?, group_name=?,
                       day_of_week=?, period_no=?, locked=? WHERE id=?")
            ->execute([$l['class_id'], $l['teacher_id'], $l['subject_id'], $l['room_id'], $l['group_name'],
                       $l['day_of_week'], $l['period_no'], $locked, $id]);
        json_out(['ok' => true, 'warning' => $warning]);

    case 'timetable_swap':
        // ئاڵوگۆڕی شوێنی دوو وانە (بە ڕاکێشان بۆ سەر خانەیەکی پڕ)
        $a = q1($pdo, "SELECT * FROM timetable WHERE id = ?", [(int) $b['id_a']]);
        $c = q1($pdo, "SELECT * FROM timetable WHERE id = ?", [(int) $b['id_b']]);
        if (!$a || !$c) fail('وانەکە نەدۆزرایەوە.');
        if ($a['joint_group'] !== '' || $c['joint_group'] !== '') {
            fail('وانەی هاوبەش ئاڵوگۆڕ ناکرێت — بیگوازەوە بۆ خانەیەکی بەتاڵ.');
        }
        $ignore = [$a['id'], $c['id']];
        $na = array_merge($a, ['day_of_week' => $c['day_of_week'], 'period_no' => $c['period_no']]);
        $nc = array_merge($c, ['day_of_week' => $a['day_of_week'], 'period_no' => $a['period_no']]);
        if ($e = check_conflicts($pdo, $na, $ignore)) fail($e);
        if ($e = check_conflicts($pdo, $nc, $ignore)) fail($e);
        // دوو وانەکە خۆیان لە نێوان خۆیاندا تێکنەگیرێن
        if ($na['day_of_week'] == $nc['day_of_week'] && $na['period_no'] == $nc['period_no']) fail('هەمان خانەیە.');
        undo_push($pdo, 'ئاڵوگۆڕی وانە');
        $pdo->beginTransaction();
        // UNIQUE ی مامۆستا: سەرەتا یەکێکیان بۆ شوێنێکی کاتی دەبەین
        $pdo->prepare("UPDATE timetable SET period_no = -1 WHERE id = ?")->execute([$a['id']]);
        $pdo->prepare("UPDATE timetable SET day_of_week=?, period_no=?, locked=1 WHERE id=?")
            ->execute([$nc['day_of_week'], $nc['period_no'], $c['id']]);
        $pdo->prepare("UPDATE timetable SET day_of_week=?, period_no=?, locked=1 WHERE id=?")
            ->execute([$na['day_of_week'], $na['period_no'], $a['id']]);
        $pdo->commit();
        json_out(['ok' => true]);

    case 'timetable_remove':
        $row = q1($pdo, "SELECT * FROM timetable WHERE id = ?", [(int) $b['id']]);
        if (!$row) fail('ئەم وانەیە نەدۆزرایەوە.');
        undo_push($pdo, 'سڕینەوەی وانە');
        $ids = array_map('intval', array_column(lesson_siblings($pdo, $row), 'id'));
        $pdo->exec("DELETE FROM timetable WHERE id IN (" . implode(',', $ids) . ")");
        json_out(['ok' => true]);

    case 'timetable_clear':
        // scope = class (تەنها پۆلێک) | all ; only_auto = تەنها وانە ئۆتۆماتیکییەکان
        $where = [];
        $params = [];
        if (($b['scope'] ?? '') === 'class') { $where[] = 'class_id = ?'; $params[] = (int) $b['class_id']; }
        if (!empty($b['only_auto'])) $where[] = 'locked = 0';
        undo_push($pdo, 'پاککردنەوەی خشتە');
        $st = $pdo->prepare("DELETE FROM timetable" . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
        $st->execute($params);
        json_out(['ok' => true, 'deleted' => $st->rowCount()]);

    case 'timetable_generate':
        $seconds = max(3, min(120, (int) ($b['seconds'] ?? 10)));
        @set_time_limit($seconds + 60);
        $res = generate_timetable($pdo, !empty($b['regenerate']), $seconds);
        json_out(['ok' => true, 'data' => $res]);

    // ---------- گەڕانەوە ----------
    case 'undo_info':
        json_out(['ok' => true, 'data' => undo_peek($pdo)]);

    case 'undo':
        $label = undo_pop($pdo);
        if ($label === null) fail('هیچ گۆڕانکارییەک نییە بۆ گەڕانەوە.');
        json_out(['ok' => true, 'label' => $label]);

    // ---------- ڕاپۆرتەکان ----------
    case 'load_report':
        $rows = teachers_with_load($pdo);
        foreach ($rows as &$r) {
            $r['status'] = $r['remaining'] < 0 ? 'over' : ($r['remaining'] == 0 ? 'full' : 'under');
        }
        json_out(['ok' => true, 'data' => $rows]);

    case 'validate':
        json_out(['ok' => true, 'data' => validate_all($pdo)]);

    case 'quality':
        json_out(['ok' => true, 'data' => quality_report($pdo)]);

    // ---------- هێنان (Import) ----------
    case 'import_asc':
        json_out(['ok' => true, 'data' => import_asc_xml($pdo, $_FILES['file'] ?? null, !empty($_POST['with_cards']))]);

    case 'import_csv':
        json_out(['ok' => true, 'data' => import_curriculum_csv($pdo, $_FILES['file'] ?? null)]);

    case 'csv_template':
        csv_template();

    // ---------- مامۆستای جێگرەوە ----------
    case 'subs_day':
        json_out(['ok' => true, 'data' => substitution_day($pdo, $_GET['date'] ?? '', (int) ($_GET['teacher_id'] ?? 0))]);

    case 'subs_save':
        substitution_save($pdo, $b);
        json_out(['ok' => true]);

    case 'subs_delete':
        $pdo->prepare("DELETE FROM substitutions WHERE id=?")->execute([(int) $b['id']]);
        json_out(['ok' => true]);

    case 'subs_list':
        json_out(['ok' => true, 'data' => substitution_list($pdo, $_GET['date'] ?? '')]);

    // ---------- بڵاوکردنەوە بۆ مۆبایل ----------
    case 'publish_html':
        publish_html($pdo);

    case 'export_csv':
        export_csv($pdo, $_GET['view'] ?? 'class');

    // ---------- پاڵپشتی (Backup) ----------
    case 'backup_list':
        json_out(['ok' => true, 'data' => backup_list(), 'dir' => realpath($BACKUP_DIR) ?: $BACKUP_DIR]);

    case 'backup_create':
        json_out(['ok' => true, 'file' => backup_create($pdo, 'manual')]);

    case 'backup_restore':
        backup_restore($pdo, $b['file'] ?? '');
        json_out(['ok' => true]);

    case 'backup_download':
        backup_download($pdo, $_GET['file'] ?? '');

    case 'backup_upload':
        backup_upload($pdo, $_FILES['file'] ?? null);
        json_out(['ok' => true]);

    default:
        fail('کرداری نەناسراو: ' . $action);
}
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE')) {
        fail('تێکهەڵچوون هەیە — ئەم خانەیە پێشتر پڕ کراوەتەوە.');
    }
    fail($e->getMessage());
}
