<?php
// ============================================================
// دروستکەری ئۆتۆماتیکی خشتە
//
// ڕێگە: هەر وانەیەکی پێویست (لە پرۆگرامی خوێندن) دەبێتە «یەکە»یەک
// (تاک یان دووانی). یەکە قورسەکان یەکەم دادەنرێن، و ئەگەر یەکەیەک
// جێی نەبووەوە، هەوڵ دەدرێت یەکەیەکی ڕێگر بگوازرێتەوە بۆ شوێنێکی تر.
// چەند جار بە ڕیزبەندی جیاواز دووبارە دەکرێتەوە و باشترین ئەنجام هەڵدەگیرێت.
// ============================================================
require_once __DIR__ . '/logic.php';

const FIXED = -1; // وانەی پێشتر دانراو کە ناگوازرێتەوە

class TimetableGenerator {
    private $D, $P;
    private $units = [];      // [cls, subj, grp, teacher, room, len, key]
    private $teacherMax = [];
    private $off = [];        // off[t][d][p] = true
    private $dayCap = [];     // key => زۆرترین جار لە ڕۆژێکدا

    // دۆخی ئێستا
    private $place, $classOcc, $teacherOcc, $roomOcc, $dayCount, $tLoad;
    private $base;            // دۆخی وانە جێگیرەکان، بۆ دەستپێکردنەوە

    public $skipped = [];     // ئەو بەشانەی پرۆگرام کە ناتوانرێت دابنرێن (بێ مامۆستا ...)

    public function __construct($pdo, $regenerate) {
        $this->D = count(cfg_days($pdo));
        $this->P = cfg_periods($pdo);

        foreach (q($pdo, "SELECT id, max_periods FROM teachers") as $t) {
            $this->teacherMax[(int) $t['id']] = (int) $t['max_periods'];
        }
        foreach (q($pdo, "SELECT * FROM teacher_offdays") as $o) {
            $ps = $o['period_no'] === null ? range(1, $this->P) : [(int) $o['period_no']];
            foreach ($ps as $p) $this->off[(int) $o['teacher_id']][(int) $o['day_of_week']][$p] = true;
        }

        // وانە جێگیرەکان (قوفڵکراو، یان هەمووی ئەگەر دووبارە دروستکردن نەبێت)
        $fixedSql = "SELECT * FROM timetable WHERE day_of_week < ? AND period_no <= ?"
                  . ($regenerate ? " AND locked = 1" : "");
        $fixed = q($pdo, $fixedSql, [$this->D, $this->P]);

        $this->resetState();
        $have = [];
        foreach ($fixed as $f) {
            $key = "{$f['class_id']}|{$f['subject_id']}|{$f['group_name']}";
            $have[$key] = ($have[$key] ?? 0) + 1;
            $this->occupy(FIXED, (int) $f['class_id'], $f['group_name'], (int) $f['teacher_id'],
                $f['room_id'] ? (int) $f['room_id'] : 0, $key, (int) $f['day_of_week'], (int) $f['period_no'], 1);
        }
        $this->base = [$this->classOcc, $this->teacherOcc, $this->roomOcc, $this->dayCount, $this->tLoad];

        foreach (curriculum($pdo) as $c) {
            $key = "{$c['class_id']}|{$c['subject_id']}|{$c['group_name']}";
            $need = (int) $c['periods_per_week'] - ($have[$key] ?? 0);
            if ($need <= 0) continue;
            if (!$c['teacher_id']) {
                $this->skipped[] = "«{$c['subject_name']}» ی پۆلی «{$c['class_name']}»: مامۆستا دیاری نەکراوە.";
                continue;
            }
            $total = (int) $c['periods_per_week'];
            $doubles = min((int) $c['double_count'], intdiv($need, 2));
            $occurrences = $total - min((int) $c['double_count'], intdiv($total, 2));
            $this->dayCap[$key] = max(1, (int) ceil($occurrences / max(1, $this->D)));
            $u = [(int) $c['class_id'], (int) $c['subject_id'], $c['group_name'],
                  (int) $c['teacher_id'], $c['room_id'] ? (int) $c['room_id'] : 0, 1, $key];
            for ($i = 0; $i < $doubles; $i++) $this->units[] = array_replace($u, [5 => 2]);
            for ($i = 0; $i < $need - 2 * $doubles; $i++) $this->units[] = $u;
        }
        $this->diagnose($pdo);
    }

