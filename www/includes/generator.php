<?php
// ============================================================
// دروستکەری ئۆتۆماتیکی خشتە
//
// قۆناغی ١ (دروستکردن): هەر وانەیەکی پێویست دەبێتە «یەکە»یەک (تاک، دووانی،
//   یان هاوبەش لە نێوان چەند پۆلێکدا). یەکە قورسەکان یەکەم دادەنرێن و هەر
//   یەکەیەک لە ئەو خانەیەدا دادەنرێت کە کەمترین «سزا»ی هەیە. ئەگەر جێی
//   نەبووەوە، یەکەیەکی ڕێگر دەگوازرێتەوە. چەند جار دووبارە دەکرێتەوە.
// قۆناغی ٢ (باشترکردن): بە گواستنەوە و ئاڵوگۆڕی هەڕەمەکی (simulated annealing)
//   سزای ڕێگرییە نەرمەکان کەم دەکرێتەوە: بۆشایی مامۆستا و پۆل، کاتی بابەت،
//   پەیوەندی بابەتەکان، کەمترین وانەی ڕۆژانە.
//
// ڕێگرییە سەختەکان هەرگیز ناشکێنرێن: تێکهەڵچوونی پۆل/مامۆستا/ژوور، کاتی بەتاڵ،
// میلاکی هەفتانە و ڕۆژانە، زۆرترین وانەی بەدوای یەکدا، یەک بابەت لە ڕۆژێکدا.
// ============================================================
require_once __DIR__ . '/logic.php';

class TimetableGenerator {
    private $D, $P, $cons;
    public  $units = [];      // هەر یەکەیەک: cls[], grp[cls], subj, t, room, len, key, fixed, rows[]
    private $tMax = [], $tMaxDay = [], $tMinDay = [], $tMaxGaps = [];
    private $off = [];        // off[t][d][p] = true
    private $dayCap = [];     // key => زۆرترین جار لە ڕۆژێکدا
    private $timePref = [];   // subject => any|early|late
    private $notSame = [];    // notSame[a][b] = true (هەردوو ئاڕاستە)
    private $consec = [];     // consec[a][b] = true: a دەبێت لە تەنیشت b بێت
    private $names = [];

    // دۆخی ئێستا
    public  $place = [];
    private $classOcc, $teacherOcc, $roomOcc, $dayCount, $tLoad, $tDay;

    public $skipped = [];

