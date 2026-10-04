<?php
require_once __DIR__ . '/includes/backup.php';
try { backup_auto_daily($pdo); } catch (Throwable $e) { /* پاڵپشتی ئۆتۆماتیکی نابێت ڕێگر بێت */ }
$school = setting($pdo, 'school_name', '');
?>
<!DOCTYPE html>
<html lang="ckb" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>سیستەمی خشتەی هەفتانەی قوتابخانە</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header class="topbar no-print">
    <h1>سیستەمی خشتەی هەفتانەی قوتابخانە <span id="schoolName" class="school"><?= htmlspecialchars($school) ?></span></h1>
    <nav class="tabs">
        <button class="tab active" data-tab="timetable">خشتەی هەفتانە</button>
        <button class="tab" data-tab="teachers">مامۆستایان</button>
        <button class="tab" data-tab="subjects">بابەتەکان</button>
        <button class="tab" data-tab="classes">پۆلەکان و پرۆگرام</button>
        <button class="tab" data-tab="rooms">ژوورەکان</button>
        <button class="tab" data-tab="report">ڕاپۆرت و پشکنین</button>
        <button class="tab" data-tab="settings">ڕێکخستن و پاڵپشتی</button>
    </nav>
</header>

<main class="no-print">
    <!-- ============ خشتە ============ -->
    <section id="tab-timetable" class="panel active">
        <div class="toolbar">
            <label>پیشاندان بەپێی:
                <select id="ttView">
                    <option value="class">پۆل</option>
                    <option value="teacher">مامۆستا</option>
                    <option value="room">ژوور</option>
                </select>
            </label>
            <select id="ttEntity"></select>
            <span id="ttHint" class="hint"></span>
            <span class="spacer"></span>
            <button id="btnGenerate" class="accent">⚙ دروستکردنی ئۆتۆماتیکی</button>
            <button id="btnUndo" class="ghost" disabled>↶ گەڕانەوە</button>
            <button id="btnPrint" class="ghost">🖨 چاپ / PDF</button>
            <button id="btnExport" class="ghost">⬇ Excel</button>
            <button id="btnClear" class="ghost danger-text">🧹 پاککردنەوە</button>
        </div>
        <div id="ttProgress" class="progress hidden"></div>
        <div id="ttGridWrap"></div>
        <p class="hint legend">
            کرتە لە خانەی بەتاڵ بکە بۆ دانانی وانە، کرتە لە وانە بکە بۆ دەستکاریکردن.
            وانەیەک ڕابکێشە بۆ خانەیەکی تر بۆ گواستنەوە (یان ئاڵوگۆڕ لەگەڵ وانەیەکی تر).
            🔒 = وانەی دەستی کە لە دروستکردنی ئۆتۆماتیکیدا ناگۆڕێت.
        </p>
    </section>

    <!-- ============ مامۆستایان ============ -->
    <section id="tab-teachers" class="panel">
        <div class="form-row">
            <button data-new="teacher">＋ مامۆستای نوێ</button>
            <input id="tFilter" class="filter" placeholder="گەڕان...">
        </div>
        <table id="tTable" class="grid"></table>

        <div id="offdaysPanel" class="offdays-panel hidden">
            <h3 id="offTitle">ڕۆژە بەتاڵەکان</h3>
            <div class="form-row">
                <select id="offDay"></select>
                <select id="offPeriod"></select>
                <button id="btnAddOff">زیادکردنی کاتی بەتاڵ</button>
                <button class="ghost" id="btnCloseOff">داخستن</button>
            </div>
            <div id="offList" class="off-list"></div>
        </div>
    </section>

    <!-- ============ بابەتەکان ============ -->
    <section id="tab-subjects" class="panel">
        <div class="form-row">
            <input id="sName" placeholder="ناوی بابەت (بۆ نموونە: بیرکاری)">
            <button id="btnAddSubject">زیادکردن</button>
        </div>
        <table id="sTable" class="grid"></table>
    </section>

    <!-- ============ پۆلەکان و پرۆگرامی خوێندن ============ -->
    <section id="tab-classes" class="panel">
        <div class="form-row">
            <input id="cName" placeholder="ناوی پۆل (بۆ نموونە: ۷/أ)">
            <input id="cGrade" type="number" min="1" max="12" placeholder="ئاست (١–١٢)">
            <button id="btnAddClass">زیادکردن</button>
        </div>
        <div class="two-col">
            <table id="cTable" class="grid"></table>
            <div id="currPanel" class="card hidden">
                <h3 id="currTitle">پرۆگرامی خوێندن</h3>
                <p class="hint">ئەم پۆلە هەفتانە چەند وانەی هەر بابەتێکی دەوێت. دروستکەری ئۆتۆماتیکی ئەمە بەکاردەهێنێت.</p>
                <div class="form-row">
                    <button data-new="curriculum">＋ بابەت بۆ ئەم پۆلە</button>
                    <select id="currCopyFrom"></select>
                    <button class="ghost" id="btnCopyCurr">کۆپیکردنی پرۆگرام لەو پۆلەوە</button>
                </div>
                <table id="currTable" class="grid"></table>
            </div>
        </div>
    </section>

    <!-- ============ ژوورەکان ============ -->
    <section id="tab-rooms" class="panel">
        <div class="form-row">
            <input id="rName" placeholder="ناوی ژوور (بۆ نموونە: تاقیگەی کیمیا)">
            <button id="btnAddRoom">زیادکردن</button>
        </div>
        <p class="hint">ژوور ئارەزوومەندانەیە — تەنها بۆ تاقیگە و هۆڵە هاوبەشەکان پێویستە، بۆ ئەوەی دوو پۆل لە یەک کاتدا تێیدا نەبن.</p>
        <table id="rmTable" class="grid"></table>
    </section>

    <!-- ============ ڕاپۆرت ============ -->
    <section id="tab-report" class="panel">
        <h2>پشکنینی خشتە</h2>
        <div id="validateBox" class="issues"></div>
        <h2>میلاکی مامۆستایان</h2>
        <table id="rTable" class="grid"></table>
    </section>

    <!-- ============ ڕێکخستن ============ -->
    <section id="tab-settings" class="panel">
        <div class="two-col">
            <div class="card">
                <h3>ڕێکخستنی قوتابخانە</h3>
                <label>ناوی قوتابخانە<input id="setSchool"></label>
                <label>ژمارەی بەشە وانەکانی ڕۆژێک<input id="setPeriods" type="number" min="1" max="12"></label>
                <label>ژمارەی ڕۆژەکانی هەفتە<input id="setDaysCount" type="number" min="1" max="7"></label>
                <div id="setDays" class="stack"></div>
                <h4>کاتی بەشە وانەکان (ئارەزوومەندانە، بۆ چاپ)</h4>
                <div id="setTimes" class="stack"></div>
                <button id="btnSaveSettings" class="primary">پاشەکەوتکردن</button>
            </div>
            <div class="card">
                <h3>پاڵپشتی (Backup)</h3>
                <p class="hint">ڕۆژانە پاڵپشتییەکی ئۆتۆماتیکی دەگیرێت. شوێنی فایلەکان: <code id="backupDir"></code></p>
                <div class="form-row">
                    <button id="btnBackup">پاڵپشتی ئێستا</button>
                    <a class="btn ghost" href="api/index.php?action=backup_download">⬇ داگرتنی داتا</a>
                    <label class="btn ghost">⬆ گەڕاندنەوە لە فایل
                        <input type="file" id="restoreFile" accept=".db" hidden>
                    </label>
                </div>
                <table id="bTable" class="grid"></table>
            </div>
        </div>
    </section>
