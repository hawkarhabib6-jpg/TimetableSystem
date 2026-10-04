// ============================================================
// سیستەمی خشتەی هەفتانە — کۆدی پێشەوە
// ============================================================
const DEFAULT_DAYS = ['یەکشەممە','دووشەممە','سێشەممە','چوارشەممە','پێنجشەممە','هەینی','شەممە'];

let CFG = { days: DEFAULT_DAYS.slice(0,5), periods: 6, period_times: [], school_name: '' };
let CACHE = { teachers:[], subjects:[], classes:[], rooms:[] };

const $ = id => document.getElementById(id);

// --- API: هەڵەکان وەک exception دەگەڕێنەوە و خۆکارانە پیشان دەدرێن ---
class ApiError extends Error {}
async function api(action, body=null, query=''){
  const opt = body instanceof FormData ? { method:'POST', body }
            : body ? { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) }
            : { method:'GET' };
  let r;
  try {
    const res = await fetch(`api/index.php?action=${action}${query}`, opt);
    r = await res.json();
  } catch (e) {
    throw new ApiError('پەیوەندی لەگەڵ بەرنامەکە نەکرا: ' + e.message);
  }
  if (!r.ok) throw new ApiError(r.error || 'هەڵەیەکی نەزانراو');
  return r;
}
window.addEventListener('unhandledrejection', e=>{
  toast(e.reason?.message || String(e.reason), 'err');
});

function toast(msg, kind='ok'){
  const t = $('toast');
  t.textContent = msg;
  t.className = 'toast ' + kind;
  clearTimeout(toast._t);
  toast._t = setTimeout(()=>{ t.className = 'toast hidden'; }, kind==='ok' ? 3000 : 6000);
}

