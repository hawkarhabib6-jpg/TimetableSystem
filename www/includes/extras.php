<?php
// ============================================================
// هێنانی داتا (aSc XML و Excel/CSV)، مامۆستای جێگرەوە، بڵاوکردنەوە بۆ مۆبایل
// ============================================================
require_once __DIR__ . '/logic.php';
require_once __DIR__ . '/backup.php';

function uploaded_file($upload) {
    if (!$upload || ($upload['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('فایلەکە بارنەکرا.');
    return $upload['tmp_name'];
}

/** ناسنامەی شتێک بەپێی ناو، ئەگەر نەبوو دروستی دەکات. */
function id_by_name($pdo, $table, $col, $name, $extra = []) {
    $name = trim($name);
    if ($name === '') return null;
    $r = q1($pdo, "SELECT id FROM $table WHERE $col = ?", [$name]);
    if ($r) return (int) $r['id'];
    $cols = array_merge([$col], array_keys($extra));
    $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")
        ->execute(array_merge([$name], array_values($extra)));
    return (int) $pdo->lastInsertId();
}

/** زیادکردن یان کۆکردنەوەی بابەتێک لە پرۆگرامی پۆلێکدا. */
function upsert_curriculum($pdo, $cid, $sid, $tid, $rid, $grp, $ppw, $dbl, $joint = '') {
    $ex = q1($pdo, "SELECT id, periods_per_week, double_count FROM class_subjects
                    WHERE class_id = ? AND subject_id = ? AND group_name = ?", [$cid, $sid, $grp]);
    if ($ex) {
        // هەمان بابەت دوو جار (بۆ نموونە دوو مامۆستا): ژمارەی وانەکان کۆ دەکرێنەوە
        $pdo->prepare("UPDATE class_subjects SET periods_per_week = ?, double_count = ?,
                       teacher_id = COALESCE(teacher_id, ?) WHERE id = ?")
            ->execute([$ex['periods_per_week'] + $ppw, $ex['double_count'] + $dbl, $tid, $ex['id']]);
        return false;
    }
    $pdo->prepare("INSERT INTO class_subjects (class_id, subject_id, teacher_id, room_id, group_name,
                   periods_per_week, double_count, joint_group) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$cid, $sid, $tid, $rid, $grp, $ppw, $dbl, $joint]);
    return true;
}

function wipe_school_data($pdo) {
    foreach (['substitutions', 'timetable', 'class_subjects', 'teacher_subjects', 'teacher_offdays',
              'subject_relations', 'teachers', 'subjects', 'classes', 'rooms', 'undo_log'] as $t) {
        $pdo->exec("DELETE FROM $t");
    }
}

/**
 * هێنانی داتا لە فایلی XML ی aSc TimeTables (File → Export → aSc XML).
 * هەموو داتای ئێستا دەگۆڕدرێت (پێشتر پاڵپشتی دەگیرێت).
 */
function import_asc_xml($pdo, $upload, $withCards) {
    $file = uploaded_file($upload);
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if (!$xml || $xml->getName() !== 'timetable' || !isset($xml->lessons)) {
        throw new RuntimeException('ئەم فایلە XML ی aSc نییە. لە aSc: File ← Export ← aSc XML بەکاربهێنە.');
    }
    $a = fn($el, $k) => trim((string) ($el[$k] ?? ''));
    $ids = fn($s) => array_values(array_filter(array_map('trim', explode(',', $s))));

    backup_create($pdo, 'before-import');
    $pdo->beginTransaction();
    try {
        wipe_school_data($pdo);

        // ڕۆژەکان: daysdef ـەکان کە تەنها یەک ڕۆژیان تێدایە
        $days = [];
        foreach ($xml->daysdefs->daysdef ?? [] as $dd) {
            $bits = $a($dd, 'days');
            if (substr_count($bits, '1') === 1) $days[strpos($bits, '1')] = $a($dd, 'name');
        }
        ksort($days);
        $periods = [];
        foreach ($xml->periods->period ?? [] as $p) {
            $n = (int) $a($p, 'period');
            if ($n > 0) $periods[$n] = trim($a($p, 'starttime') . ' - ' . $a($p, 'endtime'), ' -');
        }
        if ($days && count($days) <= 7) set_setting($pdo, 'days', json_encode(array_values($days), JSON_UNESCAPED_UNICODE));
        if ($periods) {
            ksort($periods);
            set_setting($pdo, 'periods_per_day', min(12, max(array_keys($periods))));
            set_setting($pdo, 'period_times', json_encode(array_values($periods), JSON_UNESCAPED_UNICODE));
        }

        $map = ['s' => [], 't' => [], 'r' => [], 'c' => [], 'g' => []];
        foreach ($xml->subjects->subject ?? [] as $s) {
            $map['s'][$a($s, 'id')] = id_by_name($pdo, 'subjects', 'name', $a($s, 'name') ?: $a($s, 'short'));
        }
        foreach ($xml->teachers->teacher ?? [] as $t) {
            $name = $a($t, 'name') ?: trim($a($t, 'firstname') . ' ' . $a($t, 'lastname')) ?: $a($t, 'short');
            $map['t'][$a($t, 'id')] = id_by_name($pdo, 'teachers', 'full_name', $name, ['phone' => $a($t, 'mobile')]);
        }
        foreach ($xml->classrooms->classroom ?? [] as $r) {
            $map['r'][$a($r, 'id')] = id_by_name($pdo, 'rooms', 'name', $a($r, 'name') ?: $a($r, 'short'));
        }
        foreach ($xml->classes->class ?? [] as $c) {
            $grade = (int) preg_replace('/\D/', '', $a($c, 'grade')) ?: null;
            $map['c'][$a($c, 'id')] = id_by_name($pdo, 'classes', 'name', $a($c, 'name') ?: $a($c, 'short'), ['grade_level' => $grade]);
        }
        foreach ($xml->groups->group ?? [] as $g) {
            $map['g'][$a($g, 'id')] = [$a($g, 'classid'), $a($g, 'entireclass') === '1' ? '' : $a($g, 'name')];
        }

        $lessons = [];
        $load = [];
        $stTS = $pdo->prepare("INSERT OR IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
        foreach ($xml->lessons->lesson as $l) {
            $sid = $map['s'][$a($l, 'subjectid')] ?? null;
            $cls = array_values(array_filter(array_map(fn($x) => $map['c'][$x] ?? null, $ids($a($l, 'classids')))));
            if (!$sid || !$cls) continue;
            $teachers = array_values(array_filter(array_map(fn($x) => $map['t'][$x] ?? null, $ids($a($l, 'teacherids')))));
            $tid = $teachers[0] ?? null;
            foreach ($teachers as $t) $stTS->execute([$t, $sid]);
            $rooms = array_values(array_filter(array_map(fn($x) => $map['r'][$x] ?? null, $ids($a($l, 'classroomids')))));
            $grpOf = [];
            foreach ($ids($a($l, 'groupids')) as $gid) {
                if (!isset($map['g'][$gid])) continue;
                [$ascClass, $gname] = $map['g'][$gid];
                if (isset($map['c'][$ascClass])) $grpOf[$map['c'][$ascClass]] = $gname;
            }
            $ppw = max(1, (int) round((float) $a($l, 'periodsperweek')));
            $len = max(1, (int) $a($l, 'periodspercard'));
            $dbl = $len >= 2 ? intdiv($ppw, 2) : 0;
            $joint = count($cls) > 1 ? 'asc-' . trim($a($l, 'id'), '*{}') : '';
            foreach ($cls as $cid) {
                upsert_curriculum($pdo, $cid, $sid, $tid, $rooms[0] ?? null, $grpOf[$cid] ?? '', $ppw, $dbl, $joint);
            }
            if ($tid) $load[$tid] = ($load[$tid] ?? 0) + $ppw;
            $lessons[$a($l, 'id')] = compact('sid', 'cls', 'tid', 'rooms', 'grpOf', 'len', 'joint');
        }
        // میلاکی مامۆستا بەپێی وانەکانی aSc
        $st = $pdo->prepare("UPDATE teachers SET max_periods = ? WHERE id = ?");
        foreach ($load as $tid => $n) $st->execute([max(22, $n), $tid]);

        $placed = 0;
        if ($withCards) {
            $P = cfg_periods($pdo);
            $ins = $pdo->prepare("INSERT INTO timetable (class_id, teacher_id, subject_id, room_id, group_name, joint_group,
                                  day_of_week, period_no, locked) VALUES (?,?,?,?,?,?,?,?,1)");
            foreach ($xml->cards->card ?? [] as $card) {
                $l = $lessons[$a($card, 'lessonid')] ?? null;
                $bits = $a($card, 'days');
                if (!$l || !$l['tid'] || substr_count($bits, '1') !== 1) continue;
                $d = strpos($bits, '1');
                $p = (int) $a($card, 'period');
                $room = $map['r'][$ids($a($card, 'classroomids'))[0] ?? ''] ?? ($l['rooms'][0] ?? null);
                for ($k = 0; $k < $l['len'] && $p + $k <= $P; $k++) {
                    foreach ($l['cls'] as $cid) {
                        $ins->execute([$cid, $l['tid'], $l['sid'], $room, $l['grpOf'][$cid] ?? '', $l['joint'], $d, $p + $k]);
                        $placed++;
                    }
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $count = fn($t) => (int) q1($pdo, "SELECT COUNT(*) c FROM $t")['c'];
    return [
        'teachers' => $count('teachers'), 'subjects' => $count('subjects'), 'classes' => $count('classes'),
        'rooms' => $count('rooms'), 'curriculum' => $count('class_subjects'), 'lessons' => $placed,
    ];
}

const CSV_HEADERS = ['پۆل', 'بابەت', 'مامۆستا', 'وانەی هەفتانە', 'دووانی', 'ژوور', 'گرووپ'];

function csv_template() {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="curriculum-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, CSV_HEADERS);
    fputcsv($out, ['٧/أ', 'بیرکاری', 'ئاری محەمەد', 5, 1, '', '']);
    fputcsv($out, ['٧/أ', 'وەرزش', 'سەردار عەلی', 1, 0, 'هۆڵی وەرزش', 'کوڕان']);
    fclose($out);
    exit;
}

/**
 * هێنانی پرۆگرامی خوێندن لە Excel (پاشەکەوت وەک CSV UTF-8).
 * ستوونەکان: پۆل، بابەت، مامۆستا، وانەی هەفتانە، دووانی، ژوور، گرووپ
 * پۆل/بابەت/مامۆستا/ژوور ی نوێ خۆکارانە دروست دەکرێن. داتای ئێستا ناسڕێتەوە.
 */
function import_curriculum_csv($pdo, $upload) {
    $text = file_get_contents(uploaded_file($upload));
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!preg_match('//u', $text)) {
        throw new RuntimeException('فایلەکە UTF-8 نییە. لە Excel: Save As ← «CSV UTF-8 (Comma delimited)».');
    }
    $first = strtok($text, "\n");
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $lines = array_map(fn($l) => str_getcsv($l, $delim), preg_split('/\r\n|\n|\r/', trim($text)));
    if (isset($lines[0][0]) && trim($lines[0][0]) === CSV_HEADERS[0]) array_shift($lines);

    backup_create($pdo, 'before-import');
    $added = $updated = 0; $errors = [];
    $pdo->beginTransaction();
    try {
        $stTS = $pdo->prepare("INSERT OR IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
        foreach ($lines as $i => $row) {
            $row = array_map('trim', array_pad($row, 7, ''));
            if (implode('', $row) === '') continue;
            [$cls, $subj, $teacher, $ppw, $dbl, $room, $grp] = $row;
            $ppw = (int) strtr($ppw, '٠١٢٣٤٥٦٧٨٩', '0123456789');
            $dbl = (int) strtr($dbl, '٠١٢٣٤٥٦٧٨٩', '0123456789');
            if ($cls === '' || $subj === '' || $ppw < 1) { $errors[] = 'ڕیزی ' . ($i + 2) . ': پۆل، بابەت یان ژمارەی وانە نییە.'; continue; }
            $cid = id_by_name($pdo, 'classes', 'name', $cls);
            $sid = id_by_name($pdo, 'subjects', 'name', $subj);
            $tid = id_by_name($pdo, 'teachers', 'full_name', $teacher);
            $rid = id_by_name($pdo, 'rooms', 'name', $room);
            if ($tid) $stTS->execute([$tid, $sid]);
            $ex = q1($pdo, "SELECT id FROM class_subjects WHERE class_id = ? AND subject_id = ? AND group_name = ?", [$cid, $sid, $grp]);
            if ($ex) {
                $pdo->prepare("UPDATE class_subjects SET teacher_id = ?, room_id = ?, periods_per_week = ?, double_count = ? WHERE id = ?")
                    ->execute([$tid, $rid, $ppw, min($dbl, intdiv($ppw, 2)), $ex['id']]);
                $updated++;
            } else {
                upsert_curriculum($pdo, $cid, $sid, $tid, $rid, $grp, $ppw, min($dbl, intdiv($ppw, 2)));
                $added++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['added' => $added, 'updated' => $updated, 'errors' => array_slice($errors, 0, 20)];
}

// ================= مامۆستای جێگرەوە =================

/** ڕێکەوت → ژمارەی ڕۆژ لە خشتەدا (بەپێی ناوی ڕۆژەکان). */
function date_to_day($pdo, $date) {
    $ts = strtotime($date);
    if (!$ts || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('ڕێکەوت هەڵەیە.');
    $weekday = (int) date('w', $ts); // 0 = یەکشەممە
    foreach (cfg_days($pdo) as $i => $name) {
        $pos = array_search($name, DEFAULT_DAYS, true);
        if (($pos === false ? $i : $pos) === $weekday) return $i;
    }
    throw new RuntimeException('ئەم ڕێکەوتە ڕۆژی دەوام نییە.');
}

function substitution_day($pdo, $date, $absentId) {
    $d = date_to_day($pdo, $date);
    $rows = q($pdo, "SELECT t.period_no, t.class_id, t.subject_id, t.group_name, t.joint_group, c.name class_name, s.name subject_name
                     FROM timetable t JOIN classes c ON c.id = t.class_id JOIN subjects s ON s.id = t.subject_id
                     WHERE t.teacher_id = ? AND t.day_of_week = ? ORDER BY t.period_no, c.name", [$absentId, $d]);
    // وانەی هاوبەش (چەند پۆل لە یەک کاتدا) یەک جێگرەوەی دەبێت
    $lessons = [];
    foreach ($rows as $r) {
        $k = $r['joint_group'] !== '' ? "J|{$r['period_no']}|{$r['joint_group']}" : "R|{$r['period_no']}|{$r['class_id']}";
        if (isset($lessons[$k])) {
            $lessons[$k]['class_ids'][] = (int) $r['class_id'];
            $lessons[$k]['class_name'] .= '، ' . $r['class_name'];
        } else {
            $lessons[$k] = $r + ['class_ids' => [(int) $r['class_id']]];
        }
    }
    $lessons = array_values($lessons);
    $existing = [];
    foreach (q($pdo, "SELECT * FROM substitutions WHERE sub_date = ? AND absent_id = ?", [$date, $absentId]) as $s) {
        $existing["{$s['period_no']}|{$s['class_id']}"] = $s;
    }
    // ئەو مامۆستایانەی ئەمڕۆ وەک جێگرەوە دانراون، لەو کاتەدا سەرقاڵن
    $subBusy = []; $subCount = [];
    foreach (q($pdo, "SELECT substitute_id, period_no FROM substitutions WHERE sub_date = ? AND substitute_id IS NOT NULL", [$date]) as $s) {
        $subBusy[$s['substitute_id']][$s['period_no']] = true;
        $subCount[$s['substitute_id']] = ($subCount[$s['substitute_id']] ?? 0) + 1;
    }
    $teachers = q($pdo, "SELECT id, full_name FROM teachers WHERE id <> ? ORDER BY full_name", [$absentId]);
    $busy = []; $dayLoad = [];
    foreach (q($pdo, "SELECT DISTINCT teacher_id, period_no FROM timetable WHERE day_of_week = ?", [$d]) as $r) {
        $busy[$r['teacher_id']][$r['period_no']] = true;
        $dayLoad[$r['teacher_id']] = ($dayLoad[$r['teacher_id']] ?? 0) + 1;
    }
    $off = [];
    foreach (q($pdo, "SELECT teacher_id, period_no FROM teacher_offdays WHERE day_of_week = ?", [$d]) as $o) {
        $off[$o['teacher_id']][$o['period_no'] ?? 'all'] = true;
    }
    $qual = [];
    foreach (q($pdo, "SELECT teacher_id, subject_id FROM teacher_subjects") as $r) $qual[$r['teacher_id']][$r['subject_id']] = true;

    foreach ($lessons as &$l) {
        $p = (int) $l['period_no'];
        $ex = $existing["{$p}|{$l['class_id']}"] ?? null;
        $cands = [];
        foreach ($teachers as $t) {
            $id = (int) $t['id'];
            $mine = $ex && (int) $ex['substitute_id'] === $id;
            if (!$mine && (isset($busy[$id][$p]) || isset($subBusy[$id][$p]) || isset($off[$id]['all']) || isset($off[$id][$p]))) continue;
            $cands[] = [
                'id' => $id, 'name' => $t['full_name'],
                'same_subject' => isset($qual[$id][$l['subject_id']]),
                'today' => ($dayLoad[$id] ?? 0) + ($subCount[$id] ?? 0),
            ];
        }
        // مامۆستای هەمان بابەت یەکەم، پاشان ئەوانەی ئەمڕۆ کەمتر سەرقاڵن
        usort($cands, fn($a, $b) => [$b['same_subject'], $a['today']] <=> [$a['same_subject'], $b['today']]);
        $l['candidates'] = $cands;
        $l['substitution'] = $ex;
    }
    return ['day' => $d, 'day_name' => cfg_days($pdo)[$d], 'lessons' => $lessons];
}

function substitution_save($pdo, $b) {
    $date = $b['date'] ?? '';
    $d = date_to_day($pdo, $date);
    $sub = !empty($b['substitute_id']) ? (int) $b['substitute_id'] : null;
    $classes = array_map('intval', (array) ($b['class_ids'] ?? [$b['class_id'] ?? 0]));
    foreach ($classes as $cid) $pdo->prepare("INSERT INTO substitutions (sub_date, day_of_week, period_no, class_id, subject_id, absent_id, substitute_id, note)
                   VALUES (?,?,?,?,?,?,?,?)
                   ON CONFLICT(sub_date, period_no, class_id, absent_id)
                   DO UPDATE SET substitute_id = excluded.substitute_id, note = excluded.note")
        ->execute([$date, $d, (int) $b['period_no'], $cid, (int) $b['subject_id'],
                   (int) $b['absent_id'], $sub, trim($b['note'] ?? '')]);
}

function substitution_list($pdo, $date) {
    return q($pdo, "SELECT x.*, c.name class_name, s.name subject_name, a.full_name absent_name, t.full_name substitute_name
                    FROM substitutions x JOIN classes c ON c.id = x.class_id JOIN subjects s ON s.id = x.subject_id
                    JOIN teachers a ON a.id = x.absent_id LEFT JOIN teachers t ON t.id = x.substitute_id
                    WHERE x.sub_date = ? ORDER BY x.period_no, c.name", [$date]);
}

// ================= بڵاوکردنەوە بۆ مۆبایل =================

/** فایلێکی HTML ی سەربەخۆ کە لە هەر مۆبایلێکدا بەبێ ئینتەرنێت دەکرێتەوە. */
function publish_html($pdo) {
    $cfg = cfg_all($pdo);
    $data = [
        'school'  => $cfg['school_name'],
        'days'    => $cfg['days'],
        'periods' => $cfg['periods'],
        'times'   => $cfg['period_times'],
        'classes' => q($pdo, "SELECT id, name FROM classes ORDER BY grade_level, name"),
        'teachers'=> q($pdo, "SELECT id, full_name AS name FROM teachers ORDER BY full_name"),
        'lessons' => q($pdo, "SELECT t.class_id c, t.teacher_id t, t.day_of_week d, t.period_no p, t.group_name g,
                              s.name s, te.full_name tn, cl.name cn, r.name r
                              FROM timetable t JOIN subjects s ON s.id = t.subject_id JOIN teachers te ON te.id = t.teacher_id
                              JOIN classes cl ON cl.id = t.class_id LEFT JOIN rooms r ON r.id = t.room_id"),
        'updated' => date('Y-m-d H:i'),
    ];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $title = htmlspecialchars(trim(($cfg['school_name'] ?: '') . ' — خشتەی هەفتانە', ' —'));
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="timetable-mobile.html"');
    echo <<<HTML
<!DOCTYPE html><html lang="ckb" dir="rtl"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>{$title}</title>
<style>
:root{--p:#1B5E20;--bg:#F4F6F4;--line:#D7DDD7;--muted:#6B776B}
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Tahoma,"Noto Sans Arabic",sans-serif;background:var(--bg);color:#212121}
header{background:var(--p);color:#fff;padding:14px 16px}h1{font-size:18px;margin:0 0 4px}small{opacity:.8}
.bar{display:flex;gap:8px;padding:12px 16px;flex-wrap:wrap}.bar button{flex:1;padding:10px;border:1px solid var(--line);border-radius:8px;background:#fff;font:inherit}
.bar button.on{background:var(--p);color:#fff;border-color:var(--p)}select{width:100%;padding:10px;font:inherit;border:1px solid var(--line);border-radius:8px;background:#fff}
main{padding:0 16px 24px}.day{background:#fff;border:1px solid var(--line);border-radius:10px;margin-top:12px;overflow:hidden}
.day h2{margin:0;padding:8px 12px;background:#EAF0EA;color:var(--p);font-size:15px}.row{display:flex;gap:10px;padding:8px 12px;border-top:1px solid var(--line)}
.row .n{width:46px;color:var(--p);font-weight:700;text-align:center}.row .n small{display:block;font-weight:400;color:var(--muted);font-size:10px;direction:ltr}
.row .x b{display:block}.row .x span{color:var(--muted);font-size:13px}.empty{color:#B0BCB0}
</style></head><body>
<header><h1>{$title}</h1><small id="upd"></small></header>
<div class="bar"><button id="bc" class="on">پۆل</button><button id="bt">مامۆستا</button><select id="sel"></select></div>
<main id="out"></main>
<script>
const D={$json};
let view='c';
const e=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
document.getElementById('upd').textContent='نوێکراوەتەوە: '+D.updated;
function fill(){const l=view==='c'?D.classes:D.teachers;sel.innerHTML=l.map(x=>'<option value="'+x.id+'">'+e(x.name)+'</option>').join('');
 try{const s=localStorage.getItem('tt_'+view);if(s&&l.some(x=>String(x.id)===s))sel.value=s;}catch(_){ }draw();}
function draw(){const id=+sel.value;try{localStorage.setItem('tt_'+view,id);}catch(_){ }
 const ls=D.lessons.filter(x=>(view==='c'?x.c:x.t)===id);let h='';
 D.days.forEach((dn,d)=>{h+='<div class="day"><h2>'+e(dn)+'</h2>';for(let p=1;p<=D.periods;p++){
  const c=ls.filter(x=>x.d===d&&x.p===p);const t=D.times[p-1]?'<small>'+e(D.times[p-1])+'</small>':'';
  h+='<div class="row"><div class="n">'+p+t+'</div><div class="x">'+(c.length?c.map(x=>'<b>'+e(x.s)+(x.g?' ('+e(x.g)+')':'')+'</b><span>'+e(view==='c'?x.tn:x.cn)+(x.r?' — '+e(x.r):'')+'</span>').join(''):'<span class="empty">—</span>')+'</div></div>';}
  h+='</div>';});out.innerHTML=h;}
bc.onclick=()=>{view='c';bc.className='on';bt.className='';fill();};
bt.onclick=()=>{view='t';bt.className='on';bc.className='';fill();};
sel.onchange=draw;fill();
</script></body></html>
HTML;
    exit;
}