    /** پشکنینی پێشوەختە: ئەو حاڵەتانەی کە بە هیچ شێوەیەک جێیان نابێتەوە، بە ڕوونی. */
    private function diagnose($pdo) {
        $slots = $this->D * $this->P;
        $tDemand = $this->tLoad; $rDemand = [];
        foreach ($this->roomOcc as $r => $days) foreach ($days as $ps) $rDemand[$r] = ($rDemand[$r] ?? 0) + count($ps);
        foreach ($this->units as [, , , $t, $room, $len]) {
            $tDemand[$t] = ($tDemand[$t] ?? 0) + $len;
            if ($room) $rDemand[$room] = ($rDemand[$room] ?? 0) + $len;
        }
        $tn = array_column(q($pdo, "SELECT id, full_name FROM teachers"), 'full_name', 'id');
        $rn = array_column(q($pdo, "SELECT id, name FROM rooms"), 'name', 'id');
        foreach ($tDemand as $t => $n) {
            $free = $slots;
            foreach ($this->off[$t] ?? [] as $ps) $free -= count($ps);
            if ($n > ($this->teacherMax[$t] ?? 0)) {
                $this->skipped[] = "مامۆستا {$tn[$t]}: پرۆگرامەکە {$n} وانەی پێویستە بەڵام میلاکی {$this->teacherMax[$t]}ـە.";
            }
            if ($n > $free) {
                $this->skipped[] = "مامۆستا {$tn[$t]}: {$n} وانەی پێویستە بەڵام تەنها {$free} کاتی بەردەستی هەیە (کاتی بەتاڵ زۆرە).";
            }
        }
        foreach ($rDemand as $r => $n) {
            if ($n > $slots) $this->skipped[] = "ژووری «{$rn[$r]}»: {$n} وانەی پێویستە بەڵام هەفتە تەنها {$slots} کاتی هەیە.";
        }
    }

    private function resetState() {
        $this->place = array_fill(0, count($this->units), null);
        $this->classOcc = $this->teacherOcc = $this->roomOcc = $this->dayCount = $this->tLoad = [];
    }

    private function occupy($uid, $cls, $grp, $t, $room, $key, $d, $p, $len) {
        for ($k = 0; $k < $len; $k++) {
            $pp = $p + $k;
            $this->classOcc[$cls][$d][$pp][$grp] = $uid;
            $this->teacherOcc[$t][$d][$pp] = $uid;
            if ($room) $this->roomOcc[$room][$d][$pp] = $uid;
        }
        $this->dayCount[$key][$d] = ($this->dayCount[$key][$d] ?? 0) + 1;
        $this->tLoad[$t] = ($this->tLoad[$t] ?? 0) + $len;
    }

    private function release($uid) {
        [$cls, , $grp, $t, $room, $len, $key] = $this->units[$uid];
        [$d, $p] = $this->place[$uid];
        for ($k = 0; $k < $len; $k++) {
            $pp = $p + $k;
            unset($this->classOcc[$cls][$d][$pp][$grp]);
            unset($this->teacherOcc[$t][$d][$pp]);
            if ($room) unset($this->roomOcc[$room][$d][$pp]);
        }
        $this->dayCount[$key][$d]--;
        $this->tLoad[$t] -= $len;
        $this->place[$uid] = null;
    }

    private function put($uid, $d, $p) {
        [$cls, , $grp, $t, $room, $len, $key] = $this->units[$uid];
        $this->occupy($uid, $cls, $grp, $t, $room, $key, $d, $p, $len);
        $this->place[$uid] = [$d, $p];
    }

    /**
     * ئەو یەکانەی ڕێگرن لە دانانی $uid لە (d,p).
     * [] = بەتاڵە، null = ناکرێت (ڕێگری جێگیر یان کاتی بەتاڵ ...)
     */
    private function blockers($uid, $d, $p) {
        [$cls, , $grp, $t, $room, $len, $key] = $this->units[$uid];
        if ($p + $len - 1 > $this->P) return null;
        if (($this->dayCount[$key][$d] ?? 0) >= $this->dayCap[$key]) return null;
        if (($this->tLoad[$t] ?? 0) + $len > ($this->teacherMax[$t] ?? 0)) return null;
        $b = [];
        for ($k = 0; $k < $len; $k++) {
            $pp = $p + $k;
            if (!empty($this->off[$t][$d][$pp])) return null;
            foreach ($this->classOcc[$cls][$d][$pp] ?? [] as $g => $other) {
                if (groups_compatible($grp, (string) $g)) continue;
                if ($other === FIXED) return null;
                $b[$other] = true;
            }
            foreach ([$this->teacherOcc[$t][$d][$pp] ?? null,
                      $room ? ($this->roomOcc[$room][$d][$pp] ?? null) : null] as $other) {
                if ($other === null) continue;
                if ($other === FIXED) return null;
                $b[$other] = true;
            }
        }
        return array_keys($b);
    }

    private function freeSlots($uid) {
        $out = [];
        for ($d = 0; $d < $this->D; $d++) {
            for ($p = 1; $p <= $this->P; $p++) {
                if ($this->blockers($uid, $d, $p) === []) $out[] = [$d, $p];
            }
        }
        return $out;
    }