function esc(s){
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
const opts = (list, label, selected, empty) =>
  (empty!==undefined ? `<option value="">${esc(empty)}</option>` : '') +
  list.map(x=>`<option value="${x.id}" ${String(x.id)===String(selected)?'selected':''}>${esc(label(x))}</option>`).join('');
const byId = (list, id) => list.find(x=>String(x.id)===String(id));

// --- مۆداڵەکان ---
function openModal(id){ $(id).classList.remove('hidden'); }
function closeModal(id){ $(id).classList.add('hidden'); }
document.addEventListener('click', e=>{
  const c = e.target.closest('[data-close]');
  if (c) c.closest('.modal').classList.add('hidden');
});
document.addEventListener('keydown', e=>{
  if (e.key==='Escape') document.querySelectorAll('.modal:not(.hidden)').forEach(m=>{
    if (m.dataset.temp) return; m.classList.add('hidden');
  });
});
function showErr(boxId, msg){ const e=$(boxId); e.textContent=msg; e.classList.remove('hidden'); }
function hideErr(boxId){ $(boxId).classList.add('hidden'); }

// confirm ی دەستکرد (دیالۆگی ناوزەدی Chrome لە دێسکتۆپدا کار ناکات)
function askConfirm(message){
  return new Promise(resolve=>{
    const ov = document.createElement('div');
    ov.className = 'modal'; ov.dataset.temp = '1';
    ov.innerHTML = `<div class="modal-box">
      <h3>دڵنیابوونەوە</h3>
      <p style="margin:10px 0 4px">${esc(message)}</p>
      <div class="modal-actions">
        <button class="primary" data-yes>بەڵێ</button>
        <button class="ghost" data-no>نەخێر</button>
      </div></div>`;
    document.body.appendChild(ov);
    ov.querySelector('[data-yes]').onclick = ()=>{ ov.remove(); resolve(true); };
    ov.querySelector('[data-no]').onclick  = ()=>{ ov.remove(); resolve(false); };
  });
}

/** فۆڕمی گشتی: html ی خانەکان + فەنکشنی پاشەکەوتکردن (ئەگەر هەڵە بێت لە ناو فۆڕمەکەدا پیشان دەدرێت). */
function openForm(title, html, onSave, saveLabel='پاشەکەوتکردن'){
  $('formTitle').textContent = title;
  $('formBody').innerHTML = html;
  $('btnFormSave').textContent = saveLabel;
  hideErr('formError');
  $('btnFormSave').onclick = async ()=>{
    $('btnFormSave').disabled = true;
    try { if (await onSave() !== false) closeModal('formModal'); }
    catch (e){ showErr('formError', e.message); }
    finally { $('btnFormSave').disabled = false; }
  };
  openModal('formModal');
  const first = $('formBody').querySelector('input,select');
  if (first) first.focus();
}

// --- تابەکان ---
document.querySelectorAll('.tab').forEach(btn=>{
  btn.onclick = ()=>{
    document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    $('tab-'+btn.dataset.tab).classList.add('active');
    if (btn.dataset.tab==='report') loadReport();
    if (btn.dataset.tab==='settings') { fillSettings(); loadBackups(); loadConstraints(); }
    if (btn.dataset.tab==='subs') initSubs();
    if (btn.dataset.tab==='timetable') renderTimetable();
  };
});

// ============ ڕێکخستنەکان ============
async function loadSettings(){
  CFG = (await api('settings_get')).data;
  $('schoolName').textContent = CFG.school_name;
}
function periodLabel(p){ const t = CFG.period_times[p-1]; return t ? `${p}<small>${esc(t)}</small>` : `${p}`; }

function fillSettings(){
  $('setSchool').value = CFG.school_name;
  $('setPeriods').value = CFG.periods;
  $('setDaysCount').value = CFG.days.length;
  renderDayInputs(CFG.days);
  renderTimeInputs(CFG.periods, CFG.period_times);
}
function renderDayInputs(days){
  $('setDays').innerHTML = days.map((d,i)=>
    `<input class="day-name" value="${esc(d)}" placeholder="ناوی ڕۆژی ${i+1}">`).join('');
}
function renderTimeInputs(n, times){
  let h = '';
  for (let p=1;p<=n;p++) h += `<label class="inline">${p}: <input class="period-time" value="${esc(times[p-1]||'')}" placeholder="8:00 - 8:45"></label>`;
  $('setTimes').innerHTML = h;
}
$('setDaysCount').oninput = ()=>{
  const n = Math.min(7, Math.max(1, parseInt($('setDaysCount').value)||1));
  const cur = [...document.querySelectorAll('.day-name')].map(i=>i.value);
  renderDayInputs(Array.from({length:n}, (_,i)=> cur[i] ?? DEFAULT_DAYS[i]));
};
$('setPeriods').oninput = ()=>{
  const n = Math.min(12, Math.max(1, parseInt($('setPeriods').value)||1));
  renderTimeInputs(n, [...document.querySelectorAll('.period-time')].map(i=>i.value));
};
$('btnSaveSettings').onclick = async ()=>{
  const r = await api('settings_save', {
    school_name: $('setSchool').value,
    periods: parseInt($('setPeriods').value),
    days: [...document.querySelectorAll('.day-name')].map(i=>i.value),
    period_times: [...document.querySelectorAll('.period-time')].map(i=>i.value),
  });
  CFG = r.data;
  $('schoolName').textContent = CFG.school_name;
  toast('ڕێکخستنەکان پاشەکەوت کران');
};

// ============ پاڵپشتی ============
async function loadBackups(){
  const r = await api('backup_list');
  $('backupDir').textContent = r.dir;
  $('bTable').innerHTML = `<tr><th>فایل</th><th>کات</th><th>قەبارە</th><th></th></tr>` +
    (r.data.length ? r.data.map(b=>`<tr><td>${esc(b.file)}</td><td>${esc(b.time)}</td>
      <td>${Math.ceil(b.size/1024)} KB</td>
      <td class="actions">
        <button class="ghost small" data-restore="${esc(b.file)}">گەڕاندنەوە</button>
        <a class="btn ghost small" href="api/index.php?action=backup_download&file=${encodeURIComponent(b.file)}">⬇</a>
      </td></tr>`).join('') : `<tr><td colspan="4" class="hint">هێشتا هیچ پاڵپشتییەک نییە.</td></tr>`);
}
$('btnBackup').onclick = async ()=>{ await api('backup_create', {}); loadBackups(); toast('پاڵپشتی گیرا'); };
$('bTable').onclick = async e=>{
  const f = e.target.closest('[data-restore]')?.dataset.restore;
  if (!f) return;
  if (!await askConfirm(`داتای ئێستا بە «${f}» دەگۆڕدرێت. (پێشتر پاڵپشتییەک لە داتای ئێستا دەگیرێت) بەردەوام بیت؟`)) return;
  await api('backup_restore', {file:f});
  toast('داتاکە گەڕێندرایەوە');
  await reloadAll(); loadBackups();
};
$('restoreFile').onchange = async e=>{
  const file = e.target.files[0];
  e.target.value = '';
  if (!file) return;
  if (!await askConfirm(`داتای ئێستا بە فایلی «${file.name}» دەگۆڕدرێت. بەردەوام بیت؟`)) return;
  const fd = new FormData(); fd.append('file', file);
  await api('backup_upload', fd);
  toast('داتاکە گەڕێندرایەوە');
  await reloadAll(); loadBackups();
};

// ============ مامۆستایان ============
async function loadTeachers(){
  CACHE.teachers = (await api('teachers_list')).data;
  renderTeachers();
}
function subjectNames(ids){ return ids.map(id=>byId(CACHE.subjects,id)?.name).filter(Boolean).join('، '); }
function renderTeachers(){
  const f = $('tFilter').value.trim();
  const list = CACHE.teachers.filter(x=>!f || x.full_name.includes(f));
  $('tTable').innerHTML = `<tr><th>ناو</th><th>مۆبایل</th><th>بابەتەکان</th><th>میلاک</th><th>دراوە</th><th>ماوە</th><th></th></tr>` +
    list.map(x=>`<tr>
      <td>${esc(x.full_name)}</td>
      <td>${esc(x.phone)}</td>
      <td class="muted">${esc(subjectNames(x.subject_ids)) || '<span class="hint">دیاری نەکراوە</span>'}</td>
      <td>${x.max_periods}</td>
      <td>${x.assigned}</td>
      <td>${badge(x.remaining)}</td>
      <td class="actions">
        <button class="icon-btn edit" title="دەستکاریکردن" data-act="edit" data-id="${x.id}">✎</button>
        <button class="icon-btn edit" title="ڕۆژە بەتاڵەکان" data-act="off" data-id="${x.id}">🗓</button>
        <button class="icon-btn edit" title="خشتەی ئەم مامۆستایە" data-act="tt" data-id="${x.id}">▦</button>
        <button class="icon-btn" title="سڕینەوە" data-act="del" data-id="${x.id}">🗑</button>
      </td>
    </tr>`).join('');
}
function badge(rem){
  if (rem<0) return `<span class="badge over">${rem}</span>`;
  if (rem===0) return `<span class="badge full">پڕ</span>`;
  return `<span class="badge under">${rem}</span>`;
}
$('tFilter').oninput = renderTeachers;
$('tTable').onclick = async e=>{
  const b = e.target.closest('[data-act]'); if (!b) return;
  const t = byId(CACHE.teachers, b.dataset.id);
  if (b.dataset.act==='edit') teacherForm(t);
  if (b.dataset.act==='off') openOffdays(t);
  if (b.dataset.act==='tt') showTimetableOf('teacher', t.id);
  if (b.dataset.act==='del'){
    if (!await askConfirm(`مامۆستا «${t.full_name}» بسڕدرێتەوە؟ (هەموو وانەکانیشی لە خشتەدا دەسڕێنەوە)`)) return;
    await api('teacher_delete', {id:t.id});
    await loadTeachers(); updateUndo();
  }
};
function teacherForm(t){
  t = t || { full_name:'', phone:'', max_periods:22, subject_ids:[], max_per_day:'', min_per_day:'', max_gaps:'' };
  const subj = CACHE.subjects.map(s=>`<label class="chip-check">
      <input type="checkbox" value="${s.id}" ${t.subject_ids.includes(Number(s.id))?'checked':''}> ${esc(s.name)}</label>`).join('')
    || '<span class="hint">سەرەتا بابەت زیاد بکە.</span>';
  openForm(t.id ? 'دەستکاریکردنی مامۆستا' : 'مامۆستای نوێ', `
    <label>ناوی مامۆستا<input id="fName" value="${esc(t.full_name)}"></label>
    <label>ژمارەی مۆبایل (ئارەزوومەندانە)<input id="fPhone" value="${esc(t.phone)}"></label>
    <label>میلاک (زۆرترین بەشە وانە لە هەفتەیەکدا)<input id="fMax" type="number" min="1" max="60" value="${t.max_periods}"></label>
    <div class="row3">
      <label>زۆرترین وانە لە ڕۆژێکدا<input id="fMaxDay" type="number" min="1" max="12" value="${t.max_per_day??''}" placeholder="بێ سنوور"></label>
      <label>کەمترین وانە (ئەگەر هات)<input id="fMinDay" type="number" min="1" max="12" value="${t.min_per_day??''}" placeholder="—"></label>
      <label>زۆرترین بۆشایی ڕۆژانە<input id="fGaps" type="number" min="0" max="6" value="${t.max_gaps??''}" placeholder="گشتی"></label>
    </div>
    <label>ئەو بابەتانەی دەیڵێتەوە:</label><div class="chips" id="fSubj">${subj}</div>`,
  async ()=>{
    await api(t.id ? 'teacher_update' : 'teacher_add', {
      id: t.id, full_name: $('fName').value, phone: $('fPhone').value,
      max_periods: parseInt($('fMax').value),
      max_per_day: $('fMaxDay').value, min_per_day: $('fMinDay').value, max_gaps: $('fGaps').value,
      subject_ids: [...document.querySelectorAll('#fSubj input:checked')].map(i=>Number(i.value)),
    });
    await loadTeachers();
    toast(t.id ? 'مامۆستا نوێکرایەوە' : 'مامۆستا زیادکرا');
  });
}
document.querySelector('[data-new="teacher"]').onclick = ()=>teacherForm(null);

// ============ ڕۆژە بەتاڵەکان ============
let offTeacher = null;
function openOffdays(t){
  offTeacher = t;
  $('offTitle').textContent = `ڕۆژە بەتاڵەکانی: ${t.full_name}`;
  $('offDay').innerHTML = CFG.days.map((d,i)=>`<option value="${i}">${esc(d)}</option>`).join('');
  let popts = '<option value="">هەموو ڕۆژەکە</option>';
  for(let p=1;p<=CFG.periods;p++) popts += `<option value="${p}">بەشە وانەی ${p}</option>`;
  $('offPeriod').innerHTML = popts;
  $('offdaysPanel').classList.remove('hidden');
  $('offdaysPanel').scrollIntoView({behavior:'smooth'});
  loadOffdays();
}
$('btnCloseOff').onclick = ()=>{ $('offdaysPanel').classList.add('hidden'); offTeacher=null; };
async function loadOffdays(){
  const r = await api('offdays_list', null, '&teacher_id='+offTeacher.id);
  const box = $('offList');
  if(!r.data.length){ box.innerHTML='<span class="hint">هیچ کاتێکی بەتاڵ زیاد نەکراوە.</span>'; return; }
  box.innerHTML = r.data.map(o=>{
    const day = esc(CFG.days[o.day_of_week] ?? '?');
    const label = o.period_no ? `${day} — بەشە وانەی ${o.period_no}` : `${day} — هەموو ڕۆژەکە`;
    return `<span class="off-chip">${label} <b data-id="${o.id}" title="سڕینەوە">✕</b></span>`;
  }).join('');
}
$('offList').onclick = async e=>{
  const id = e.target.closest('b[data-id]')?.dataset.id; if (!id) return;
  await api('offday_delete', {id}); loadOffdays();
};
$('btnAddOff').onclick = async ()=>{
  const r = await api('offday_add', {
    teacher_id: offTeacher.id, day_of_week: parseInt($('offDay').value), period_no: $('offPeriod').value });
  loadOffdays();
  if (r.warning) toast(r.warning, 'warn'); else toast('کاتی بەتاڵ زیادکرا');
};

// ============ بابەتەکان ============
async function loadSubjects(){
  CACHE.subjects = (await api('subjects_list')).data;
  const prefs = {any:'هەر کاتێک', early:'سەرەتای ڕۆژ (بابەتی قورس)', late:'کۆتایی ڕۆژ'};
  $('sTable').innerHTML = `<tr><th>ناوی بابەت</th><th>کاتی باشتر</th><th>مامۆستاکان</th><th></th></tr>` +
    CACHE.subjects.map(x=>{
      const teachers = CACHE.teachers.filter(t=>t.subject_ids.includes(Number(x.id))).map(t=>t.full_name).join('، ');
      const sel = Object.entries(prefs).map(([k,v])=>`<option value="${k}" ${x.time_pref===k?'selected':''}>${v}</option>`).join('');
      return `<tr><td>${esc(x.name)}</td><td><select class="small-select" data-pref="${x.id}">${sel}</select></td><td class="muted">${esc(teachers)}</td>
      <td class="actions">
        <button class="icon-btn edit" data-act="edit" data-id="${x.id}">✎</button>
        <button class="icon-btn" data-act="del" data-id="${x.id}">🗑</button></td></tr>`;
    }).join('');
  loadRelations();
}
async function loadRelations(){
  $('relA').innerHTML = $('relB').innerHTML = opts(CACHE.subjects, s=>s.name);
  const r = (await api('relations_list')).data;
  const kinds = {not_same_day:'لە یەک ڕۆژدا نەبن', consecutive:'بەدوای یەکدا بن'};
  $('relList').innerHTML = r.length ? r.map(x=>`<span class="off-chip">${esc(x.a_name)} ↔ ${esc(x.b_name)}: ${kinds[x.kind]} <b data-id="${x.id}" title="سڕینەوە">✕</b></span>`).join('')
    : '<span class="hint">هیچ پەیوەندییەک زیاد نەکراوە.</span>';
}
$('btnAddRel').onclick = async ()=>{
  await api('relation_add', {subject_a:$('relA').value, subject_b:$('relB').value, kind:$('relKind').value});
  loadRelations(); toast('پەیوەندی زیادکرا');
};
$('relList').onclick = async e=>{
  const id = e.target.closest('b[data-id]')?.dataset.id; if (!id) return;
  await api('relation_delete', {id}); loadRelations();
};
$('sTable').addEventListener('change', async e=>{
  const id = e.target.dataset.pref; if (!id) return;
  const s = byId(CACHE.subjects, id);
  await api('subject_update', {id, name:s.name, time_pref:e.target.value});
  s.time_pref = e.target.value; toast('پاشەکەوت کرا');
});
$('btnAddSubject').onclick = async ()=>{
  await api('subject_add', {name: $('sName').value});
  $('sName').value='';
  await loadSubjects(); toast('بابەت زیادکرا');
};
$('sName').onkeydown = e=>{ if (e.key==='Enter') $('btnAddSubject').click(); };
$('sTable').onclick = async e=>{
  const b = e.target.closest('[data-act]'); if (!b) return;
  const s = byId(CACHE.subjects, b.dataset.id);
  if (b.dataset.act==='edit') renameForm('بابەت', s, 'subject_update', loadSubjects, `<input type="hidden" id="fPref" value="${esc(s.time_pref)}">`);
  if (b.dataset.act==='del'){
    if(!await askConfirm(`بابەتی «${s.name}» بسڕدرێتەوە؟ (هەموو وانەکانی ئەم بابەتە لە خشتەدا دەسڕێنەوە)`)) return;
    await api('subject_delete', {id:s.id}); await loadSubjects(); loadTeachers(); updateUndo();
  }
};
function renameForm(what, item, action, reload, extra=''){
  openForm(`گۆڕینی ناوی ${what}`, `<label>ناو<input id="fName" value="${esc(item.name)}"></label>${extra}`,
    async ()=>{
      const body = { id:item.id, name: $('fName').value };
      if ($('fGrade')) body.grade_level = parseInt($('fGrade').value)||0;
      if ($('fPref')) body.time_pref = $('fPref').value;
      await api(action, body); await reload();
    });
}

// ============ پۆلەکان و پرۆگرامی خوێندن ============
let currClass = null;
let CURR_ALL = [];
async function loadClasses(){
  CACHE.classes = (await api('classes_list')).data;
  const curr = (await api('curriculum_list')).data;
  CURR_ALL = curr;
  const need = {};
  curr.forEach(c=> need[c.class_id] = (need[c.class_id]||0) + Number(c.periods_per_week));
  const cap = CFG.days.length * CFG.periods;
  $('cTable').innerHTML = `<tr><th>ناوی پۆل</th><th>ئاست</th><th>وانەی هەفتانە</th><th></th></tr>` +
    CACHE.classes.map(x=>`<tr class="${currClass && currClass.id==x.id ? 'selected':''}">
      <td>${esc(x.name)}</td><td>${x.grade_level??''}</td>
      <td>${need[x.id]||0} / ${cap} ${(need[x.id]||0) > cap ? '<span class="badge over">زیاترە</span>':''}</td>
      <td class="actions">
        <button class="ghost small" data-act="curr" data-id="${x.id}">پرۆگرام</button>
        <button class="icon-btn edit" data-act="tt" data-id="${x.id}" title="خشتە">▦</button>
        <button class="icon-btn edit" data-act="edit" data-id="${x.id}">✎</button>
        <button class="icon-btn" data-act="del" data-id="${x.id}">🗑</button></td></tr>`).join('');
  if (currClass) {
    currClass = byId(CACHE.classes, currClass.id) || null;
    currClass ? renderCurriculum(curr.filter(c=>c.class_id==currClass.id)) : $('currPanel').classList.add('hidden');
  }
  fillEntitySelect();
}
$('btnAddClass').onclick = async ()=>{
  await api('class_add', {name: $('cName').value, grade_level: parseInt($('cGrade').value)||0});
  $('cName').value=''; $('cGrade').value='';
  await loadClasses(); toast('پۆل زیادکرا');
};
$('cName').onkeydown = e=>{ if (e.key==='Enter') $('btnAddClass').click(); };
$('cTable').onclick = async e=>{
  const b = e.target.closest('[data-act]'); if (!b) return;
  const c = byId(CACHE.classes, b.dataset.id);
  if (b.dataset.act==='curr'){ currClass = c; loadClasses(); }
  if (b.dataset.act==='tt') showTimetableOf('class', c.id);
  if (b.dataset.act==='edit') renameForm('پۆل', c, 'class_update', loadClasses,
    `<label>ئاست<input id="fGrade" type="number" min="1" max="12" value="${c.grade_level??''}"></label>`);
  if (b.dataset.act==='del'){
    if(!await askConfirm(`پۆلی «${c.name}» بسڕدرێتەوە؟ (خشتە و پرۆگرامەکەشی دەسڕێتەوە)`)) return;
    await api('class_delete', {id:c.id}); await loadClasses(); loadTeachers(); updateUndo();
  }
};
let CURR = [];
function renderCurriculum(rows){
  CURR = rows;
  $('currPanel').classList.remove('hidden');
  $('currTitle').textContent = `پرۆگرامی خوێندنی پۆلی «${currClass.name}»`;
  $('currCopyFrom').innerHTML = opts(CACHE.classes.filter(c=>c.id!=currClass.id), c=>c.name, '', '— پۆلێک هەڵبژێرە —');
  const total = rows.reduce((s,r)=>s+Number(r.periods_per_week),0);
  $('currTable').innerHTML = `<tr><th>بابەت</th><th>گرووپ</th><th>مامۆستا</th><th>ژوور</th><th>هەفتانە</th><th>دووانی</th><th>دانراوە</th><th></th></tr>` +
    rows.map(r=>`<tr>
      <td>${esc(r.subject_name)}${r.joint_group?`<div class="joint-tag" title="وانەی هاوبەش">🔗 ${esc(r.joint_classes.join('، '))}</div>`:''}</td><td>${esc(r.group_name)}</td>
      <td>${r.teacher_name ? esc(r.teacher_name) : '<span class="badge over">نییە</span>'}</td>
      <td>${esc(r.room_name||'')}</td><td>${r.periods_per_week}</td><td>${r.double_count||''}</td>
      <td><span class="badge ${r.placed==r.periods_per_week?'full':(r.placed>r.periods_per_week?'over':'under')}">${r.placed}/${r.periods_per_week}</span></td>
      <td class="actions"><button class="icon-btn edit" data-act="edit" data-id="${r.id}">✎</button>
        <button class="icon-btn" data-act="del" data-id="${r.id}">🗑</button></td></tr>`).join('') +
    `<tr class="total"><td colspan="4">کۆ</td><td colspan="4">${total} / ${CFG.days.length*CFG.periods}</td></tr>`;
}
$('currTable').onclick = async e=>{
  const b = e.target.closest('[data-act]'); if (!b) return;
  const r = byId(CURR, b.dataset.id);
  if (b.dataset.act==='edit') curriculumForm(r);
  if (b.dataset.act==='del'){
    if(!await askConfirm(`«${r.subject_name}» لە پرۆگرامی ئەم پۆلە لابدرێت؟ (وانە دانراوەکان دەمێننەوە)`)) return;
    await api('curriculum_delete', {id:r.id}); loadClasses();
  }
};
document.querySelector('[data-new="curriculum"]').onclick = ()=>curriculumForm(null);
$('btnCopyCurr').onclick = async ()=>{
  const from = $('currCopyFrom').value;
  if (!from) return toast('پۆلێک هەڵبژێرە بۆ کۆپیکردن', 'err');
  await api('curriculum_copy', {from_class_id: from, to_class_id: currClass.id});
  await loadClasses(); toast('پرۆگرامەکە کۆپی کرا — مامۆستاکان دیاری بکە');
};
function teachersFor(subjectId){
  // مامۆستایانی ئەم بابەتە یەکەم دێن
  const q = CACHE.teachers.filter(t=>t.subject_ids.includes(Number(subjectId)));
  const rest = CACHE.teachers.filter(t=>!q.includes(t));
  return [q, rest];
}
function teacherOptions(subjectId, selected){
  const [q, rest] = teachersFor(subjectId);
  const o = list => list.map(t=>`<option value="${t.id}" ${String(t.id)===String(selected)?'selected':''}>${esc(t.full_name)} (${t.remaining} ماوە)</option>`).join('');
  return `<option value="">— هەڵبژێرە —</option>` +
    (q.length ? `<optgroup label="مامۆستایانی ئەم بابەتە">${o(q)}</optgroup><optgroup label="مامۆستایانی تر">${o(rest)}</optgroup>` : o(rest));
}
function curriculumForm(r){
  r = r || { subject_id: CACHE.subjects[0]?.id, teacher_id:'', room_id:'', group_name:'', periods_per_week:2, double_count:0, joint_group:'' };
  openForm(r.id ? 'دەستکاریکردنی بابەتی پرۆگرام' : `بابەت بۆ پۆلی «${currClass.name}»`, `
    <label>بابەت<select id="fSubj">${opts(CACHE.subjects, s=>s.name, r.subject_id)}</select></label>
    <label>مامۆستا<select id="fTeacher">${teacherOptions(r.subject_id, r.teacher_id)}</select></label>
    <div class="row2">
      <label>وانەی هەفتانە<input id="fPpw" type="number" min="1" max="40" value="${r.periods_per_week}"></label>
      <label>ژمارەی وانەی دووانی<input id="fDbl" type="number" min="0" max="20" value="${r.double_count}"></label>
    </div>
    <div class="row2">
      <label>ژوور (ئارەزوومەندانە)<select id="fRoom">${opts(CACHE.rooms, x=>x.name, r.room_id, '— بێ ژوور —')}</select></label>
      <label>گرووپ (ئارەزوومەندانە)<input id="fGroup" value="${esc(r.group_name)}" placeholder="بۆ نموونە: کچان"></label>
    </div>
    <p class="hint">گرووپ: ئەگەر پۆلەکە دابەش دەبێت (بۆ نموونە کوڕان/کچان)، دوو گرووپی جیاواز دەتوانن لە یەک کاتدا وانەیان هەبێت.</p>
    <label>وانەی هاوبەش (ئارەزوومەندانە)<input id="fJoint" value="${esc(r.joint_group)}" placeholder="بۆ نموونە: ئایین-٧" list="jointList"></label>
    <datalist id="jointList">${[...new Set(CURR_ALL.filter(x=>x.joint_group).map(x=>x.joint_group))].map(j=>`<option value="${esc(j)}">`).join('')}</datalist>
    <p class="hint">وانەی هاوبەش: ئەگەر یەک مامۆستا ئەم بابەتە بۆ چەند پۆلێک <b>پێکەوە</b> دەڵێتەوە، هەمان ناو لە پرۆگرامی هەموو ئەو پۆلانەدا بنووسە.
    دروستکەرەکە هەموویان لە یەک کاتدا دادەنێت.</p>`,
  async ()=>{
    await api('curriculum_save', {
      id: r.id, class_id: currClass.id, subject_id: $('fSubj').value, teacher_id: $('fTeacher').value,
      room_id: $('fRoom').value, group_name: $('fGroup').value, joint_group: $('fJoint').value,
      periods_per_week: parseInt($('fPpw').value), double_count: parseInt($('fDbl').value)||0,
    });
    await loadClasses();
  });
  $('fSubj').onchange = ()=>{ $('fTeacher').innerHTML = teacherOptions($('fSubj').value, $('fTeacher').value); };
}

// ============ ژوورەکان ============
async function loadRooms(){
  CACHE.rooms = (await api('rooms_list')).data;
  $('rmTable').innerHTML = `<tr><th>ناوی ژوور</th><th></th></tr>` +
    CACHE.rooms.map(x=>`<tr><td>${esc(x.name)}</td>
      <td class="actions">
        <button class="icon-btn edit" data-act="tt" data-id="${x.id}" title="خشتە">▦</button>
        <button class="icon-btn edit" data-act="edit" data-id="${x.id}">✎</button>
        <button class="icon-btn" data-act="del" data-id="${x.id}">🗑</button></td></tr>`).join('');
  fillEntitySelect();
}
$('btnAddRoom').onclick = async ()=>{
  await api('room_add', {name: $('rName').value});
  $('rName').value='';
  await loadRooms(); toast('ژوور زیادکرا');
};
$('rName').onkeydown = e=>{ if (e.key==='Enter') $('btnAddRoom').click(); };
$('rmTable').onclick = async e=>{
  const b = e.target.closest('[data-act]'); if (!b) return;
  const r = byId(CACHE.rooms, b.dataset.id);
  if (b.dataset.act==='tt') showTimetableOf('room', r.id);
  if (b.dataset.act==='edit') renameForm('ژوور', r, 'room_update', loadRooms);
  if (b.dataset.act==='del'){
    if(!await askConfirm(`ژووری «${r.name}» بسڕدرێتەوە؟ (وانەکان دەمێننەوە بەبێ ژوور)`)) return;
    await api('room_delete', {id:r.id}); loadRooms();
  }
};

// ============ خشتەی هەفتانە ============
const VIEW_SOURCES = {
  class:   ()=>CACHE.classes.map(c=>({id:c.id, name:c.name})),
  teacher: ()=>CACHE.teachers.map(t=>({id:t.id, name:t.full_name})),
  room:    ()=>CACHE.rooms.map(r=>({id:r.id, name:r.name})),
};
function fillEntitySelect(){
  const sel = $('ttEntity');
  const prev = sel.value;
  const list = VIEW_SOURCES[$('ttView').value]();
  sel.innerHTML = opts(list, x=>x.name, prev);
  if (!sel.value && list.length) sel.value = list[0].id;
}
$('ttView').onchange = ()=>{ $('ttEntity').innerHTML=''; fillEntitySelect(); renderTimetable(); };
$('ttEntity').onchange = renderTimetable;
function showTimetableOf(view, id){
  $('ttView').value = view;
  $('ttEntity').innerHTML = '';
  fillEntitySelect();
  $('ttEntity').value = id;
  document.querySelector('.tab[data-tab="timetable"]').click();
}

/** دەقی ناو خانەیەک بەپێی جۆری پیشاندان. */
function lessonHtml(x, view){
  const g = x.group_name ? `<span class="grp">${esc(x.group_name)}</span>` : '';
  const lines = [`<div class="subj">${esc(x.subject_name)} ${g}</div>`];
  if (view!=='class')   lines.push(`<div class="teach">${esc(x.class_name)}</div>`);
  if (view!=='teacher') lines.push(`<div class="teach">${esc(x.teacher_name)}</div>`);
  if (view!=='room' && x.room_name) lines.push(`<div class="room">${esc(x.room_name)}</div>`);
  return lines.join('');
}

function gridHtml(rows, view, interactive){
  const map = {};
  rows.forEach(x=> (map[`${x.day_of_week}-${x.period_no}`] ||= []).push(x));
  let html = '<table class="tt-table"><tr><th class="ph">بەشە وانە</th>';
  CFG.days.forEach(d=> html += `<th>${esc(d)}</th>`);
  html += '</tr>';
  for (let p=1; p<=CFG.periods; p++){
    html += `<tr><td class="periodhead">${periodLabel(p)}</td>`;
    for (let d=0; d<CFG.days.length; d++){
      const cell = map[`${d}-${p}`] || [];
      const inner = cell.map(x=>`<div class="lesson ${x.locked==1?'locked':'auto'}" ${interactive?`draggable="true" data-id="${x.id}"`:''}>
          ${lessonHtml(x, view)}</div>`).join('');
      html += `<td class="${interactive?'slot':''}" data-d="${d}" data-p="${p}">${inner || (interactive?'<div class="empty">+</div>':'')}</td>`;
    }
    html += '</tr>';
  }
  return html + '</table>';
}

let TT_ROWS = [];
async function renderTimetable(){
  const view = $('ttView').value, id = $('ttEntity').value;
  if (!id){
    const what = {class:'پۆلێک', teacher:'مامۆستایەک', room:'ژوورێک'}[view];
    $('ttGridWrap').innerHTML = `<p class="hint">سەرەتا ${what} زیاد بکە.</p>`;
    $('ttHint').textContent = '';
    return;
  }
  TT_ROWS = (await api('timetable_get', null, `&view=${view}&id=${id}`)).data;
  $('ttGridWrap').innerHTML = gridHtml(TT_ROWS, view, true);
  const total = CFG.periods*CFG.days.length;
  let hint = `پڕکراوە: ${new Set(TT_ROWS.map(x=>x.day_of_week+'-'+x.period_no)).size} / ${total} خانە`;
  if (view==='class'){
    const curr = (await api('curriculum_list', null, '&class_id='+id)).data;
    if (curr.length){
      const need = curr.reduce((s,c)=>s+Number(c.periods_per_week),0);
      const missing = curr.reduce((s,c)=>s+Math.max(0, c.periods_per_week - c.placed),0);
      hint += missing ? ` — ${missing} وانە لە ${need} وانەی پرۆگرام ماوە` : ' — پرۆگرامەکە تەواوە ✓';
    }
  }
  if (view==='teacher'){
    const t = byId(CACHE.teachers, id);
    if (t) hint += ` — میلاک: ${t.assigned}/${t.max_periods}`;
  }
  $('ttHint').textContent = hint;
}

// کرتە: خانەی بەتاڵ → وانەی نوێ؛ وانە → دەستکاریکردن
$('ttGridWrap').onclick = e=>{
  const l = e.target.closest('.lesson[data-id]');
  if (l) return openLesson(byId(TT_ROWS, l.dataset.id));
  const td = e.target.closest('td.slot');
  if (td) openLesson(null, +td.dataset.d, +td.dataset.p);
};

// ڕاکێشان و دانان (drag & drop) بۆ گواستنەوە یان ئاڵوگۆڕ
let dragId = null;
$('ttGridWrap').addEventListener('dragstart', e=>{
  const l = e.target.closest('.lesson[data-id]'); if (!l) return;
  dragId = l.dataset.id;
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', dragId);
  l.classList.add('dragging');
});
$('ttGridWrap').addEventListener('dragend', ()=>{
  dragId = null;
  document.querySelectorAll('.dragging,.drop-over').forEach(x=>x.classList.remove('dragging','drop-over'));
});
$('ttGridWrap').addEventListener('dragover', e=>{
  const td = e.target.closest('td.slot'); if (!td || !dragId) return;
  e.preventDefault();
  document.querySelectorAll('.drop-over').forEach(x=>x!==td && x.classList.remove('drop-over'));
  td.classList.add('drop-over');
});
$('ttGridWrap').addEventListener('drop', async e=>{
  const td = e.target.closest('td.slot'); if (!td || !dragId) return;
  e.preventDefault();
  const id = dragId; dragId = null;
  const src = byId(TT_ROWS, id);
  const d = +td.dataset.d, p = +td.dataset.p;
  if (src.day_of_week==d && src.period_no==p) return renderTimetable();
  const targets = TT_ROWS.filter(x=>x.day_of_week==d && x.period_no==p);
  try {
    if (targets.length===1){
      await api('timetable_swap', {id_a:id, id_b:targets[0].id});
      toast('وانەکان ئاڵوگۆڕ کران');
    } else {
      const r = await api('timetable_update', {id, day_of_week:d, period_no:p});
      toast(r.warning || 'وانە گوازرایەوە', r.warning?'warn':'ok');
    }
  } catch (err){ toast(err.message, 'err'); }
  refreshAfterChange();
});

// --- دیالۆگی وانە ---
let editing = null; // {id?, day, period}
function openLesson(row, day, period){
  const view = $('ttView').value, entity = $('ttEntity').value;
  editing = row ? {id: row.id, day: row.day_of_week, period: row.period_no} : {day, period};
  const base = row || {
    class_id:   view==='class'   ? entity : (CACHE.classes[0]?.id || ''),
    teacher_id: view==='teacher' ? entity : '',
    room_id:    view==='room'    ? entity : '',
    subject_id: '', group_name: '', locked: 1,
  };
  if (!CACHE.classes.length || !CACHE.subjects.length || !CACHE.teachers.length){
    return toast('سەرەتا پۆل، بابەت و مامۆستا زیاد بکە.', 'err');
  }
  $('lessonTitle').textContent = `${row ? 'دەستکاریکردنی وانە' : 'دانانی وانە'} — ${CFG.days[editing.day]}، بەشە وانەی ${editing.period}`;
  $('lsClass').innerHTML = opts(CACHE.classes, c=>c.name, base.class_id);
  $('lsRoom').innerHTML = opts(CACHE.rooms, r=>r.name, base.room_id, '— بێ ژوور —');
  $('lsGroup').value = base.group_name || '';
  $('lsLocked').checked = base.locked==1;
  $('lsDouble').checked = false;
  $('lsDoubleWrap').classList.toggle('hidden', !!row || editing.period >= CFG.periods);
  $('btnDeleteLesson').classList.toggle('hidden', !row);
  $('suggestBox').innerHTML = '';
  hideErr('lessonError');
  showJoint(row?.joint_group, row?.joint_group ? [...new Set(TT_ROWS.filter(x=>x.joint_group===row.joint_group).map(x=>x.class_name))] : []);
  fillLessonSubjects(base.subject_id).then(()=>{
    if (!row && !base.teacher_id) applyPlanned(); else fillLessonTeachers(base.teacher_id);
  });
  openModal('lessonModal');
}
let LS_CURR = [];
/** بابەتەکانی پرۆگرامی پۆلەکە یەکەم دێن، لەگەڵ ژمارەی وانە ماوەکانیان. */
async function fillLessonSubjects(selected){
  LS_CURR = (await api('curriculum_list', null, '&class_id='+$('lsClass').value)).data;
  const inCurr = LS_CURR.map(c=>{
    const s = byId(CACHE.subjects, c.subject_id);
    const left = c.periods_per_week - c.placed;
    return `<option value="${c.subject_id}" data-group="${esc(c.group_name)}">${c.joint_group?'🔗 ':''}${esc(s?.name)}${c.group_name?' ('+esc(c.group_name)+')':''} — ${left>0?left+' ماوە':'تەواوە'}</option>`;
  }).join('');
  const others = CACHE.subjects.filter(s=>!LS_CURR.some(c=>c.subject_id==s.id))
    .map(s=>`<option value="${s.id}">${esc(s.name)}</option>`).join('');
  $('lsSubject').innerHTML = inCurr
    ? `<optgroup label="پرۆگرامی ئەم پۆلە">${inCurr}</optgroup>${others?`<optgroup label="بابەتەکانی تر">${others}</optgroup>`:''}`
    : others;
  if (selected) {
    const g = $('lsGroup').value;
    const opt = [...$('lsSubject').options].find(o=>o.value==selected && (o.dataset.group??'')===g)
             || [...$('lsSubject').options].find(o=>o.value==selected);
    if (opt) opt.selected = true;
  } else {
    // یەکەم بابەت کە هێشتا وانەی ماوە
    const firstLeft = LS_CURR.findIndex(c=>c.periods_per_week > c.placed);
    if (firstLeft >= 0) $('lsSubject').selectedIndex = firstLeft;
  }
}
function fillLessonTeachers(selected){
  $('lsTeacher').innerHTML = teacherOptions($('lsSubject').value, selected);
}
/** گرووپ، مامۆستا و ژووری پرۆگرامەکە بە خۆکاری پڕ دەکرێنەوە. */
let LS_JOINT = '';
function showJoint(joint, classes){
  LS_JOINT = joint || '';
  $('lsJointInfo').classList.toggle('hidden', !LS_JOINT);
  if (LS_JOINT) $('lsJointInfo').textContent = `🔗 وانەی هاوبەش — بۆ هەموو ئەم پۆلانە پێکەوە دادەنرێت: ${classes.join('، ')}`;
}
function applyPlanned(){
  const opt = $('lsSubject').selectedOptions[0];
  const c = LS_CURR.find(x=>x.subject_id==$('lsSubject').value && x.group_name===(opt?.dataset.group ?? ''));
  if (!editing.id) showJoint(c?.joint_group, c?.joint_classes || []);
  if (c){
    $('lsGroup').value = c.group_name;
    if (c.room_id) $('lsRoom').value = c.room_id;
  }
  const keep = $('ttView').value==='teacher' && !editing.id ? $('ttEntity').value : '';
  fillLessonTeachers(keep || c?.teacher_id || '');
}
$('lsClass').onchange = ()=>fillLessonSubjects().then(applyPlanned);
$('lsSubject').onchange = applyPlanned;

function lessonBody(){
  return {
    id: editing.id, class_id: $('lsClass').value, subject_id: $('lsSubject').value,
    teacher_id: $('lsTeacher').value, room_id: $('lsRoom').value, group_name: $('lsGroup').value,
    joint_group: editing.id ? undefined : LS_JOINT,
    day_of_week: editing.day, period_no: editing.period,
    locked: $('lsLocked').checked ? 1 : 0, double: $('lsDouble').checked,
  };
}
$('btnSuggest').onclick = async ()=>{
  const r = await api('timetable_suggest', lessonBody());
  const box = $('suggestBox');
  if (!r.data.length){ box.innerHTML='<span class="hint">هیچ مامۆستایەکی بەردەست نییە (تێکهەڵچوونیان هەیە، بەتاڵن، یان میلاکیان پڕە).</span>'; return; }
  box.innerHTML = r.data.map(t=>
    `<span class="suggest-chip ${t.planned?'planned':''}" data-id="${t.id}">${t.planned?'★ ':''}${esc(t.name)} <span class="rem">(${t.remaining} ماوە)</span></span>`
  ).join('');
};
$('suggestBox').onclick = e=>{
  const id = e.target.closest('[data-id]')?.dataset.id; if (!id) return;
  $('lsTeacher').value = id; hideErr('lessonError');
};
$('btnSaveLesson').onclick = async ()=>{
  const b = lessonBody();
  if (!b.subject_id) return showErr('lessonError', 'بابەت هەڵبژێرە.');
  if (!b.teacher_id) return showErr('lessonError', 'مامۆستا هەڵبژێرە.');
  try {
    const r = await api(editing.id ? 'timetable_update' : 'timetable_assign', b);
    closeModal('lessonModal');
    toast(r.warning || (editing.id ? 'وانە نوێکرایەوە' : 'وانە دانرا'), r.warning ? 'warn' : 'ok');
    refreshAfterChange();
  } catch (e){ showErr('lessonError', e.message); }
};
$('btnDeleteLesson').onclick = async ()=>{
  await api('timetable_remove', {id: editing.id});
  closeModal('lessonModal');
  toast('وانە سڕایەوە');
  refreshAfterChange();
};

async function refreshAfterChange(){
  await renderTimetable();
  loadTeachers();
  updateUndo();
}

// --- گەڕانەوە ---
async function updateUndo(){
  const info = (await api('undo_info')).data;
  $('btnUndo').disabled = !info;
  $('btnUndo').title = info ? `گەڕانەوەی: ${info.label} (${info.created_at})` : '';
}
$('btnUndo').onclick = async ()=>{
  const r = await api('undo', {});
  toast(`گەڕێندرایەوە: ${r.label}`);
  refreshAfterChange();
};
document.addEventListener('keydown', e=>{
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='z' && !e.target.matches('input,textarea')
      && !$('btnUndo').disabled && $('tab-timetable').classList.contains('active')) {
    e.preventDefault(); $('btnUndo').click();
  }
});