    public function __construct($pdo, $regenerate, $withCurriculum = true) {
        $this->D = count(cfg_days($pdo));
        $this->P = cfg_periods($pdo);
        $this->cons = cfg_constraints($pdo);

        foreach (q($pdo, "SELECT * FROM teachers") as $t) {
            $id = (int) $t['id'];
            $this->tMax[$id] = (int) $t['max_periods'];
            $this->tMaxDay[$id] = $t['max_per_day'] ? (int) $t['max_per_day'] : 0;
            $this->tMinDay[$id] = $t['min_per_day'] ? (int) $t['min_per_day'] : 0;
            $this->tMaxGaps[$id] = $t['max_gaps'] !== null ? (int) $t['max_gaps'] : $this->cons['max_teacher_gaps'];
            $this->names['t'][$id] = $t['full_name'];
        }
        foreach (q($pdo, "SELECT id, name, time_pref FROM subjects") as $s) {
            $this->timePref[(int) $s['id']] = $s['time_pref'];
            $this->names['s'][(int) $s['id']] = $s['name'];
        }
        foreach (q($pdo, "SELECT id, name FROM classes") as $c) $this->names['c'][(int) $c['id']] = $c['name'];
        foreach (q($pdo, "SELECT id, name FROM rooms") as $r)   $this->names['r'][(int) $r['id']] = $r['name'];
        foreach (q($pdo, "SELECT * FROM teacher_offdays") as $o) {
            $ps = $o['period_no'] === null ? range(1, $this->P) : [(int) $o['period_no']];
            foreach ($ps as $p) $this->off[(int) $o['teacher_id']][(int) $o['day_of_week']][$p] = true;
        }
        foreach (q($pdo, "SELECT * FROM subject_relations") as $r) {
            [$a, $b] = [(int) $r['subject_a'], (int) $r['subject_b']];
            if ($r['kind'] === 'not_same_day') $this->notSame[$a][$b] = $this->notSame[$b][$a] = true;
            if ($r['kind'] === 'consecutive')  $this->consec[$a][$b] = $this->consec[$b][$a] = true;
        }

        // ---- وانە جێگیرەکان (قوفڵکراو، یان هەمووی ئەگەر دووبارە دروستکردن نەبێت) ----
        $rows = q($pdo, "SELECT * FROM timetable WHERE day_of_week < ? AND period_no <= ?"
                      . ($regenerate ? " AND locked = 1" : "") . " ORDER BY id", [$this->D, $this->P]);
        $have = [];
        $bySlot = [];
        foreach ($rows as $r) {
            $k = $r['joint_group'] !== ''
                ? "J|{$r['joint_group']}|{$r['teacher_id']}|{$r['day_of_week']}|{$r['period_no']}"
                : "R|{$r['id']}";
            $bySlot[$k][] = $r;
        }
        foreach ($bySlot as $group) {
            $f = $group[0];
            $key = $f['joint_group'] !== '' ? "J|{$f['joint_group']}" : "{$f['class_id']}|{$f['subject_id']}|{$f['group_name']}";
            $have[$key] = ($have[$key] ?? 0) + 1;
            $grp = [];
            foreach ($group as $g) $grp[(int) $g['class_id']] = $g['group_name'];
            $this->units[] = [
                'cls' => array_keys($grp), 'grp' => $grp, 'subj' => (int) $f['subject_id'],
                't' => (int) $f['teacher_id'], 'room' => $f['room_id'] ? (int) $f['room_id'] : 0,
                'len' => 1, 'key' => $key, 'fixed' => true, 'rows' => array_column($group, 'id'),
                'at' => [(int) $f['day_of_week'], (int) $f['period_no']],
            ];
        }

        if ($withCurriculum) $this->buildCurriculumUnits($pdo, $have);

        $this->resetState();
        foreach ($this->units as $uid => $u) {
            if ($u['fixed']) $this->put($uid, ...$u['at']);
        }
        if ($withCurriculum) $this->diagnose();
    }

    /** یەکەکانی پرۆگرامی خوێندن؛ وانە هاوبەشەکان (هەمان joint_group) یەک یەکە دەبن. */
    private function buildCurriculumUnits($pdo, $have) {
        $entries = [];
        foreach (curriculum($pdo) as $c) {
            $k = $c['joint_group'] !== '' ? "J|{$c['joint_group']}" : "{$c['class_id']}|{$c['subject_id']}|{$c['group_name']}";
            if (!isset($entries[$k])) {
                $entries[$k] = $c + ['grp' => []];
            } elseif (!$entries[$k]['teacher_id'] && $c['teacher_id']) {
                $entries[$k]['teacher_id'] = $c['teacher_id'];
            }
            $entries[$k]['grp'][(int) $c['class_id']] = $c['group_name'];
            if (!$entries[$k]['room_id'] && $c['room_id']) $entries[$k]['room_id'] = $c['room_id'];
        }
        foreach ($entries as $key => $c) {
            $label = $this->label($c['subject_id'], array_keys($c['grp']), $c['group_name']);
            $need = (int) $c['periods_per_week'] - ($have[$key] ?? 0);
            if ($need <= 0) continue;
            if (!$c['teacher_id']) { $this->skipped[] = "$label: مامۆستا دیاری نەکراوە."; continue; }
            $total = (int) $c['periods_per_week'];
            $doubles = min((int) $c['double_count'], intdiv($need, 2));
            $occurrences = $total - min((int) $c['double_count'], intdiv($total, 2));
            $this->dayCap[$key] = max(1, (int) ceil($occurrences / max(1, $this->D)));
            $u = [
                'cls' => array_keys($c['grp']), 'grp' => $c['grp'], 'subj' => (int) $c['subject_id'],
                't' => (int) $c['teacher_id'], 'room' => $c['room_id'] ? (int) $c['room_id'] : 0,
                'len' => 1, 'key' => $key, 'fixed' => false, 'rows' => [], 'joint' => $c['joint_group'],
            ];
            for ($i = 0; $i < $doubles; $i++) $this->units[] = ['len' => 2] + $u;
            for ($i = 0; $i < $need - 2 * $doubles; $i++) $this->units[] = $u;
        }
    }

