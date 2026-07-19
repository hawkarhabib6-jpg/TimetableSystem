// ============================================================
// سیستەمی خشتەی هەفتانە — کۆدی پێشەوە
// ============================================================
const DAYS = ['یەکشەممە','دووشەممە','سێشەممە','چوارشەممە','پێنجشەممە'];
const PERIODS = 6;

let CACHE = { teachers:[], subjects:[], classes:[] };
let currentCell = null; // {day, period}

// --- API helper ---
async function api(action, method='GET', body=null){
  const opt = { method };
  if (body){ opt.headers = {'Content-Type':'application/json'}; opt.body = JSON.stringify(body); }
  const res = await fetch(`api/index.php?action=${action}`, opt);
  return res.json();
}

function toast(msg, kind='ok'){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast ' + kind;
  setTimeout(()=>{ t.className = 'toast hidden'; }, 3500);
}

function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// --- confirm ی دەستکرد (چونکە دیالۆگی ناوزەدی Chrome لە دێسکتۆپدا کار ناکات) ---
function askConfirm(message){
  return new Promise(resolve=>{
    const ov = document.createElement('div');
    ov.className = 'modal';
    ov.innerHTML = `<div class="modal-box">
      <h3>دڵنیابوونەوە</h3>
      <p style="margin:10px 0 4px">${esc(message)}</p>
      <div class="modal-actions">
        <button class="primary" id="cfYes">بەڵێ</button>
        <button id="cfNo">نەخێر</button>
      </div></div>`;
    document.body.appendChild(ov);
    ov.querySelector('#cfYes').onclick = ()=>{ ov.remove(); resolve(true); };
    ov.querySelector('#cfNo').onclick  = ()=>{ ov.remove(); resolve(false); };
  });
}

// --- تابەکان ---
document.querySelectorAll('.tab').forEach(btn=>{
  btn.onclick = ()=>{
    document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-'+btn.dataset.tab).classList.add('active');
    if (btn.dataset.tab==='report') loadReport();
  };
});

// ============ مامۆستایان ============
async function loadTeachers(){
  const r = await api('teachers_list');
  CACHE.teachers = r.data;
  const t = document.getElementById('tTable');
  t.innerHTML = `<tr><th>ناو</th><th>مۆبایل</th><th>میلاک</th><th>دراوە</th><th>ماوە</th><th></th></tr>` +
    r.data.map(x=>`<tr>
      <td>${esc(x.full_name)}</td>
      <td>${esc(x.phone)}</td>
      <td>${x.max_periods}</td>
      <td>${x.assigned}</td>
      <td>${badge(x.remaining)}</td>
      <td>
        <button class="icon-btn" title="ڕۆژە بەتاڵەکان" onclick="openOffdays(${x.id},'${esc(x.full_name)}')">🗓</button>
        <button class="icon-btn" onclick="delTeacher(${x.id})">🗑</button>
      </td>
    </tr>`).join('');
}
function badge(rem){
  if (rem<0) return `<span class="badge over">${rem}</span>`;
  if (rem===0) return `<span class="badge full">پڕ</span>`;
  return `<span class="badge under">${rem}</span>`;
}
async function addTeacher(){
  const full_name = document.getElementById('tName').value.trim();
  const phone = document.getElementById('tPhone').value.trim();
  const max_periods = parseInt(document.getElementById('tMax').value)||22;
  if (!full_name) return toast('ناوی مامۆستا بنووسە','err');
  await api('teacher_add','POST',{full_name,phone,max_periods});
  document.getElementById('tName').value='';
  document.getElementById('tPhone').value='';
  loadTeachers();
  toast('مامۆستا زیادکرا');
}
async function delTeacher(id){
  if (!await askConfirm('ئەم مامۆستایە بسڕدرێتەوە؟ (خشتەکەشی دەسڕێتەوە)')) return;
  await api('teacher_delete','POST',{id});
  loadTeachers();
}

// ============ ڕۆژە بەتاڵەکان ============
let offTeacherId = null;
async function openOffdays(id, name){
  offTeacherId = id;
  document.getElementById('offTitle').textContent = `ڕۆژە بەتاڵەکانی: ${name}`;
  // پڕکردنەوەی لیستەکان
  document.getElementById('offDay').innerHTML =
    DAYS.map((d,i)=>`<option value="${i}">${d}</option>`).join('');
  let popts = '<option value="">هەموو ڕۆژەکە</option>';
  for(let p=1;p<=PERIODS;p++) popts += `<option value="${p}">بەشە وانەی ${p}</option>`;
  document.getElementById('offPeriod').innerHTML = popts;
  document.getElementById('offdaysPanel').classList.remove('hidden');
  loadOffdays();
}
function closeOffdays(){ document.getElementById('offdaysPanel').classList.add('hidden'); offTeacherId=null; }