// --- دروستکردنی ئۆتۆماتیکی ---
$('btnGenerate').onclick = ()=>{
  openForm('دروستکردنی ئۆتۆماتیکی خشتە', `
    <p>سیستەمەکە بەپێی <b>پرۆگرامی خوێندنی هەر پۆلێک</b> (لە تابی پۆلەکان) وانەکان دادەنێت و ڕێز لە
    کاتی بەتاڵ، میلاک، سنووری ڕۆژانە، ژوور، گرووپ و وانەی هاوبەش دەگرێت. پاشان خشتەکە <b>باشتر دەکات</b>:
    بۆشایی مامۆستا و پۆل کەم دەکاتەوە، بابەتی قورس دەباتە سەرەتای ڕۆژ، و پەیوەندی بابەتەکان جێبەجێ دەکات.</p>
    <label>ماوەی هەوڵدان (کاتی زیاتر = خشتەی باشتر)
      <select id="fSecs"><option value="5">خێرا — ٥ چرکە</option><option value="15" selected>ئاسایی — ١٥ چرکە</option>
      <option value="40">ورد — ٤٠ چرکە</option><option value="90">زۆر ورد — ٩٠ چرکە (قوتابخانەی گەورە)</option></select></label>
    <label class="check"><input type="checkbox" id="fRegen" checked>
      وانە ئۆتۆماتیکییە کۆنەکان بسڕەوە و لە سەرەتاوە دروستی بکەرەوە (وانە 🔒 قوفڵکراوەکان دەمێننەوە)</label>
    <p class="hint">ئەگەر ئەم بژاردەیە نەکرێت، تەنها وانە ماوەکان زیاد دەکرێن. هەمیشە دەتوانیت بە «↶ گەڕانەوە» بگەڕێیتەوە.</p>`,
  async ()=>{
    const regenerate = $('fRegen').checked;
    const seconds = parseInt($('fSecs').value);
    closeModal('formModal');
    $('ttProgress').classList.remove('hidden');
    $('btnGenerate').disabled = true;
    // پیشاندانی پێشکەوتن بەپێی کات
    const t0 = Date.now();
    const tick = setInterval(()=>{
      const pct = Math.min(99, Math.round((Date.now()-t0) / (seconds*10)));
      $('ttProgress').innerHTML = `⏳ خشتە دروست دەکرێت و باشتر دەکرێت... ${pct}%<div class="pbar"><i style="width:${pct}%"></i></div>`;
    }, 300);
    try {
      const r = (await api('timetable_generate', {regenerate, seconds})).data;
      showGenerateResult(r);
    } finally {
      clearInterval(tick);
      $('ttProgress').classList.add('hidden');
      $('btnGenerate').disabled = false;
      refreshAfterChange();
    }
    return false;
  }, 'دەستپێکردن');
};
function showGenerateResult(r){
  if (!r.total && !r.messages.length){
    return toast('هیچ وانەیەک بۆ دانان نییە — سەرەتا پرۆگرامی خوێندنی پۆلەکان پڕ بکەرەوە.', 'warn');
  }
  const ok = r.placed === r.total && !r.messages.length;
  openForm(ok ? 'خشتەکە بە تەواوی دروست کرا ✓' : 'خشتەکە بە بەشێکی دروست کرا', `
    <p>${r.placed} لە ${r.total} یەکەی وانە دانران.</p>
    ${qualityCompare(r.before, r.after)}
    ${r.messages.length ? `<ul class="issues-list">${r.messages.map(m=>`<li>${esc(m)}</li>`).join('')}</ul>
      <p class="hint">بۆ چارەسەر: کاتی بەتاڵی مامۆستاکان کەم بکەرەوە، میلاک زیاد بکە، مامۆستای تر دیاری بکە،
      یان وانە ماوەکان بە دەست دابنێ.</p>` : ''}`,
    async ()=>true, 'باشە');
}

