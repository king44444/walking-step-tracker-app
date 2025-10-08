<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = \App\Security\Csrf::token();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>KW Admin — AI</title>
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
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="kicker">Kings Walk Week</div>
        <h1>AI Console</h1>
      </div>
      <div class="nav">
        <a class="btn" href="index.php">Home</a>
        <a class="btn" href="weeks.php">Weeks</a>
        <a class="btn" href="entries.php">Entries</a>
        <a class="btn" href="users.php">Users</a>
        <a class="btn" href="../site/">Dashboard</a>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px">Settings</h2>
    <div class="row" style="margin-bottom:8px">
      <span id="aiEnabledBadge" class="badge">AI: …</span>
      <button class="btn" id="toggleAiBtn">Toggle</button>
    </div>
    <div class="row" style="margin-bottom:8px">
      <label>Model:
        <select id="aiModelSel">
          <option value="anthropic/claude-3.5-sonnet">anthropic/claude-3.5-sonnet</option>
          <option value="google/gemini-1.5-pro">google/gemini-1.5-pro</option>
          <option value="deepseek/deepseek-chat">deepseek/deepseek-chat</option>
        </select>
      </label>
      <button class="btn" id="saveModelBtn">Save</button>
    </div>
    <div class="row" style="margin-bottom:8px">
      <label>Auto-send:
        <select id="aiAutosendSel">
          <option value="0">Off (review queue)</option>
          <option value="1">On (send immediately)</option>
        </select>
      </label>
      <button class="btn" id="saveAutosendBtn">Save</button>
    </div>
    <div id="aiStatus" class="muted" style="margin-top:4px"></div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px">Queue</h2>
    <div class="row" style="margin-bottom:8px">
      <label>Week: <select id="weekSel"></select></label>
      <button class="btn" id="sendApprovedBtn">Send approved (selected week)</button>
      <button class="btn warn" id="deleteAllWeekBtn">Delete all (this week)</button>
      <button class="btn warn" id="deleteAllBtn">Delete all (unsent)</button>
      <span id="queueStatus" class="muted"></span>
    </div>
    <table id="queueTable">
      <thead><tr><th>#</th><th>User</th><th>Week</th><th>Content</th><th>Approved</th><th>Actions</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px">AI Log</h2>
    <div id="aiLog" class="mono" style="white-space:pre-wrap; background:#0b1020; border:1px solid rgba(255,255,255,0.08); border-radius:8px; padding:8px; max-height:220px; overflow:auto;">Loading…</div>
    <div class="row" style="margin-top:8px"><button id="refreshLogBtn" class="btn">Refresh log</button></div>
  </div>
</div>