async function loadOffdays(){
  const r = await api('offdays_list&teacher_id='+offTeacherId);
  const box = document.getElementById('offList');
  if(!r.data.length){ box.innerHTML='<span class="hint">هیچ کاتێکی بەتاڵ زیاد نەکراوە.</span>'; return; }
  box.innerHTML = r.data.map(o=>{
    const label = o.period_no ? `${DAYS[o.day_of_week]} — بەشە وانەی ${o.period_no}`
                              : `${DAYS[o.day_of_week]} — هەموو ڕۆژەکە`;
    return `<span class="off-chip">${label}
      <b onclick="delOffday(${o.id})">✕</b></span>`;
  }).join('');
}
async function addOffday(){
  const day_of_week = parseInt(document.getElementById('offDay').value);
  const period_no = document.getElementById('offPeriod').value;
  await api('offday_add','POST',{teacher_id:offTeacherId, day_of_week, period_no});
  loadOffdays();
  toast('کاتی بەتاڵ زیادکرا');
}
async function delOffday(id){
  await api('offday_delete','POST',{id});
  loadOffdays();
}

// ============ بابەتەکان ============
async function loadSubjects(){
  const r = await api('subjects_list');
  CACHE.subjects = r.data;
  document.getElementById('sTable').innerHTML =
    `<tr><th>ناوی بابەت</th><th></th></tr>` +
    r.data.map(x=>`<tr><td>${esc(x.name)}</td>
      <td><button class="icon-btn" onclick="delSubject(${x.id})">🗑</button></td></tr>`).join('');
}
async function addSubject(){
  const name = document.getElementById('sName').value.trim();
  if (!name) return toast('ناوی بابەت بنووسە','err');
  await api('subject_add','POST',{name});
  document.getElementById('sName').value='';
  loadSubjects();
  toast('بابەت زیادکرا');
}
async function delSubject(id){
  if(!await askConfirm('بسڕدرێتەوە؟')) return;
  await api('subject_delete','POST',{id}); loadSubjects();
}

// ============ پۆلەکان ============
async function loadClasses(){
  const r = await api('classes_list');
  CACHE.classes = r.data;
  document.getElementById('cTable').innerHTML =
    `<tr><th>ناوی پۆل</th><th>ئاست</th><th></th></tr>` +
    r.data.map(x=>`<tr><td>${esc(x.name)}</td><td>${x.grade_level??''}</td>
      <td><button class="icon-btn" onclick="delClass(${x.id})">🗑</button></td></tr>`).join('');
  // نوێکردنەوەی لیستی پۆل لە خشتەدا
  const sel = document.getElementById('ttClass');
  const prev = sel.value;
  sel.innerHTML = r.data.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
  if (prev) sel.value = prev;
  if (sel.value) renderTimetable();
}
async function addClass(){
  const name = document.getElementById('cName').value.trim();
  const grade_level = parseInt(document.getElementById('cGrade').value)||0;
  if (!name) return toast('ناوی پۆل بنووسە','err');
  await api('class_add','POST',{name,grade_level});
  document.getElementById('cName').value='';
  document.getElementById('cGrade').value='';
  loadClasses();
  toast('پۆل زیادکرا');
}
async function delClass(id){
  if(!await askConfirm('بسڕدرێتەوە؟ (خشتەکەشی دەسڕێتەوە)')) return;
  await api('class_delete','POST',{id}); loadClasses();
}

// ============ خشتەی هەفتانە ============
document.getElementById('ttClass').onchange = renderTimetable;