function qualityCompare(b, a){
  if (!a) return '';
  if (b && !b.lessons) b = null; // خشتەکە پێشتر بەتاڵ بوو
  const row = (label, k) => `<tr><td>${label}</td><td>${b?.[k] ?? '—'}</td><td><b>${a[k]}</b></td></tr>`;
  return `<table class="grid compact"><tr><th>کوالێتی</th><th>پێشتر</th><th>ئێستا</th></tr>
    ${row('بۆشایی مامۆستایان', 'teacher_gaps')}${row('بۆشایی پۆلەکان', 'class_gaps')}
    ${row('بابەت لە کاتی نەگونجاودا', 'time_misses')}${row('پەیوەندی بابەتی جێبەجێنەکراو', 'relation_miss')}
    ${row('کۆی خاڵی سزا (کەمتر باشترە)', 'score')}</table>`;
}

// --- پاککردنەوە ---
$('btnClear').onclick = ()=>{
  const isClass = $('ttView').value==='class' && $('ttEntity').value;
  const name = isClass ? $('ttEntity').selectedOptions[0].text : '';
  openForm('پاککردنەوەی خشتە', `
    ${isClass ? `<label class="check"><input type="radio" name="fScope" value="class" checked> تەنها پۆلی «${esc(name)}»</label>` : ''}
    <label class="check"><input type="radio" name="fScope" value="all" ${isClass?'':'checked'}> هەموو پۆلەکان</label>
    <label class="check"><input type="checkbox" id="fOnlyAuto"> تەنها وانە ئۆتۆماتیکییەکان (قوفڵکراوەکان بمێننەوە)</label>`,
  async ()=>{
    const scope = document.querySelector('[name=fScope]:checked').value;
    const r = await api('timetable_clear', {scope, class_id: $('ttEntity').value, only_auto: $('fOnlyAuto').checked});
    toast(`${r.deleted} وانە سڕایەوە — بە «↶ گەڕانەوە» دەتوانیت بیگەڕێنیتەوە`);
    refreshAfterChange();
  }, 'سڕینەوە');
};