    private function label($subj, $classes, $grp = '') {
        $cls = implode('، ', array_map(fn($c) => $this->names['c'][$c] ?? '?', $classes));
        return "«" . ($this->names['s'][$subj] ?? '?') . "» ی پۆلی «{$cls}»" . ($grp !== '' ? " (گرووپی $grp)" : '');
    }

    /** پشکنینی پێشوەختە: ئەو حاڵەتانەی کە بە هیچ شێوەیەک جێیان نابێتەوە. */
    private function diagnose() {
        $slots = $this->D * $this->P;
        $tDemand = []; $rDemand = []; $cDemand = [];
        foreach ($this->units as $u) {
            $tDemand[$u['t']] = ($tDemand[$u['t']] ?? 0) + $u['len'];
            if ($u['room']) $rDemand[$u['room']] = ($rDemand[$u['room']] ?? 0) + $u['len'];
            foreach ($u['grp'] as $c => $g) if ($g === '') $cDemand[$c] = ($cDemand[$c] ?? 0) + $u['len'];
        }
        foreach ($tDemand as $t => $n) {
            $free = $slots;
            foreach ($this->off[$t] ?? [] as $ps) $free -= count($ps);
            $name = $this->names['t'][$t] ?? '?';
            if ($n > $this->tMax[$t]) $this->skipped[] = "مامۆستا $name: {$n} وانەی پێویستە بەڵام میلاکی {$this->tMax[$t]}ـە.";
            if ($n > $free) $this->skipped[] = "مامۆستا $name: {$n} وانەی پێویستە بەڵام تەنها {$free} کاتی بەردەستی هەیە.";
            if ($this->tMaxDay[$t] && $n > $this->tMaxDay[$t] * $this->D) {
                $this->skipped[] = "مامۆستا $name: {$n} وانەی پێویستە بەڵام سنووری ڕۆژانەی تەنها ڕێگە بە " . ($this->tMaxDay[$t] * $this->D) . " دەدات.";
            }
        }
        foreach ($rDemand as $r => $n) {
            if ($n > $slots) $this->skipped[] = "ژووری «" . ($this->names['r'][$r] ?? '?') . "»: {$n} وانەی پێویستە بەڵام هەفتە تەنها {$slots} کاتی هەیە.";
        }
        foreach ($cDemand as $c => $n) {
            if ($n > $slots) $this->skipped[] = "پۆلی «" . ($this->names['c'][$c] ?? '?') . "»: {$n} وانەی پێویستە بەڵام هەفتە تەنها {$slots} کاتی هەیە.";
        }
    }

    // ================= دۆخ =================
    private function resetState() {
        $this->place = array_fill(0, count($this->units), null);
        $this->classOcc = $this->teacherOcc = $this->roomOcc = $this->dayCount = $this->tLoad = $this->tDay = [];
    }

    private function put($uid, $d, $p) {
        $u = $this->units[$uid];
        for ($k = 0; $k < $u['len']; $k++) {
            $pp = $p + $k;
            foreach ($u['grp'] as $c => $g) $this->classOcc[$c][$d][$pp][$g] = $uid;
            $this->teacherOcc[$u['t']][$d][$pp] = $uid;
            if ($u['room']) $this->roomOcc[$u['room']][$d][$pp] = $uid;
        }
        $this->dayCount[$u['key']][$d] = ($this->dayCount[$u['key']][$d] ?? 0) + 1;
        $this->tLoad[$u['t']] = ($this->tLoad[$u['t']] ?? 0) + $u['len'];
        $this->tDay[$u['t']][$d] = ($this->tDay[$u['t']][$d] ?? 0) + $u['len'];
        $this->place[$uid] = [$d, $p];
    }

