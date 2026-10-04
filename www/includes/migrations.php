<?php
// ============================================================
// گۆڕانکارییەکانی schema — هەر بەشێک تەنها یەکجار جێبەجێ دەکرێت.
// بەشی نوێ تەنها لە کۆتاییدا زیاد بکە، هەرگیز بەشە کۆنەکان مەگۆڕە.
// ============================================================
return [

// ---------- ١: خشتە بنەڕەتییەکان (وەشانی یەکەم) ----------
file_get_contents(__DIR__ . '/schema.sql'),

// ---------- ٢: ژوور، گرووپ، پرۆگرامی خوێندن، ڕێکخستن، گەڕانەوە ----------
<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS rooms (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ئەو بابەتانەی هەر مامۆستایەک دەتوانێت بیڵێتەوە
CREATE TABLE IF NOT EXISTS teacher_subjects (
    teacher_id INTEGER NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
    subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    PRIMARY KEY (teacher_id, subject_id)
);

-- پرۆگرامی خوێندن: هەر پۆلێک هەفتانە چەند وانەی هەر بابەتێکی دەوێت
CREATE TABLE IF NOT EXISTS class_subjects (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id         INTEGER NOT NULL REFERENCES classes(id)  ON DELETE CASCADE,
    subject_id       INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    teacher_id       INTEGER REFERENCES teachers(id) ON DELETE SET NULL,
    room_id          INTEGER REFERENCES rooms(id)    ON DELETE SET NULL,
    group_name       TEXT NOT NULL DEFAULT '',
    periods_per_week INTEGER NOT NULL DEFAULT 1,
    double_count     INTEGER NOT NULL DEFAULT 0,
    UNIQUE (class_id, subject_id, group_name)
);

-- خشتە: ژوور و گرووپ و قوفڵ زیاد دەکرێن. UNIQUE ی پۆل لادەبرێت چونکە
-- دوو گرووپی یەک پۆل دەتوانن لە یەک کاتدا وانەیان هەبێت (لە کۆددا پشکنین دەکرێت).
CREATE TABLE timetable_v2 (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id    INTEGER NOT NULL REFERENCES classes(id)  ON DELETE CASCADE,
    teacher_id  INTEGER NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
    subject_id  INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    room_id     INTEGER REFERENCES rooms(id) ON DELETE SET NULL,
    group_name  TEXT NOT NULL DEFAULT '',
    day_of_week INTEGER NOT NULL,
    period_no   INTEGER NOT NULL,
    locked      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (teacher_id, day_of_week, period_no)
);
INSERT INTO timetable_v2 (id, class_id, teacher_id, subject_id, day_of_week, period_no, created_at)
    SELECT id, class_id, teacher_id, subject_id, day_of_week, period_no, created_at FROM timetable;
DROP TABLE timetable;
ALTER TABLE timetable_v2 RENAME TO timetable;
CREATE INDEX idx_tt_class ON timetable (class_id, day_of_week, period_no);
CREATE UNIQUE INDEX ux_tt_room ON timetable (room_id, day_of_week, period_no) WHERE room_id IS NOT NULL;

-- لابردنی ڕۆژە بەتاڵە دووبارەکان و ڕێگری لێیان
DELETE FROM teacher_offdays WHERE id NOT IN (
    SELECT MIN(id) FROM teacher_offdays GROUP BY teacher_id, day_of_week, IFNULL(period_no, 0)
);
CREATE UNIQUE INDEX ux_offday ON teacher_offdays (teacher_id, day_of_week, IFNULL(period_no, 0));

-- مێژووی گۆڕانکارییەکانی خشتە بۆ «گەڕانەوە» (Undo)
CREATE TABLE IF NOT EXISTS undo_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    snapshot   TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL,

// ---------- ٣: ڕێگرییە نەرمەکان، وانەی هاوبەش، پەیوەندی بابەت، جێگرەوە ----------
<<<'SQL'
-- سنوورەکانی مامۆستا (NULL = بێ سنوور / ڕێکخستنی گشتی)
ALTER TABLE teachers ADD COLUMN max_per_day INTEGER;
ALTER TABLE teachers ADD COLUMN min_per_day INTEGER;
ALTER TABLE teachers ADD COLUMN max_gaps INTEGER;

-- کاتی باشتر بۆ بابەت: any | early | late
ALTER TABLE subjects ADD COLUMN time_pref TEXT NOT NULL DEFAULT 'any';

-- وانەی هاوبەش: ئەو بابەتانەی پرۆگرام کە هەمان joint_group یان هەیە، پێکەوە دەوترێنەوە
ALTER TABLE class_subjects ADD COLUMN joint_group TEXT NOT NULL DEFAULT '';

-- پەیوەندی نێوان بابەتەکان بۆ هەر پۆلێک: not_same_day | consecutive
CREATE TABLE subject_relations (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_a INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    subject_b INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    kind      TEXT NOT NULL,
    UNIQUE (subject_a, subject_b, kind)
);

-- خشتە: UNIQUE ی مامۆستا لادەبرێت چونکە وانەی هاوبەش یەک مامۆستا لە چەند پۆلێکدا
-- لە یەک کاتدا دادەنێت (لە کۆددا پشکنین دەکرێت)
CREATE TABLE timetable_v3 (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id    INTEGER NOT NULL REFERENCES classes(id)  ON DELETE CASCADE,
    teacher_id  INTEGER NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
    subject_id  INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    room_id     INTEGER REFERENCES rooms(id) ON DELETE SET NULL,
    group_name  TEXT NOT NULL DEFAULT '',
    joint_group TEXT NOT NULL DEFAULT '',
    day_of_week INTEGER NOT NULL,
    period_no   INTEGER NOT NULL,
    locked      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO timetable_v3 (id, class_id, teacher_id, subject_id, room_id, group_name,
                          day_of_week, period_no, locked, created_at)
    SELECT id, class_id, teacher_id, subject_id, room_id, group_name,
           day_of_week, period_no, locked, created_at FROM timetable;
DROP TABLE timetable;
ALTER TABLE timetable_v3 RENAME TO timetable;
CREATE INDEX idx_tt_class   ON timetable (class_id, day_of_week, period_no);
CREATE INDEX idx_tt_teacher ON timetable (teacher_id, day_of_week, period_no);
CREATE INDEX idx_tt_room    ON timetable (room_id, day_of_week, period_no);

-- مامۆستای جێگرەوە بۆ ڕۆژێکی دیاریکراو (سەربەخۆیە لە ناسنامەی ڕیزەکانی خشتە)
CREATE TABLE substitutions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    sub_date      TEXT NOT NULL,
    day_of_week   INTEGER NOT NULL,
    period_no     INTEGER NOT NULL,
    class_id      INTEGER NOT NULL REFERENCES classes(id)  ON DELETE CASCADE,
    subject_id    INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    absent_id     INTEGER NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
    substitute_id INTEGER REFERENCES teachers(id) ON DELETE SET NULL,
    note          TEXT NOT NULL DEFAULT '',
    UNIQUE (sub_date, period_no, class_id, absent_id)
);
SQL,

];