// --- هەناردە بۆ Excel ---
$('btnExport').onclick = ()=>{
  openForm('هەناردەکردن بۆ Excel', `
    <label class="check"><input type="radio" name="fExp" value="class" checked> خشتەی هەموو پۆلەکان</label>
    <label class="check"><input type="radio" name="fExp" value="teacher"> خشتەی هەموو مامۆستایان</label>
    <label class="check"><input type="radio" name="fExp" value="room"> خشتەی هەموو ژوورەکان</label>`,
  async ()=>{
    const v = document.querySelector('[name=fExp]:checked').value;
    location.href = `api/index.php?action=export_csv&view=${v}`;
  }, 'داگرتن');
};

// --- چاپ / PDF ---
$('btnPrint').onclick = ()=>{
  const cur = $('ttEntity').selectedOptions[0]?.text || '';
  openForm('چاپکردن', `
    <label class="check"><input type="radio" name="fPr" value="current" checked> تەنها ئەم خشتەیە (${esc(cur)})</label>
    <label class="check"><input type="radio" name="fPr" value="class"> هەموو پۆلەکان (هەر پۆلێک لە پەڕەیەک)</label>
    <label class="check"><input type="radio" name="fPr" value="teacher"> هەموو مامۆستایان</label>
    <label class="check"><input type="radio" name="fPr" value="room"> هەموو ژوورەکان</label>
    <p class="hint">بۆ PDF: لە پەنجەرەی چاپدا «Save as PDF» هەڵبژێرە.</p>`,
  async ()=>{
    const which = document.querySelector('[name=fPr]:checked').value;
    await printTimetables(which);
  }, 'چاپ');
};
async function printTimetables(which){
  const view = which==='current' ? $('ttView').value : which;
  const all = (await api('timetable_get', null, '&view=all')).data;
  const fk = {class:'class_id', teacher:'teacher_id', room:'room_id'}[view];
  const label = {class:'پۆلی', teacher:'مامۆستا', room:'ژووری'}[view];
  let list = VIEW_SOURCES[view]();
  if (which==='current') list = list.filter(x=>String(x.id)===$('ttEntity').value);
  $('printArea').innerHTML = list.map(e=>`<div class="print-page">
      <div class="print-head"><span>${esc(CFG.school_name)}</span><b>خشتەی هەفتانەی ${label} ${esc(e.name)}</b></div>
      ${gridHtml(all.filter(x=>String(x[fk])===String(e.id)), view, false)}
    </div>`).join('');
  closeModal('formModal');
  setTimeout(()=>window.print(), 50);
}