    private function release($uid) {
        $u = $this->units[$uid];
        [$d, $p] = $this->place[$uid];
        for ($k = 0; $k < $u['len']; $k++) {
            $pp = $p + $k;
            foreach ($u['grp'] as $c => $g) {
                unset($this->classOcc[$c][$d][$pp][$g]);
                if (empty($this->classOcc[$c][$d][$pp])) unset($this->classOcc[$c][$d][$pp]);
            }
            unset($this->teacherOcc[$u['t']][$d][$pp]);
            if ($u['room']) unset($this->roomOcc[$u['room']][$d][$pp]);
        }
        $this->dayCount[$u['key']][$d]--;
        $this->tLoad[$u['t']] -= $u['len'];
        $this->tDay[$u['t']][$d] -= $u['len'];
        $this->place[$uid] = null;
    }

    private function snapshot() { return $this->place; }
    private function restore($place) {
        $this->resetState();
        foreach ($place as $uid => $at) if ($at) $this->put($uid, ...$at);
    }

    // ================= ڕێگرییە سەختەکان =================
    /**
     * ئەو یەکانەی ڕێگرن لە دانانی $uid لە (d,p).
     * [] = بەتاڵە، null = بە هیچ شێوەیەک ناکرێت.
     */
    private function blockers($uid, $d, $p) {
        $u = $this->units[$uid];
        $t = $u['t']; $len = $u['len'];
        if ($p + $len - 1 > $this->P) return null;
        if (isset($this->dayCap[$u['key']]) && ($this->dayCount[$u['key']][$d] ?? 0) >= $this->dayCap[$u['key']]) return null;
        if (($this->tLoad[$t] ?? 0) + $len > ($this->tMax[$t] ?? 0)) return null;
        if ($this->tMaxDay[$t] && ($this->tDay[$t][$d] ?? 0) + $len > $this->tMaxDay[$t]) return null;
        $b = [];
        for ($k = 0; $k < $len; $k++) {
            $pp = $p + $k;
            if (!empty($this->off[$t][$d][$pp])) return null;
            foreach ($u['grp'] as $c => $grp) {
                foreach ($this->classOcc[$c][$d][$pp] ?? [] as $g => $other) {
                    if (groups_compatible($grp, (string) $g)) continue;
                    if ($this->units[$other]['fixed']) return null;
                    $b[$other] = true;
                }
            }
            foreach ([$this->teacherOcc[$t][$d][$pp] ?? null, $u['room'] ? ($this->roomOcc[$u['room']][$d][$pp] ?? null) : null] as $other) {
                if ($other === null) continue;
                if ($this->units[$other]['fixed']) return null;
                $b[$other] = true;
            }
        }
        // زۆرترین وانەی بەدوای یەکدا
        if ($max = $this->cons['max_consecutive']) {
            $occ = $this->teacherOcc[$t][$d] ?? [];
            $lo = $p; while (isset($occ[$lo - 1])) $lo--;
            $hi = $p + $len - 1; while (isset($occ[$hi + 1])) $hi++;
            if ($hi - $lo + 1 > $max) return null;
        }
        return array_keys($b);
    }

    private function freeSlots($uid) {
        $out = [];
        for ($d = 0; $d < $this->D; $d++)
            for ($p = 1; $p <= $this->P; $p++)
                if ($this->blockers($uid, $d, $p) === []) $out[] = [$d, $p];
        return $out;
    }

    // ================= ڕێگرییە نەرمەکان (سزا) =================
    private function teacherDayPen($t, $d) {
        $occ = $this->teacherOcc[$t][$d] ?? [];
        $n = count($occ);
        if (!$n) return 0;
        $ps = array_keys($occ);
        $gaps = max($ps) - min($ps) + 1 - $n;
        $pen = $this->cons['w_teacher_gaps'] * (max(0, $gaps - $this->tMaxGaps[$t]) * 20 + $gaps * 2);
        if ($this->tMinDay[$t] && $n < $this->tMinDay[$t]) $pen += $this->cons['w_min_per_day'] * ($this->tMinDay[$t] - $n) * 10;
        return $pen;
    }

