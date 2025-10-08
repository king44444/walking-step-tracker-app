<?php
declare(strict_types=1);

// Simple, clean Admin landing with quick actions and links
// Uses existing APIs under ../api and keeps legacy editors available via links.

// Auth + bootstrap
require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = \App\Security\Csrf::token();

// Optional for image paths shared with site
$SITE_ASSETS = '../site/assets';

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>KW Admin</title>
  <link rel="icon" href="../favicon.ico" />
  <link rel="stylesheet" href="../public/assets/css/app.css" />
  <style>
    body { background:#0b1020; color:#e6ecff; font: 14px system-ui,-apple-system,"Segoe UI",Roboto,Arial; }
    .wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
    .grid { display:grid; grid-template-columns: 1fr; gap:16px; }
    @media (min-width: 920px){ .grid{ grid-template-columns: 1fr 1fr; } }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; }
    .hdr { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .nav { display:flex; flex-wrap:wrap; gap:8px; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    label input, select { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; }
    .muted { color: rgba(230,236,255,0.7); font-size: 12px; }
    h1 { font-size: 20px; font-weight: 800; margin: 0; }
    h2 { font-size: 16px; font-weight: 700; margin: 0 0 8px; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; border:1px solid rgba(255,255,255,0.15); font-size:12px; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border-top:1px solid rgba(255,255,255,0.08); text-align:left; }
    .ok { color:#7ce3a1; }
    .err { color:#f79; }
    .link { color:#9ecbff; text-decoration: none; }
  </style>
  <script>
  // CSRF token injected from server
  const CSRF = "<?= htmlspecialchars($csrf) ?>";
  // Small helper for form POSTs (x-www-form-urlencoded)
  async function postForm(url, params) {
    const body = new URLSearchParams(params || {});
    const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body });
    return { ok: res.ok, json: (async ()=>{ try { return await res.json(); } catch(e){ return null; } })() };
  }
  </script>
  </head>
<body>
<div class="wrap">
  <div class="card hdr">
    <div>
      <div class="kicker">Kings Walk Week</div>
      <h1>Admin</h1>
      <div class="muted">Signed in as <b><?=htmlspecialchars($_SERVER['PHP_AUTH_USER'] ?? 'admin')?></b></div>
    </div>
    <div class="nav">
      <a class="btn" href="../site/">View Dashboard</a>
      <a class="btn" href="weeks.php">Weeks</a>
      <a class="btn" href="entries.php">Entries</a>
      <a class="btn" href="users.php">Users</a>
      <a class="btn" href="ai.php">AI</a>
      <a class="btn" href="phones.php">Phones</a>
      <a class="btn" href="photos.php">Photos</a>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Weeks</h2>
      <div class="row" style="margin-bottom:8px">
        <label>Pick:
          <select id="weekSel"></select>
        </label>
        <span id="weekMeta" class="muted"></span>
      </div>
      <div class="row" style="margin-bottom:8px">
        <label>Date (YYYY-MM-DD): <input id="newWeekDate" placeholder="2025-10-19"></label>
        <label>Label: <input id="newWeekLabel" placeholder="Oct 19–25"></label>
        <button class="btn" id="createWeekBtn">Create/Update</button>
      </div>
      <div class="row" style="margin-bottom:8px">
        <button class="btn" id="finalizeBtn">Finalize</button>
        <button class="btn" id="unfinalizeBtn">Unfinalize</button>
        <button class="btn" id="addActiveBtn">Add all active to week</button>
        <button class="btn warn" id="deleteWeekBtn">Delete</button>
        <label class="muted"><input type="checkbox" id="forceDelete"> cascade entries</label>
      </div>
      <div id="weeksStatus" class="muted"></div>
    </div>

    <div class="card">
      <h2>AI Settings</h2>
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
      <div class="row" style="margin-top:6px">
        <a class="btn" href="ai.php">Open AI Console</a>
      </div>
      <div id="aiStatus" class="muted" style="margin-top:8px"></div>
    </div>

    <div class="card" style="grid-column: 1 / -1;">
      <h2>Quick Links</h2>
      <div class="row">
        <a class="link" href="../api/weeks.php">Weeks JSON</a>
        <span class="muted">·</span>
        <a class="link" href="../api/lifetime.php">Lifetime JSON</a>
        <span class="muted">·</span>
        <a class="link" href="../site/">Public dashboard</a>
        <span class="muted">·</span>
        <a class="link" href="entries.php">Entries editor</a>
      </div>
    </div>

    <div class="card" style="grid-column: 1 / -1;">
      <h2>AI Log Preview</h2>
      <div id="aiLog" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px; white-space:pre-wrap; background:#0b1020; border:1px solid rgba(255,255,255,0.08); border-radius:8px; padding:8px; max-height:220px; overflow:auto;">Loading…</div>
      <div class="row" style="margin-top:8px"><button id="refreshLogBtn" class="btn" type="button">Refresh log</button></div>
    </div>
  </div>

</div>

<script>
(function(){
  const base = '../'; // admin/ -> project root

  async function loadWeeks(){
    const s = document.getElementById('weeksStatus'); s.textContent = 'Loading weeks…';
    const sel = document.getElementById('weekSel'); sel.innerHTML = '';
    try {
      const r = await fetch(base + 'api/weeks.php', { cache: 'no-store' });
      const j = await r.json();
      const weeks = Array.isArray(j.weeks) ? j.weeks : [];
      sel.innerHTML = weeks.map(w => `<option value="${w.starts_on}">${w.label || w.starts_on}${w.finalized ? ' — finalized' : ''}</option>`).join('');
      if (weeks.length) sel.value = weeks[0].starts_on;
      s.textContent = `${weeks.length} week(s)`;
      updateWeekMeta();
    } catch (e) { s.textContent = 'Failed to load weeks'; }
  }

  function currentWeek(){ return document.getElementById('weekSel').value; }
  function updateWeekMeta(){
    const opt = document.getElementById('weekSel').selectedOptions[0];
    document.getElementById('weekMeta').textContent = opt ? opt.textContent : '';
  }
  document.getElementById('weekSel').addEventListener('change', updateWeekMeta);

  async function createWeek(){
    const date = (document.getElementById('newWeekDate').value || '').trim();
    const label = (document.getElementById('newWeekLabel').value || '').trim();
    if (!date) return alert('Enter a YYYY-MM-DD date');
    const res = await fetch(base + 'api/weeks.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ action:'create', date, label, csrf: CSRF }) });
    const txt = await res.text(); let j=null; try{ j=JSON.parse(txt);}catch(e){}
    if (!res.ok || !j || j.ok !== true) return alert('Create failed');
    await loadWeeks();
    alert('Week saved');
  }

  async function deleteWeek(){
    const date = currentWeek();
    if (!date) return alert('Pick a week');
    const force = document.getElementById('forceDelete').checked ? '1' : '0';
    if (!confirm('Delete week '+date + (force==='1' ? ' (cascade entries)' : '') + '?')) return;
    const res = await fetch(base + 'api/weeks.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ action:'delete', date, force, csrf: CSRF }) });
    const txt = await res.text(); let j=null; try{ j=JSON.parse(txt);}catch(e){}
    if (!res.ok || !j || j.ok !== true) return alert('Delete failed');
    await loadWeeks();
    alert('Deleted');
  }

  async function finalizeWeek(kind){ // kind: 'finalize' | 'unfinalize'
    const week = currentWeek(); if (!week) return alert('Pick a week');
    const url = base+'api/entries_finalize.php';
    const body = (kind==='unfinalize') ? new URLSearchParams({ action:'unfinalize', week, csrf: CSRF }) : new URLSearchParams({ action:'finalize', week, csrf: CSRF });
    const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body });
    if (!res.ok) { alert(kind+' failed'); return; }
    await loadWeeks();
    alert(kind.charAt(0).toUpperCase()+kind.slice(1)+'d');
  }

  async function addAllActive(){
    const week = currentWeek(); if (!week) return alert('Pick a week');
    const res = await fetch(base+'api/entries_add_active.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body: new URLSearchParams({ week, csrf: CSRF }) });
    if (!res.ok) { alert('Add failed'); return; }
    alert('Added active users to '+week);
  }

  document.getElementById('createWeekBtn').addEventListener('click', createWeek);
  document.getElementById('deleteWeekBtn').addEventListener('click', deleteWeek);
  document.getElementById('finalizeBtn').addEventListener('click', ()=>finalizeWeek('finalize'));
  document.getElementById('unfinalizeBtn').addEventListener('click', ()=>finalizeWeek('unfinalize'));
  document.getElementById('addActiveBtn').addEventListener('click', addAllActive);

  async function loadAi(){
    const badge = document.getElementById('aiEnabledBadge');
    const st = document.getElementById('aiStatus');
    st.textContent = 'Loading AI settings…';
    try {
      const g = async (key) => { const r = await fetch(base+'api/get_setting.php?key='+encodeURIComponent(key)); const j = await r.json(); return (j && j.value != null) ? String(j.value) : ''; };
      const ai = await g('ai_enabled');
      badge.textContent = 'AI: ' + (ai==='1' ? 'ON' : 'OFF');
      badge.style.borderColor = ai==='1' ? 'rgba(122, 255, 180, 0.35)' : 'rgba(255,255,255,0.15)';
      const model = await g('openrouter_model');
      if (model) document.getElementById('aiModelSel').value = model;
      const autosend = await g('ai_autosend');
      if (autosend) document.getElementById('aiAutosendSel').value = autosend;
      st.textContent = '';
    } catch (e) { st.textContent = 'Failed to load AI settings'; }
  }

  async function toggleAi(){
    try {
      const r = await fetch(base+'api/get_setting.php?key=ai_enabled');
      const j = await r.json();
      const cur = (j && j.value === '1') ? '1' : '0';
      const next = cur === '1' ? '0' : '1';
      await postForm(base+'api/set_setting.php', { key:'ai_enabled', value: next });
      await loadAi();
    } catch(e) { alert('Toggle failed'); }
  }

  async function saveModel(){
    const m = document.getElementById('aiModelSel').value;
    await postForm(base+'api/set_setting.php', { key:'openrouter_model', value: m });
    await loadAi();
  }
  async function saveAutosend(){
    const v = document.getElementById('aiAutosendSel').value;
    await postForm(base+'api/set_setting.php', { key:'ai_autosend', value: v });
    await loadAi();
  }
  document.getElementById('toggleAiBtn').addEventListener('click', toggleAi);
  document.getElementById('saveModelBtn').addEventListener('click', saveModel);
  document.getElementById('saveAutosendBtn').addEventListener('click', saveAutosend);

  async function loadAiLog(){
    const el = document.getElementById('aiLog');
    el.textContent = 'Loading…';
    try {
      const r = await fetch(base+'api/ai_log.php');
      const j = await r.json();
      const rows = Array.isArray(j.entries) ? j.entries : [];
      el.textContent = rows.length ? rows.join('\n') : 'No recent entries.';
    } catch(e) { el.textContent = 'Failed to load log'; }
  }
  document.getElementById('refreshLogBtn').addEventListener('click', loadAiLog);

  // Initial
  loadWeeks();
  loadAi();
  loadAiLog();
})();
</script>
</body>
</html>