    /**
     * باشترین شوێن: ڕۆژێک کە ئەم بابەتەی کەمتری تێدایە، و بەشە وانەی سەرەتا.
     * گرووپەکان هاوکات لەگەڵ گرووپەکانی تری هەمان پۆل دادەنرێن بۆ ئەوەی کات بەفیڕۆ نەچێت.
     */
    private function best($uid, $slots) {
        [$cls, , $grp, , , , $key] = $this->units[$uid];
        $best = null; $bestScore = PHP_INT_MAX;
        foreach ($slots as [$d, $p]) {
            $score = ($this->dayCount[$key][$d] ?? 0) * 100 + $p * 10 + mt_rand(0, 25);
            if ($grp !== '' && !empty($this->classOcc[$cls][$d][$p])) $score -= 1000;
            if ($score < $bestScore) { $bestScore = $score; $best = [$d, $p]; }
        }
        return $best;
    }

    /** هەوڵی دانان، بە گواستنەوەی یەک ڕێگر ئەگەر پێویست بوو. */
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

    /** ئاستی قورسی: ژمارەی شوێنە بەردەستەکان (کەمتر = قورستر). */
    private function difficulty($uid) {
        $n = 0;
        [, , , $t, , $len] = $this->units[$uid];
        for ($d = 0; $d < $this->D; $d++) {
            for ($p = 1; $p <= $this->P - $len + 1; $p++) {
                if (empty($this->off[$t][$d][$p])) $n++;
            }
        }
        return $n / $len;
    }

    public function run($seconds = 8.0) {
        $n = count($this->units);
        $bestPlace = array_fill(0, $n, null);
        $bestCount = -1;
        if (!$n) return [$bestPlace, 0];

        // یەکەکانی هەر مامۆستایەک: مامۆستای پڕکار یەکەم
        $teacherUnits = [];
        foreach ($this->units as $u) $teacherUnits[$u[3]] = ($teacherUnits[$u[3]] ?? 0) + $u[5];
        $this->place = array_fill(0, $n, null);
        $diff = [];
        foreach ($this->units as $i => $u) {
            $diff[$i] = $this->difficulty($i) - $teacherUnits[$u[3]] * 0.5 - ($u[5] - 1) * 10;
        }

        $start = microtime(true);
        $attempt = 0;
        do {
            [$this->classOcc, $this->teacherOcc, $this->roomOcc, $this->dayCount, $this->tLoad] = $this->base;
            $this->place = array_fill(0, $n, null);
            $order = range(0, $n - 1);
            $noise = $attempt === 0 ? 0 : 6;
            $keys = [];
            foreach ($order as $i) $keys[$i] = $diff[$i] + mt_rand(0, $noise * 100) / 100;
            usort($order, fn($a, $b) => $keys[$a] <=> $keys[$b]);

            $placed = 0;
            foreach ($order as $uid) {
                if ($this->tryPlace($uid)) $placed++;
            }
            $placed = count(array_filter($this->place));
            if ($placed > $bestCount) { $bestCount = $placed; $bestPlace = $this->place; }
            $attempt++;
        } while ($bestCount < $n && microtime(true) - $start < $seconds);

        return [$bestPlace, $bestCount];
    }

    public function units() { return $this->units; }
}

/**
 * دروستکردنی خشتە و پاشەکەوتکردنی.
 * $regenerate = true: وانە ئۆتۆماتیکییە کۆنەکان (قوفڵنەکراو) دەسڕێنەوە و لە سەرەتاوە دروست دەکرێنەوە.
 */
function generate_timetable($pdo, $regenerate, $seconds = 8.0) {
    $gen = new TimetableGenerator($pdo, $regenerate);
    [$place, $count] = $gen->run($seconds);
    $units = $gen->units();

    $names = [];
    foreach (q($pdo, "SELECT id, name FROM classes") as $r)  $names['c'][$r['id']] = $r['name'];
    foreach (q($pdo, "SELECT id, name FROM subjects") as $r) $names['s'][$r['id']] = $r['name'];

    undo_push($pdo, 'دروستکردنی ئۆتۆماتیکی');
    $pdo->beginTransaction();
    try {
        if ($regenerate) $pdo->exec("DELETE FROM timetable WHERE locked = 0");
        $st = $pdo->prepare("INSERT INTO timetable (class_id, teacher_id, subject_id, room_id, group_name,
                             day_of_week, period_no, locked) VALUES (?,?,?,?,?,?,?,0)");
        $unplaced = [];
        foreach ($units as $i => [$cls, $subj, $grp, $t, $room, $len]) {
            if (!$place[$i]) {
                $label = "«{$names['s'][$subj]}» ی پۆلی «{$names['c'][$cls]}»" . ($grp !== '' ? " (گرووپی $grp)" : '');
                $unplaced[$label] = ($unplaced[$label] ?? 0) + $len;
                continue;
            }
            [$d, $p] = $place[$i];
            for ($k = 0; $k < $len; $k++) {
                $st->execute([$cls, $t, $subj, $room ?: null, $grp, $d, $p + $k]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $messages = $gen->skipped;
    foreach ($unplaced as $label => $n) {
        $messages[] = "{$n} وانەی {$label} جێی نەبووەوە (مامۆستا، ژوور یان کاتی بەتاڵ ڕێگرن).";
    }
    return ['total' => count($units), 'placed' => $count, 'messages' => $messages];
}