    private function classDayPen($c, $d) {
        $occ = $this->classOcc[$c][$d] ?? [];
        if (!$occ) return 0;
        $ps = array_keys($occ);
        $lo = min($ps); $hi = max($ps);
        // پۆل دەبێت بێ بۆشایی بێت و لە بەشە وانەی یەکەمەوە دەست پێبکات
        $pen = $this->cons['w_class_gaps'] * (($hi - $lo + 1 - count($occ)) * 20 + ($lo - 1) * 4);
        if ($this->cons['w_relations'] && ($this->notSame || $this->consec)) {
            $subjAt = [];
            foreach ($occ as $p => $gs) foreach ($gs as $uid) $subjAt[$p][$this->units[$uid]['subj']] = true;
            $present = [];
            foreach ($subjAt as $ss) $present += $ss;
            foreach ($present as $a => $_) {
                foreach ($this->notSame[$a] ?? [] as $b => $_2) {
                    if ($a < $b && isset($present[$b])) $pen += $this->cons['w_relations'] * 30;
                }
            }
            foreach ($subjAt as $p => $ss) {
                foreach ($ss as $a => $_) {
                    if (empty($this->consec[$a])) continue;
                    $ok = false;
                    foreach ($this->consec[$a] as $b => $_2) {
                        if (isset($subjAt[$p - 1][$b]) || isset($subjAt[$p + 1][$b])) { $ok = true; break; }
                    }
                    if (!$ok) $pen += $this->cons['w_relations'] * 8;
                }
            }
        }
        return $pen;
    }

    private function unitPen($uid) {
        if (!$this->place[$uid]) return $this->units[$uid]['fixed'] ? 0 : 1000;
        $pref = $this->timePref[$this->units[$uid]['subj']] ?? 'any';
        if ($pref === 'any' || !$this->cons['w_subject_time']) return 0;
        [, $p] = $this->place[$uid];
        $end = $p + $this->units[$uid]['len'] - 1;
        $half = (int) ceil($this->P / 2);
        $miss = $pref === 'early' ? max(0, $end - $half) : max(0, ($this->P - $half + 1) - $p);
        return $this->cons['w_subject_time'] * $miss * 6;
    }

    /** سزای ئەو بەشانەی کە ئەم یەکانە و ئەم ڕۆژانە کاریان تێدەکەن. */
    private function localPen($uids, $days) {
        $tSeen = []; $cSeen = []; $pen = 0;
        foreach ($uids as $uid) {
            $u = $this->units[$uid];
            $pen += $this->unitPen($uid);
            foreach ($days as $d) {
                if (!isset($tSeen[$u['t']][$d])) { $tSeen[$u['t']][$d] = 1; $pen += $this->teacherDayPen($u['t'], $d); }
                foreach ($u['cls'] as $c) {
                    if (!isset($cSeen[$c][$d])) { $cSeen[$c][$d] = 1; $pen += $this->classDayPen($c, $d); }
                }
            }
        }
        return $pen;
    }

    public function totalPen() {
        $pen = 0;
        for ($d = 0; $d < $this->D; $d++) {
            foreach (array_keys($this->teacherOcc) as $t) $pen += $this->teacherDayPen($t, $d);
            foreach (array_keys($this->classOcc) as $c) $pen += $this->classDayPen($c, $d);
        }
        foreach ($this->units as $uid => $_) $pen += $this->unitPen($uid);
        return $pen;
    }

    // ================= قۆناغی ١: دروستکردن =================
    /** باشترین خانە: کەمترین سزا + بڵاوبوونەوەی بابەت بەسەر هەفتەدا + کەمێک هەڕەمەکی. */
    private function best($uid, $slots) {
        $u = $this->units[$uid];
        $best = null; $bestScore = PHP_INT_MAX;
        $daysBefore = [];
        foreach ($slots as [$d, $p]) {
            if (!isset($daysBefore[$d])) $daysBefore[$d] = $this->localPen([$uid], [$d]);
            $this->put($uid, $d, $p);
            $delta = $this->localPen([$uid], [$d]) - $daysBefore[$d];
            $this->release($uid);
            $score = $delta * 10 + ($this->dayCount[$u['key']][$d] ?? 0) * 150 + mt_rand(0, 40);
            // گرووپەکان هاوکات لەگەڵ گرووپەکانی تری هەمان پۆل
            foreach ($u['grp'] as $c => $g) if ($g !== '' && !empty($this->classOcc[$c][$d][$p])) $score -= 2000;
            if ($score < $bestScore) { $bestScore = $score; $best = [$d, $p]; }
        }
        return $best;
    }

