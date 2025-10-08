<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = \App\Security\Csrf::token();
$SITE_ASSETS = '../site/assets';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>KW Admin — Weeks</title>
  <link rel="icon" href="../favicon.ico" />
  <link rel="stylesheet" href="../public/assets/css/app.css" />
  <style>
    body { background:#0b1020; color:#e6ecff; font:14px system-ui,-apple-system,"Segoe UI",Roboto,Arial; }
    .wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; margin-bottom:16px; }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    label input, select { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; }
    h1 { font-size: 20px; font-weight: 800; margin: 0; }
    .muted { color: rgba(230,236,255,0.7); font-size: 12px; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border-top:1px solid rgba(255,255,255,0.08); text-align:left; }
    .nav { display:flex; flex-wrap:wrap; gap:8px; margin-bottom: 12px; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; border:1px solid rgba(255,255,255,0.15); font-size:12px; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="kicker">Kings Walk Week</div>
        <h1>Weeks</h1>
      </div>
      <div class="nav">
        <a class="btn" href="index.php">Home</a>
        <a class="btn" href="entries.php">Entries</a>
        <a class="btn" href="users.php">Users</a>
        <a class="btn" href="ai.php">AI</a>
        <a class="btn" href="../site/">Dashboard</a>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px">Create Week</h2>
    <div class="row">
      <label>Date (YYYY-MM-DD): <input id="newWeekDate" placeholder="2025-10-19"></label>
      <label>Label: <input id="newWeekLabel" placeholder="Oct 19–25"></label>
      <button class="btn" id="createWeekBtn">Create/Update</button>
    </div>
    <div id="createStatus" class="muted"></div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px">All Weeks</h2>
    <div class="row" style="margin-bottom:8px">
      <label><input type="checkbox" id="forceDelete"> Delete cascades entries</label>
      <span class="muted" id="weeksStatus"></span>
    </div>
    <table id="weeksTable">
      <thead>
        <tr><th>Date</th><th>Label</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const base = '../';
  const CSRF = "<?= htmlspecialchars($csrf) ?>";
  const tBody = document.querySelector('#weeksTable tbody');
  const status = document.getElementById('weeksStatus');

  function btn(txt, cls){ const b=document.createElement('button'); b.textContent=txt; b.className='btn'+(cls?' '+cls:''); return b; }

  async function listWeeks(){
    status.textContent = 'Loading…';
    tBody.innerHTML = '';
    try {
      const r = await fetch(base+'api/weeks.php', { cache:'no-store' });
      const j = await r.json();
      const weeks = Array.isArray(j.weeks) ? j.weeks : [];
      status.textContent = `${weeks.length} week(s)`;
      weeks.forEach(w => {
        const tr = document.createElement('tr');
        const tdDate = document.createElement('td'); tdDate.textContent = w.starts_on;
        const tdLabel= document.createElement('td'); tdLabel.textContent = w.label || w.starts_on;
        const tdStatus=document.createElement('td'); tdStatus.innerHTML = w.finalized ? '<span class="badge">finalized</span>' : '<span class="badge">open</span>';
        const tdAct  = document.createElement('td');
        const row = w.starts_on;
        const link = document.createElement('a'); link.href = 'entries.php?week='+encodeURIComponent(row); link.className='btn'; link.textContent='Open entries';
        const fin = btn('Finalize');
        const unfin = btn('Unfinalize');
        const addAct = btn('Add active');
        const del = btn('Delete','warn');
        fin.onclick = async ()=>{ await finalize(row); };
        unfin.onclick = async ()=>{ await unfinalize(row); };
        addAct.onclick = async ()=>{ await addActive(row); };
        del.onclick = async ()=>{ await delWeek(row); };
        tdAct.append(link, ' ', fin, ' ', unfin, ' ', addAct, ' ', del);
        tr.append(tdDate, tdLabel, tdStatus, tdAct);
        tBody.appendChild(tr);
      });
    } catch(e) { status.textContent = 'Failed to load'; }
  }

  async function finalize(week){
    if (!confirm('Finalize '+week+'?')) return;
    const r = await fetch(base+'api/entries_finalize.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ action:'finalize', week, csrf: CSRF }) });
    if (!r.ok) return alert('Finalize failed');
    await listWeeks();
  }
  async function unfinalize(week){
    if (!confirm('Unfinalize '+week+'?')) return;
    const r = await fetch(base+'api/entries_finalize.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ action:'unfinalize', week, csrf: CSRF }) });
    if (!r.ok) return alert('Unfinalize failed');
    await listWeeks();
  }
  async function addActive(week){
    const r = await fetch(base+'api/entries_add_active.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ week, csrf: CSRF }) });
    if (!r.ok) return alert('Add failed');
    alert('Added active users to '+week);
  }
  async function delWeek(date){
    const force = document.getElementById('forceDelete').checked ? '1' : '0';
    if (!confirm('Delete '+date + (force==='1'?' (cascade)':'') + '?')) return;
    const r = await fetch(base+'api/weeks.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ action:'delete', date, force, csrf: CSRF }) });
    const j = await r.json().catch(()=>null);
    if (!r.ok || !j || j.ok !== true) return alert('Delete failed');
    await listWeeks();
  }

  document.getElementById('createWeekBtn').addEventListener('click', async ()=>{
    const date = (document.getElementById('newWeekDate').value || '').trim();
    const label= (document.getElementById('newWeekLabel').value || '').trim();
    const st = document.getElementById('createStatus');
    if (!date) { alert('Enter YYYY-MM-DD'); return; }
    st.textContent = 'Saving…';
    const r = await fetch(base+'api/weeks.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ action:'create', date, label, csrf: CSRF }) });
    const j = await r.json().catch(()=>null);
    if (!r.ok || !j || j.ok !== true) { st.textContent = 'Save failed'; return; }
    st.textContent = 'Saved';
    await listWeeks();
  });

  listWeeks();
})();
</script>
</body>
</html>