</main>

<!-- دیالۆگی دانان / دەستکاریکردنی وانە -->
<div id="lessonModal" class="modal hidden">
    <div class="modal-box">
        <h3 id="lessonTitle">دانانی وانە</h3>
        <label>پۆل:<select id="lsClass"></select></label>
        <label>بابەت:<select id="lsSubject"></select></label>
        <div class="row2">
            <label>گرووپ (ئارەزوومەندانە):<input id="lsGroup" placeholder="بۆ نموونە: کوڕان"></label>
            <label>ژوور:<select id="lsRoom"></select></label>
        </div>
        <button id="btnSuggest" class="info">پێشنیاری ئۆتۆماتیکی مامۆستا</button>
        <div id="suggestBox" class="suggest-box"></div>
        <label>مامۆستا:<select id="lsTeacher"></select></label>
        <label class="check" id="lsDoubleWrap"><input type="checkbox" id="lsDouble"> وانەی دووانی (دوو بەشە وانەی بەدوای یەکدا)</label>
        <label class="check"><input type="checkbox" id="lsLocked" checked> 🔒 قوفڵ (لە دروستکردنی ئۆتۆماتیکیدا مەیگۆڕە)</label>
        <div id="lessonError" class="error hidden"></div>
        <div class="modal-actions">
            <button class="primary" id="btnSaveLesson">پاشەکەوتکردن</button>
            <button class="danger hidden" id="btnDeleteLesson">سڕینەوە</button>
            <button class="ghost" data-close>داخستن</button>
        </div>
    </div>
</div>

<!-- دیالۆگی گشتی بۆ فۆڕمەکان (مامۆستا، پرۆگرام، ...) -->
<div id="formModal" class="modal hidden">
    <div class="modal-box">
        <h3 id="formTitle"></h3>
        <div id="formBody"></div>
        <div id="formError" class="error hidden"></div>
        <div class="modal-actions">
            <button class="primary" id="btnFormSave">پاشەکەوتکردن</button>
            <button class="ghost" data-close>داخستن</button>
        </div>
    </div>
</div>

<div id="printArea" class="print-only"></div>
<div id="toast" class="toast hidden"></div>

<script src="assets/app.js"></script>
</body>
</html>