    private function tryPlace($uid) {
        $free = $this->freeSlots($uid);
        if ($free) { $this->put($uid, ...$this->best($uid, $free)); return true; }

        $options = [];
        for ($d = 0; $d < $this->D; $d++) {
            for ($p = 1; $p <= $this->P; $p++) {
                $b = $this->blockers($uid, $d, $p);
                if ($b && count($b) === 1) $options[] = [$d, $p, $b[0]];
            }
        }
        shuffle($options);
        foreach (array_slice($options, 0, 12) as [$d, $p, $other]) {
            $old = $this->place[$other];
            $this->release($other);
            if ($this->blockers($uid, $d, $p) !== []) { $this->put($other, ...$old); continue; }
            $this->put($uid, $d, $p);
            $alt = $this->freeSlots($other);
            if ($alt) { $this->put($other, ...$this->best($other, $alt)); return true; }
            $this->release($uid);
            $this->put($other, ...$old);
        }
        return false;
    }

    private function difficulty($uid) {
        $u = $this->units[$uid];
        $n = 0;
        for ($d = 0; $d < $this->D; $d++)
            for ($p = 1; $p <= $this->P - $u['len'] + 1; $p++)
                if (empty($this->off[$u['t']][$d][$p])) $n++;
        return $n / $u['len'] - count($u['cls']) * 3;
    }

    // ================= قۆناغی ٢: باشترکردن =================
    private function optimize($deadline, $movable) {
        if (!$movable) return;
        $byClass = [];
        foreach ($movable as $uid) foreach ($this->units[$uid]['cls'] as $c) $byClass[$c][] = $uid;
        $cur = $this->totalPen();
        $best = $cur; $bestPlace = $this->snapshot();
        $T0 = 8.0; $start = microtime(true); $span = max(0.01, $deadline - $start);
        $iter = 0;
        while (true) {
            if (($iter++ & 63) === 0) {
                $now = microtime(true);
                if ($now >= $deadline) break;
                $T = max(0.05, $T0 * (1 - ($now - $start) / $span));
                // جارجارە هەوڵی دانانی یەکە جێنەکراوەکان
                foreach ($movable as $uid) if (!$this->place[$uid] && mt_rand(0, 3) === 0 && $this->tryPlace($uid)) $cur = $this->totalPen();
            }
            $u = $movable[array_rand($movable)];
            if (!$this->place[$u]) continue;
            [$d1] = $this->place[$u];

            if (mt_rand(0, 1)) {
                // گواستنەوە بۆ خانەیەکی بەتاڵ
                $d = mt_rand(0, $this->D - 1); $p = mt_rand(1, $this->P);
                $old = $this->place[$u];
                if ($old === [$d, $p]) continue;
                $days = array_unique([$d1, $d]);
                $before = $this->localPen([$u], $days);
                $this->release($u);
                if ($this->blockers($u, $d, $p) !== []) { $this->put($u, ...$old); continue; }
                $this->put($u, $d, $p);
                $delta = $this->localPen([$u], $days) - $before;
                if ($delta <= 0 || mt_rand() / mt_getrandmax() < exp(-$delta / $T)) { $cur += $delta; }
                else { $this->release($u); $this->put($u, ...$old); }
            } else {
                // ئاڵوگۆڕ لەگەڵ یەکەیەکی تری هەمان پۆل
                $c = $this->units[$u]['cls'][array_rand($this->units[$u]['cls'])];
                $v = $byClass[$c][array_rand($byClass[$c])];
                if ($v === $u || !$this->place[$v] || $this->units[$v]['len'] !== $this->units[$u]['len']) continue;
                $pu = $this->place[$u]; $pv = $this->place[$v];
                if ($pu === $pv) continue;
                $days = array_unique([$pu[0], $pv[0]]);
                $before = $this->localPen([$u, $v], $days);
                $this->release($u); $this->release($v);
                if ($this->blockers($u, ...$pv) !== []) { $this->put($u, ...$pu); $this->put($v, ...$pv); continue; }
                $this->put($u, ...$pv);
                if ($this->blockers($v, ...$pu) !== []) { $this->release($u); $this->put($u, ...$pu); $this->put($v, ...$pv); continue; }
                $this->put($v, ...$pu);
                $delta = $this->localPen([$u, $v], $days) - $before;
                if ($delta <= 0 || mt_rand() / mt_getrandmax() < exp(-$delta / $T)) { $cur += $delta; }
                else { $this->release($u); $this->release($v); $this->put($u, ...$pu); $this->put($v, ...$pv); }
            }
            if ($cur < $best) { $best = $cur; $bestPlace = $this->snapshot(); }
        }
        $this->restore($bestPlace);
    }

