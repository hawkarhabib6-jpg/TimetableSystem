<?php require_once __DIR__ . '/includes/db.php'; ?>
<!DOCTYPE html>
<html lang="ckb" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>سیستەمی خشتەی هەفتانەی قوتابخانە</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header class="topbar">
    <h1>سیستەمی خشتەی هەفتانەی قوتابخانە</h1>
    <nav class="tabs">
        <button class="tab active" data-tab="timetable">خشتەی هەفتانە</button>
        <button class="tab" data-tab="teachers">مامۆستایان</button>
        <button class="tab" data-tab="subjects">بابەتەکان</button>
        <button class="tab" data-tab="classes">پۆلەکان</button>
        <button class="tab" data-tab="report">ڕاپۆرتی میلاک</button>
    </nav>
</header>

<main>
    <!-- ============ خشتە ============ -->
    <section id="tab-timetable" class="panel active">
        <div class="toolbar">
            <label>پۆل:
                <select id="ttClass"></select>
            </label>
            <span id="ttHint" class="hint"></span>
        </div>
        <div id="ttGridWrap"></div>
    </section>

    <!-- ============ مامۆستایان ============ -->
    <section id="tab-teachers" class="panel">
        <div class="form-row">
            <input id="tName" placeholder="ناوی مامۆستا">
            <input id="tPhone" placeholder="ژمارەی مۆبایل (ئارەزوومەندانە)">
            <input id="tMax" type="number" value="22" min="1" max="40" placeholder="میلاک (بەشە وانە)">
            <button onclick="addTeacher()">زیادکردن</button>
        </div>
        <table id="tTable" class="grid"></table>

        <div id="offdaysPanel" class="offdays-panel hidden">
            <h3 id="offTitle">ڕۆژە بەتاڵەکان</h3>
            <div class="form-row">
                <select id="offDay"></select>
                <select id="offPeriod"></select>
                <button onclick="addOffday()">زیادکردنی کاتی بەتاڵ</button>
                <button class="ghost" onclick="closeOffdays()">داخستن</button>
            </div>
            <div id="offList" class="off-list"></div>
        </div>
    </section>

    <!-- ============ بابەتەکان ============ -->
    <section id="tab-subjects" class="panel">
        <div class="form-row">
            <input id="sName" placeholder="ناوی بابەت (بۆ نموونە: بیرکاری)">
            <button onclick="addSubject()">زیادکردن</button>
        </div>
        <table id="sTable" class="grid"></table>
    </section>

    <!-- ============ پۆلەکان ============ -->
    <section id="tab-classes" class="panel">
        <div class="form-row">
            <input id="cName" placeholder="ناوی پۆل (بۆ نموونە: ۷/أ)">
            <input id="cGrade" type="number" min="1" max="12" placeholder="پۆل (٧–١٢)">
            <button onclick="addClass()">زیادکردن</button>
        </div>
        <table id="cTable" class="grid"></table>
    </section>

    <!-- ============ ڕاپۆرت ============ -->
    <section id="tab-report" class="panel">
        <h2>میلاکی مامۆستایان</h2>
        <table id="rTable" class="grid"></table>
    </section>
</main>

<!-- دیالۆگی دانانی وانە -->
<div id="assignModal" class="modal hidden">
    <div class="modal-box">
        <h3 id="assignTitle">دانانی وانە</h3>
        <label>بابەت:
            <select id="asSubject"></select>
        </label>
        <button id="btnSuggest" onclick="suggestTeachers()">پێشنیاری ئۆتۆماتیکی مامۆستا</button>
        <div id="suggestBox" class="suggest-box"></div>
        <label>مامۆستا:
            <select id="asTeacher"></select>
        </label>
        <div id="assignError" class="error hidden"></div>
        <div class="modal-actions">
            <button class="primary" onclick="doAssign()">دانان</button>
            <button onclick="closeModal()">داخستن</button>
        </div>
    </div>
</div>

<div id="toast" class="toast hidden"></div>

<script src="assets/app.js"></script>
</body>
</html>