<script>
(function(){
  const base = '../';
  const CSRF = "<?= htmlspecialchars($csrf) ?>";

  async function g(key){ const r = await fetch(base+'api/get_setting.php?key='+encodeURIComponent(key)); const j = await r.json(); return (j && j.value != null)? String(j.value) : ''; }
  async function set(key,value){ await fetch(base+'api/set_setting.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ key, value, csrf: CSRF }) }); }

  async function loadAi(){
    const badge = document.getElementById('aiEnabledBadge');
    const st = document.getElementById('aiStatus');
    st.textContent = 'Loading…';
    try {
      const ai = await g('ai_enabled');
      badge.textContent = 'AI: ' + (ai==='1' ? 'ON' : 'OFF');
      badge.style.borderColor = ai==='1' ? 'rgba(122,255,180,0.35)' : 'rgba(255,255,255,0.15)';
      const model = await g('openrouter_model');
      if (model) document.getElementById('aiModelSel').value = model;
      const autosend = await g('ai_autosend');
      if (autosend) document.getElementById('aiAutosendSel').value = autosend;
      st.textContent = '';
    } catch(e) { st.textContent = 'Failed to load'; }
  }
  document.getElementById('toggleAiBtn').addEventListener('click', async ()=>{ const cur = await g('ai_enabled'); await set('ai_enabled', cur==='1'?'0':'1'); await loadAi(); });
  document.getElementById('saveModelBtn').addEventListener('click', async ()=>{ const v=document.getElementById('aiModelSel').value; await set('openrouter_model', v); await loadAi(); });
  document.getElementById('saveAutosendBtn').addEventListener('click', async ()=>{ const v=document.getElementById('aiAutosendSel').value; await set('ai_autosend', v); await loadAi(); });

  async function loadWeeks(){
    const sel = document.getElementById('weekSel'); sel.innerHTML='';
    try { const r=await fetch(base+'api/weeks.php',{cache:'no-store'}); const j=await r.json(); const weeks=Array.isArray(j.weeks)?j.weeks:[]; sel.innerHTML = weeks.map(w=>`<option value="${w.starts_on}">${w.label || w.starts_on}${w.finalized?' — finalized':''}</option>`).join(''); if (weeks.length) sel.value = weeks[0].starts_on; } catch(e){}
  }

  async function loadQueue(){
    const tbody = document.querySelector('#queueTable tbody'); tbody.innerHTML='';
    const st = document.getElementById('queueStatus'); st.textContent = 'Loading…';
    try {
      const r = await fetch(base+'api/ai_list.php?status=unsent', { cache:'no-store' });
      const j = await r.json();
      const items = Array.isArray(j.messages) ? j.messages : [];
      st.textContent = `${items.length} message(s)`;
      items.forEach((m,i)=>{
        const tr=document.createElement('tr');
        tr.innerHTML = `<td>${m.id}</td><td>${(m.user||'')}</td><td>${(m.week||'')}</td><td>${(m.body||'').replace(/[\r\n]+/g,' ')}</td><td>${m.approved?'Yes':'No'}</td><td></td>`;
        const tdAct = tr.lastElementChild;
        const appr = document.createElement('button'); appr.className='btn'; appr.textContent = m.approved ? 'Unapprove' : 'Approve';
        const del = document.createElement('button'); del.className='btn warn'; del.textContent='Delete';
        appr.onclick = async ()=>{ const form=new URLSearchParams({ id:String(m.id), approved: m.approved ? '0':'1', csrf: CSRF }); await fetch(base+'api/ai_approve_message.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: form }); await loadQueue(); };
        del.onclick = async ()=>{ const form=new URLSearchParams({ id:String(m.id), csrf: CSRF }); await fetch(base+'api/ai_delete_message.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: form }); await loadQueue(); };
        tdAct.append(appr,' ',del);
        tbody.appendChild(tr);
      });
    } catch(e) { st.textContent='Failed to load'; }
  }

  document.getElementById('sendApprovedBtn').addEventListener('click', async ()=>{
    const week = document.getElementById('weekSel').value || '';
    const r = await fetch(base+'api/ai_send_approved.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ week, csrf: CSRF }) });
    const j = await r.json().catch(()=>null);
    if (!r.ok || !j) return alert('Send failed');
    alert(`Sent: ${(j.sent_ids||[]).length} • Errors: ${(j.error_ids||[]).length}`);
    await loadQueue();
  });
  document.getElementById('deleteAllBtn').addEventListener('click', async ()=>{
    if (!confirm('Delete ALL unsent AI messages?')) return;
    const r = await fetch(base+'api/ai_delete_all.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ csrf: CSRF }) });
    const j = await r.json().catch(()=>null);
    if (!r.ok || !j) return alert('Delete failed');
    await loadQueue();
  });
  document.getElementById('deleteAllWeekBtn').addEventListener('click', async ()=>{
    const week = document.getElementById('weekSel').value || '';
    if (!week) return alert('Pick a week');
    if (!confirm('Delete ALL unsent for '+week+'?')) return;
    const r = await fetch(base+'api/ai_delete_all.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ week, csrf: CSRF }) });
    const j = await r.json().catch(()=>null);
    if (!r.ok || !j) return alert('Delete failed');
    await loadQueue();
  });

  async function loadAiLog(){
    const el = document.getElementById('aiLog'); el.textContent='Loading…';
    try { const r = await fetch(base+'api/ai_log.php'); const j = await r.json(); const rows = Array.isArray(j.entries)?j.entries:[]; el.textContent = rows.length? rows.join('\n') : 'No recent entries.'; } catch(e){ el.textContent='Failed to load'; }
  }
  document.getElementById('refreshLogBtn').addEventListener('click', loadAiLog);

  // Initial
  loadAi();
  loadWeeks();
  loadQueue();
  loadAiLog();
})();
</script>
</body>
</html>