    // ================= جێبەجێکردن =================
    public function run($seconds = 10.0) {
        $start = microtime(true);
        $movable = array_keys(array_filter($this->units, fn($u) => !$u['fixed']));
        $n = count($movable);
        if (!$n) return ['placed' => 0, 'total' => 0];

        $teacherUnits = [];
        foreach ($movable as $i) $teacherUnits[$this->units[$i]['t']] = ($teacherUnits[$this->units[$i]['t']] ?? 0) + $this->units[$i]['len'];
        $diff = [];
        foreach ($movable as $i) {
            $u = $this->units[$i];
            $diff[$i] = $this->difficulty($i) - $teacherUnits[$u['t']] * 0.5 - ($u['len'] - 1) * 10;
        }

        // قۆناغی ١: تا ٤٠٪ ی کات، یان تا هەموو یەکەکان دادەنرێن
        $base = $this->snapshot();
        $bestPlace = null; $bestKey = null; $attempt = 0;
        do {
            $this->restore($base);
            $keys = [];
            foreach ($movable as $i) $keys[$i] = $diff[$i] + ($attempt ? mt_rand(0, 600) / 100 : 0);
            $order = $movable;
            usort($order, fn($a, $b) => $keys[$a] <=> $keys[$b]);
            foreach ($order as $uid) $this->tryPlace($uid);
            $placed = count(array_filter($movable, fn($i) => $this->place[$i]));
            $key = [$placed, -$this->totalPen()];
            if ($bestKey === null || $key > $bestKey) { $bestKey = $key; $bestPlace = $this->snapshot(); }
            $attempt++;
        } while ($bestKey[0] < $n && microtime(true) - $start < $seconds * 0.4);

        $this->restore($bestPlace);
        // قۆناغی ٢: باشترکردن بە کاتی ماوە
        $this->optimize($start + $seconds, $movable);

        return ['placed' => count(array_filter($movable, fn($i) => $this->place[$i])), 'total' => $n];
    }

    // ================= ڕاپۆرتی کوالێتی =================
    public function qualityReport() {
        $gapsT = []; $gapsC = []; $issues = [];
        $dayNames = null;
        for ($d = 0; $d < $this->D; $d++) {
            foreach ($this->teacherOcc as $t => $days) {
                $occ = $days[$d] ?? [];
                if (!$occ) continue;
                $ps = array_keys($occ);
                $g = max($ps) - min($ps) + 1 - count($occ);
                $gapsT[$t] = ($gapsT[$t] ?? 0) + $g;
                if ($g > $this->tMaxGaps[$t]) $issues[] = ['t', $t, $d, "{$g} بۆشایی"];
                if ($this->tMinDay[$t] && count($occ) < $this->tMinDay[$t]) $issues[] = ['t', $t, $d, count($occ) . " وانە (کەمترین {$this->tMinDay[$t]})"];
                if ($this->tMaxDay[$t] && ($this->tDay[$t][$d] ?? 0) > $this->tMaxDay[$t]) $issues[] = ['t', $t, $d, "{$this->tDay[$t][$d]} وانە (زۆرترین {$this->tMaxDay[$t]})"];
            }
            foreach ($this->classOcc as $c => $days) {
                $occ = $days[$d] ?? [];
                if (!$occ) continue;
                $ps = array_keys($occ);
                $g = max($ps) - min($ps) + 1 - count($occ);
                $gapsC[$c] = ($gapsC[$c] ?? 0) + $g;
                if ($g) $issues[] = ['c', $c, $d, "{$g} بۆشایی لە نێوان وانەکاندا"];
            }
        }
        $timeMiss = 0;
        foreach ($this->units as $uid => $_) if ($this->unitPen($uid) > 0 && $this->place[$uid]) $timeMiss++;
        $rel = 0;
        foreach ($this->classOcc as $c => $_) for ($d = 0; $d < $this->D; $d++) {
            $save = $this->cons;
            $this->cons['w_class_gaps'] = 0; $this->cons['w_relations'] = 1;
            if ($this->classDayPen($c, $d) > 0) { $rel++; $issues[] = ['c', $c, $d, 'پەیوەندی بابەتەکان جێبەجێ نەکراوە']; }
            $this->cons = $save;
        }
        $teachers = [];
        foreach ($gapsT as $t => $g) $teachers[] = ['name' => $this->names['t'][$t] ?? '?', 'gaps' => $g, 'lessons' => $this->tLoad[$t] ?? 0];
        usort($teachers, fn($a, $b) => $b['gaps'] <=> $a['gaps']);
        return [
            'score'         => $this->totalPen(),
            'lessons'       => count(array_filter($this->place)),
            'teacher_gaps'  => array_sum($gapsT),
            'class_gaps'    => array_sum($gapsC),
            'time_misses'   => $timeMiss,
            'relation_miss' => $rel,
            'teachers'      => $teachers,
            'issues'        => $issues,
            'names'         => $this->names,
        ];
    }
}