// ============ ڕاپۆرت ============
async function loadReport(){
  const [v, r, q] = await Promise.all([api('validate'), api('load_report'), api('quality')]);
  const Q = q.data;
  $('qualityBox').innerHTML = `<div class="stats">
      <div><b>${Q.teacher_gaps}</b><span>بۆشایی مامۆستایان</span></div>
      <div><b>${Q.class_gaps}</b><span>بۆشایی پۆلەکان</span></div>
      <div><b>${Q.time_misses}</b><span>بابەت لە کاتی نەگونجاو</span></div>
      <div><b>${Q.relation_miss}</b><span>پەیوەندی جێبەجێنەکراو</span></div>
      <div><b>${Q.score}</b><span>خاڵی سزا (کەمتر باشترە)</span></div></div>
    ${Q.issues.length ? `<details><summary>${Q.issues.length} خاڵی باشترکردن</summary><ul class="issues-list">${Q.issues.slice(0,200).map(i=>`<li>${esc(i)}</li>`).join('')}</ul></details>` : ''}
    ${Q.teachers.some(t=>t.gaps) ? `<table class="grid compact"><tr><th>مامۆستا</th><th>وانە</th><th>بۆشایی لە هەفتەدا</th></tr>
      ${Q.teachers.filter(t=>t.gaps).map(t=>`<tr><td>${esc(t.name)}</td><td>${t.lessons}</td><td>${t.gaps}</td></tr>`).join('')}</table>` : ''}`;
  $('validateBox').innerHTML = v.data.length
    ? `<ul class="issues-list">${v.data.map(i=>`<li class="${i.level}">${i.level==='error'?'⛔':'⚠'} ${esc(i.text)}</li>`).join('')}</ul>`
    : '<p class="ok-box">✓ هیچ کێشەیەک نەدۆزرایەوە.</p>';
  $('rTable').innerHTML =
    `<tr><th>مامۆستا</th><th>میلاک</th><th>دراوە</th><th>ماوە</th><th>دۆخ</th></tr>` +
    r.data.map(x=>{
      const st = x.status==='over' ? '<span class="badge over">میلاک تێپەڕاندووە</span>'
               : x.status==='full'? '<span class="badge full">تەواو</span>'
               : '<span class="badge under">وانەی کەمتری هەیە</span>';
      return `<tr><td>${esc(x.full_name)}</td><td>${x.max_periods}</td>
        <td>${x.assigned}</td><td>${x.remaining}</td><td>${st}</td></tr>`;
    }).join('');
}