async function renderTimetable(){
  const class_id = document.getElementById('ttClass').value;
  if (!class_id){ document.getElementById('ttGridWrap').innerHTML='<p class="hint">سەرەتا پۆلێک زیاد بکە.</p>'; return; }

  const r = await api('timetable_get&class_id='+class_id);
  const map = {}; // "day-period" -> row
  r.data.forEach(x=> map[`${x.day_of_week}-${x.period_no}`] = x);

  let html = '<table class="tt-table"><tr><th>بەشە وانە</th>';
  DAYS.forEach(d=> html += `<th>${d}</th>`);
  html += '</tr>';

  for (let p=1; p<=PERIODS; p++){
    html += `<tr><td class="periodhead">${p}</td>`;
    for (let d=0; d<DAYS.length; d++){
      const cell = map[`${d}-${p}`];
      if (cell){
        html += `<td><div class="tt-cell filled" onclick="removeCell(${cell.id})">
          <div class="subj">${esc(cell.subject_name)}</div>
          <div class="teach">${esc(cell.teacher_name)}</div>
          <div class="rm">✕ سڕینەوە</div>
        </div></td>`;
      } else {
        html += `<td><div class="tt-cell empty" onclick="openAssign(${d},${p})">+</div></td>`;
      }
    }
    html += '</tr>';
  }
  html += '</table>';
  document.getElementById('ttGridWrap').innerHTML = html;

  const filled = r.data.length;
  document.getElementById('ttHint').textContent = `پڕکراوە: ${filled} / ${PERIODS*DAYS.length} خانە`;
}

// --- دیالۆگی دانان ---
function openAssign(day, period){
  currentCell = {day, period};
  document.getElementById('assignTitle').textContent =
    `دانانی وانە — ${DAYS[day]}، بەشە وانەی ${period}`;
  const subSel = document.getElementById('asSubject');
  subSel.innerHTML = CACHE.subjects.map(s=>`<option value="${s.id}">${esc(s.name)}</option>`).join('');
  const teachSel = document.getElementById('asTeacher');
  teachSel.innerHTML = CACHE.teachers.map(t=>`<option value="${t.id}">${esc(t.full_name)}</option>`).join('');
  document.getElementById('suggestBox').innerHTML='';
  hideError();
  document.getElementById('assignModal').classList.remove('hidden');
}
function closeModal(){ document.getElementById('assignModal').classList.add('hidden'); }
function showError(msg){ const e=document.getElementById('assignError'); e.textContent=msg; e.classList.remove('hidden'); }
function hideError(){ document.getElementById('assignError').classList.add('hidden'); }

async function suggestTeachers(){
  const class_id = document.getElementById('ttClass').value;
  const subject_id = document.getElementById('asSubject').value;
  const r = await api('timetable_suggest','POST',{
    class_id, subject_id, day_of_week:currentCell.day, period_no:currentCell.period });
  const box = document.getElementById('suggestBox');
  if (!r.data.length){ box.innerHTML='<span class="hint">هیچ مامۆستایەکی بەردەست نییە (هەموویان تێکهەڵچوونیان هەیە یان میلاکیان پڕە).</span>'; return; }
  box.innerHTML = r.data.map(t=>
    `<span class="suggest-chip" onclick="pickTeacher(${t.id})">${esc(t.name)} <span class="rem">(${t.remaining} ماوە)</span></span>`
  ).join('');
}
function pickTeacher(id){
  document.getElementById('asTeacher').value = id;
  hideError();
}

async function doAssign(){
  const class_id = document.getElementById('ttClass').value;
  const subject_id = document.getElementById('asSubject').value;
  const teacher_id = document.getElementById('asTeacher').value;
  if (!subject_id) return showError('بابەت هەڵبژێرە.');
  if (!teacher_id) return showError('مامۆستا هەڵبژێرە.');

  const r = await api('timetable_assign','POST',{
    class_id, subject_id, teacher_id,
    day_of_week:currentCell.day, period_no:currentCell.period });

  if (!r.ok){ showError(r.error); return; }
  closeModal();
  renderTimetable();
  loadTeachers();
  if (r.warning) toast(r.warning,'warn');
  else toast('وانە دانرا');
}

async function removeCell(id){
  if(!await askConfirm('ئەم وانەیە بسڕدرێتەوە؟')) return;
  await api('timetable_remove','POST',{id});
  renderTimetable();
  loadTeachers();
}

// ============ ڕاپۆرت ============
async function loadReport(){
  const r = await api('load_report');
  document.getElementById('rTable').innerHTML =
    `<tr><th>مامۆستا</th><th>میلاک</th><th>دراوە</th><th>ماوە</th><th>دۆخ</th></tr>` +
    r.data.map(x=>{
      let st = x.status==='over' ? '<span class="badge over">میلاک تێپەڕاندووە</span>'
             : x.status==='full'? '<span class="badge full">تەواو</span>'
             : '<span class="badge under">وانەی کەمتری هەیە</span>';
      return `<tr><td>${esc(x.full_name)}</td><td>${x.max_periods}</td>
        <td>${x.assigned}</td><td>${x.remaining}</td><td>${st}</td></tr>`;
    }).join('');
}

// --- دەستپێک ---
(async function init(){
  await loadTeachers();
  await loadSubjects();
  await loadClasses();
})();