/** ڕاپۆرتی کوالێتی خشتەی ئێستا. */
function quality_report($pdo) {
    $gen = new TimetableGenerator($pdo, false, false);
    $r = $gen->qualityReport();
    $days = cfg_days($pdo);
    $r['issues'] = array_map(function ($i) use ($r, $days) {
        [$kind, $id, $d, $text] = $i;
        $who = $kind === 't' ? 'مامۆستا ' . ($r['names']['t'][$id] ?? '?') : 'پۆلی «' . ($r['names']['c'][$id] ?? '?') . '»';
        return "$who — " . ($days[$d] ?? '') . ": $text";
    }, $r['issues']);
    unset($r['names']);
    return $r;
}

/**
 * دروستکردنی خشتە و پاشەکەوتکردنی.
 * $regenerate = true: وانە ئۆتۆماتیکییە کۆنەکان (قوفڵنەکراو) دەسڕێنەوە و لە سەرەتاوە دروست دەکرێنەوە.
 */
function generate_timetable($pdo, $regenerate, $seconds = 10.0) {
    $before = quality_report($pdo);
    $gen = new TimetableGenerator($pdo, $regenerate);
    $res = $gen->run($seconds);

    undo_push($pdo, 'دروستکردنی ئۆتۆماتیکی');
    $pdo->beginTransaction();
    try {
        if ($regenerate) $pdo->exec("DELETE FROM timetable WHERE locked = 0");
        $st = $pdo->prepare("INSERT INTO timetable (class_id, teacher_id, subject_id, room_id, group_name, joint_group,
                             day_of_week, period_no, locked) VALUES (?,?,?,?,?,?,?,?,0)");
        $unplaced = [];
        $names = [];
        foreach (q($pdo, "SELECT id, name FROM classes") as $r)  $names['c'][$r['id']] = $r['name'];
        foreach (q($pdo, "SELECT id, name FROM subjects") as $r) $names['s'][$r['id']] = $r['name'];
        foreach ($gen->units as $i => $u) {
            if ($u['fixed']) continue;
            if (!$gen->place[$i]) {
                $cls = implode('، ', array_map(fn($c) => $names['c'][$c] ?? '?', $u['cls']));
                $label = "«{$names['s'][$u['subj']]}» ی پۆلی «{$cls}»";
                $unplaced[$label] = ($unplaced[$label] ?? 0) + $u['len'];
                continue;
            }
            [$d, $p] = $gen->place[$i];
            for ($k = 0; $k < $u['len']; $k++) {
                foreach ($u['grp'] as $c => $g) {
                    $st->execute([$c, $u['t'], $u['subj'], $u['room'] ?: null, $g, $u['joint'] ?? '', $d, $p + $k]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $messages = $gen->skipped;
    foreach ($unplaced as $label => $n) {
        $messages[] = "{$n} وانەی {$label} جێی نەبووەوە (مامۆستا، ژوور، کاتی بەتاڵ یان سنوورەکان ڕێگرن).";
    }
    $after = quality_report($pdo);
    return $res + ['messages' => $messages, 'before' => $before, 'after' => $after];
}