// ============ ڕێگرییەکان ============
const WEIGHTS = [
  ['w_teacher_gaps', 'کەمکردنەوەی بۆشایی مامۆستا'],
  ['w_class_gaps',   'پۆل بێ بۆشایی بێت و لە بەشە وانەی ١ دەست پێبکات'],
  ['w_subject_time', 'کاتی باشتری بابەت (قورس لە سەرەتا...)'],
  ['w_relations',    'پەیوەندی نێوان بابەتەکان'],
  ['w_min_per_day',  'کەمترین وانەی ڕۆژانەی مامۆستا'],
];
async function loadConstraints(){
  const c = (await api('constraints_get')).data;
  $('cMaxGaps').value = c.max_teacher_gaps;
  $('cMaxCons').value = c.max_consecutive;
  const lv = {0:'ناچالاک', 1:'ئاسایی', 3:'گرنگ', 6:'زۆر گرنگ'};
  $('cWeights').innerHTML = WEIGHTS.map(([k, label])=>`<label class="inline">${label}
    <select data-w="${k}">${Object.entries(lv).map(([v,t])=>`<option value="${v}" ${c[k]==v?'selected':''}>${t}</option>`).join('')}</select></label>`).join('');
}
$('btnSaveCons').onclick = async ()=>{
  const body = { max_teacher_gaps: $('cMaxGaps').value, max_consecutive: $('cMaxCons').value };
  document.querySelectorAll('[data-w]').forEach(x=> body[x.dataset.w] = x.value);
  await api('constraints_save', body);
  toast('ڕێگرییەکان پاشەکەوت کران');
};

// ============ هێنانی داتا ============
async function uploadImport(action, file, extra={}){
  const fd = new FormData(); fd.append('file', file);
  Object.entries(extra).forEach(([k,v])=>fd.append(k, v));
  return (await api(action, fd)).data;
}
$('ascFile').onchange = async e=>{
  const file = e.target.files[0]; e.target.value = '';
  if (!file) return;
  if (!await askConfirm(`هەموو داتای ئێستا (مامۆستا، پۆل، بابەت، خشتە) بە داتای «${file.name}» دەگۆڕدرێت. پێشتر پاڵپشتی دەگیرێت. بەردەوام بیت؟`)) return;
  const r = await uploadImport('import_asc', file, $('ascCards').checked ? {with_cards:1} : {});
  await reloadAll();
  openForm('هێنان لە aSc تەواو بوو ✓', `<ul class="issues-list">
    <li>${r.teachers} مامۆستا، ${r.subjects} بابەت، ${r.classes} پۆل، ${r.rooms} ژوور</li>
    <li>${r.curriculum} بابەتی پرۆگرامی خوێندن</li><li>${r.lessons} وانەی دانراو لە خشتەدا</li></ul>
    <p class="hint">کاتی بەتاڵی مامۆستاکان لە aSc ـەوە ناهێنرێت؛ لە تابی مامۆستایان دیاریان بکە.</p>`, async ()=>true, 'باشە');
};
$('csvFile').onchange = async e=>{
  const file = e.target.files[0]; e.target.value = '';
  if (!file) return;
  const r = await uploadImport('import_csv', file);
  await reloadAll();
  openForm('هێنان لە Excel تەواو بوو', `<p>${r.added} بابەتی نوێ زیادکرا، ${r.updated} نوێکرایەوە.</p>
    ${r.errors.length ? `<ul class="issues-list">${r.errors.map(x=>`<li>${esc(x)}</li>`).join('')}</ul>` : ''}`, async ()=>true, 'باشە');
};
$('btnPublish').onclick = ()=>{
  openForm('بڵاوکردنەوە بۆ مۆبایل', `<p>فایلێکی بچووک (<code>timetable-mobile.html</code>) دروست دەکرێت کە خشتەی هەموو پۆل و مامۆستایانی تێدایە.</p>
    <ul class="issues-list"><li>بە WhatsApp، Telegram یان Viber بینێرە بۆ مامۆستایان و قوتابیان</li>
    <li>لە هەر مۆبایل و کۆمپیوتەرێکدا بەبێ ئینتەرنێت دەکرێتەوە</li>
    <li>هەر کەسێک پۆل یان ناوی خۆی هەڵدەبژێرێت</li></ul>`,
  async ()=>{ location.href = 'api/index.php?action=publish_html'; }, '⬇ دروستکردن');
};

// ============ مامۆستای جێگرەوە ============
function todayStr(){ const d = new Date(); return new Date(d - d.getTimezoneOffset()*60000).toISOString().slice(0,10); }
function initSubs(){
  if (!$('subDate').value) $('subDate').value = todayStr();
  const prev = $('subTeacher').value;
  $('subTeacher').innerHTML = opts(CACHE.teachers, t=>t.full_name, prev);
  loadSubList();
}
$('subDate').onchange = ()=>{ $('subBox').innerHTML=''; loadSubList(); };
let SUB_DAY = null;
$('btnSubLoad').onclick = async ()=>{
  const date = $('subDate').value, tid = $('subTeacher').value;
  try { SUB_DAY = (await api('subs_day', null, `&date=${date}&teacher_id=${tid}`)).data; }
  catch (e){ $('subBox').innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }
  if (!SUB_DAY.lessons.length){ $('subBox').innerHTML = `<p class="ok-box">ئەم مامۆستایە ڕۆژی ${esc(SUB_DAY.day_name)} هیچ وانەیەکی نییە.</p>`; return; }
  $('subBox').innerHTML = `<h3>${esc(SUB_DAY.day_name)} — ${esc($('subTeacher').selectedOptions[0].text)}</h3>
    <table class="grid"><tr><th>بەشە وانە</th><th>پۆل</th><th>بابەت</th><th>مامۆستای جێگرەوە</th><th></th></tr>` +
    SUB_DAY.lessons.map((l,i)=>`<tr><td>${l.period_no}</td><td>${esc(l.class_name)}${l.group_name?' ('+esc(l.group_name)+')':''}</td><td>${esc(l.subject_name)}</td>
      <td><select data-sub="${i}"><option value="">— هیچ (پشوو) —</option>${l.candidates.map(c=>
        `<option value="${c.id}" ${l.substitution?.substitute_id==c.id?'selected':''}>${c.same_subject?'★ ':''}${esc(c.name)} (ئەمڕۆ ${c.today} وانە)</option>`).join('')}</select></td>
      <td>${l.substitution ? '<span class="badge full">پاشەکەوتکراو</span>' : ''}</td></tr>`).join('') + `</table>
    <p class="hint">★ = هەمان بابەت دەڵێتەوە. تەنها ئەو مامۆستایانە پیشان دەدرێن کە لەو کاتەدا ئازادن.</p>`;
};
$('subBox').addEventListener('change', async e=>{
  const i = e.target.dataset.sub; if (i===undefined) return;
  const l = SUB_DAY.lessons[i];
  await api('subs_save', { date: $('subDate').value, absent_id: $('subTeacher').value, period_no: l.period_no,
    class_ids: l.class_ids, subject_id: l.subject_id, substitute_id: e.target.value });
  toast('پاشەکەوت کرا');
  $('btnSubLoad').click(); loadSubList();
});
let SUB_LIST = [];
async function loadSubList(){
  SUB_LIST = (await api('subs_list', null, '&date='+$('subDate').value)).data;
  $('subList').innerHTML = `<tr><th>بەشە وانە</th><th>پۆل</th><th>بابەت</th><th>مامۆستای ئامادەنەبوو</th><th>جێگرەوە</th><th></th></tr>` +
    (SUB_LIST.length ? SUB_LIST.map(x=>`<tr><td>${x.period_no}</td><td>${esc(x.class_name)}</td><td>${esc(x.subject_name)}</td>
      <td>${esc(x.absent_name)}</td><td>${x.substitute_name ? esc(x.substitute_name) : '<span class="hint">پشوو</span>'}</td>
      <td><button class="icon-btn" data-del="${x.id}">🗑</button></td></tr>`).join('')
    : '<tr><td colspan="6" class="hint">هیچ جێگرەوەیەک بۆ ئەم ڕۆژە دیاری نەکراوە.</td></tr>');
}
$('subList').onclick = async e=>{
  const id = e.target.closest('[data-del]')?.dataset.del; if (!id) return;
  await api('subs_delete', {id}); loadSubList();
};
$('btnSubPrint').onclick = ()=>{
  $('printArea').innerHTML = `<div class="print-page"><div class="print-head"><span>${esc(CFG.school_name)}</span>
    <b>مامۆستایانی جێگرەوە — ${esc($('subDate').value)}</b></div>
    <table class="tt-table"><tr><th>بەشە وانە</th><th>پۆل</th><th>بابەت</th><th>مامۆستای ئامادەنەبوو</th><th>جێگرەوە</th><th>واژوو</th></tr>
    ${SUB_LIST.map(x=>`<tr><td>${x.period_no}</td><td>${esc(x.class_name)}</td><td>${esc(x.subject_name)}</td><td>${esc(x.absent_name)}</td>
      <td>${esc(x.substitute_name||'پشوو')}</td><td></td></tr>`).join('')}</table></div>`;
  setTimeout(()=>window.print(), 50);
};

// --- دەستپێک ---
async function reloadAll(){
  await loadSettings();
  await loadTeachers();
  await Promise.all([loadSubjects(), loadRooms()]);
  await loadClasses();
  renderTeachers();
  await renderTimetable();
  updateUndo();
}
reloadAll();
