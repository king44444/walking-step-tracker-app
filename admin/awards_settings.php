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
  <title>Awards Settings — KW Admin</title>
  <link rel="stylesheet" href="../public/assets/css/app.css" />
  <style>
    body { background:#0b1020; color:#e6ecff; font: 14px system-ui,-apple-system,"Segoe UI",Roboto,Arial; }
    .wrap { max-width: 900px; margin: 24px auto; padding: 0 16px; }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; margin-bottom:12px; }
    .row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:8px; }
    label input[type="text"], label input[type="number"] { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; min-width:220px; }
    .muted { color: rgba(230,236,255,0.7); font-size: 13px; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    h1{ margin:0 0 8px 0; font-size:18px; }
  </style>
  <script>
    const CSRF = "<?= htmlspecialchars($csrf) ?>";
    async function freshCsrf(){
      try {
        const r = await fetch('../api/csrf_token.php', { cache: 'no-store' });
        const j = await r.json();
        return (j && j.token) ? String(j.token) : CSRF;
      } catch(e) { return CSRF; }
    }
    async function postJson(url, body){
      const tk = await freshCsrf();
      const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF':tk}, body: JSON.stringify(body) });
      const j = await res.json().catch(()=>null);
      return { ok: res.ok, json: j };
    }
  </script>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div>
        <h1>Awards Settings</h1>
        <div class="muted">Edit thresholds and first-award labels (stored in settings).</div>
      </div>
      <div>
        <a class="btn" href="index.php">Back</a>
        <a class="btn" href="awards.php">Awards Editor</a>
      </div>
    </div>
  </div>

  <div class="card" id="settingsCard">
    <div class="row">
      <label>Cheryl threshold (steps): <input id="cherylThreshold" type="number" min="0"></label>
      <label>30k threshold (steps): <input id="thirtyKThreshold" type="number" min="0"></label>
    </div>

    <div class="row">
      <label>First 20k label: <input id="label20k" type="text" placeholder="Cheryl Award"></label>
      <label>First 30k label: <input id="label30k" type="text" placeholder="Megan Award"></label>
      <label>First 15k label: <input id="label15k" type="text" placeholder="Dean Award"></label>
    </div>

    <div class="row">
      <button class="btn" id="saveBtn">Save</button>
      <button class="btn" id="reloadBtn">Reload</button>
      <button class="btn warn" id="resetBtn">Reset to defaults</button>
      <span id="status" class="muted"></span>
    </div>

    <div style="margin-top:12px">
      <h2 style="margin:0 0 8px 0">Daily Milestones</h2>
      <div class="muted" style="margin-bottom:8px">Define ordered daily milestones as a JSON array of objects: [{"steps":1000,"label":"1k"}, ...]. The public site will use this list to render chips.</div>
      <div style="margin-bottom:8px">
        <textarea id="milestonesJson" style="width:100%;min-height:140px;background:#07102a;color:#e6ecff;border:1px solid #1e2a5a;padding:8px;border-radius:8px;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"></textarea>
      </div>
      <div class="row">
        <button class="btn" id="formatBtn">Format JSON</button>
        <button class="btn" id="validateBtn">Validate</button>
        <button class="btn" id="saveMilestonesBtn">Save Milestones</button>
        <button class="btn warn" id="resetMilestonesBtn">Reset Milestones to Defaults</button>
        <span id="milestonesStatus" class="muted"></span>
      </div>
      <div class="muted" style="margin-top:8px">
        Tip: Use Format first, then Save Milestones. Saving other thresholds/labels is still done by the main Save button.
      </div>
    </div>

    <div class="muted" style="margin-top:8px">
      Note: changing thresholds affects badges shown on the public dashboard. Labels change the custom award names.
    </div>
  </div>
</div>

<script>
(async function(){
  const base = '../';
  const s = document.getElementById('status');

  async function loadSettings(){
    s.textContent = 'Loading…';
    try {
      const res = await fetch(base + 'api/settings_get.php', { cache:'no-store' });
      const flags = await res.json();
      document.getElementById('cherylThreshold').value = flags['thresholds.cheryl'] || '20000';
      document.getElementById('thirtyKThreshold').value = flags['thresholds.thirty_k'] || '30000';
      document.getElementById('label20k').value = flags['awards.first_20k'] || 'Cheryl Award';
      document.getElementById('label30k').value = flags['awards.first_30k'] || 'Megan Award';
      document.getElementById('label15k').value = flags['awards.first_15k'] || 'Dean Award';

      // Load daily milestones JSON if present
      try {
        const raw = flags['daily.milestones'] || '';
        if (raw && typeof raw === 'string' && raw.trim().length > 0) {
          // Try to pretty-print stored JSON string
          try {
            const arr = JSON.parse(raw);
            document.getElementById('milestonesJson').value = JSON.stringify(arr, null, 2);
          } catch (e) {
            // stored value not valid JSON; place raw
            document.getElementById('milestonesJson').value = raw;
          }
        } else {
          // Try to fetch public defaults as a helpful fallback
          try {
            const pub = await fetch(base + 'api/public_settings.php', { cache:'no-store' });
            const pj = await pub.json();
            if (pj && Array.isArray(pj.daily_milestones)) {
              document.getElementById('milestonesJson').value = JSON.stringify(pj.daily_milestones, null, 2);
            } else {
              document.getElementById('milestonesJson').value = '';
            }
          } catch (e) {
            document.getElementById('milestonesJson').value = '';
          }
        }
      } catch (e) {
        document.getElementById('milestonesJson').value = '';
      }

      s.textContent = '';
    } catch (e) {
      s.textContent = 'Failed to load settings';
    }
  }

  async function saveAll(){
    s.textContent = 'Saving…';
    const updates = [
      { key: 'thresholds.cheryl', value: String(parseInt(document.getElementById('cherylThreshold').value || '0',10)) },
      { key: 'thresholds.thirty_k', value: String(parseInt(document.getElementById('thirtyKThreshold').value || '0',10)) },
      { key: 'awards.first_20k', value: String(document.getElementById('label20k').value || '') },
      { key: 'awards.first_30k', value: String(document.getElementById('label30k').value || '') },
      { key: 'awards.first_15k', value: String(document.getElementById('label15k').value || '') }
    ];
    try {
      for (const u of updates){
        const r = await postJson(base + 'api/settings_set.php', { key: u.key, value: u.value });
        if (!r.ok || (r.json && r.json.error)) {
          s.textContent = 'Save failed for ' + u.key;
          return;
        }
      }
      s.textContent = 'Saved';
    } catch(e) {
      s.textContent = 'Save error';
    }
    // give a moment then clear
    setTimeout(()=>{ if (s.textContent === 'Saved') s.textContent = ''; }, 1000);
  }

  async function resetDefaults(){
    if (!confirm('Reset thresholds and labels to their default values from config.json?')) return;
    // Defaults mirrored from site/config.json
    document.getElementById('cherylThreshold').value = '20000';
    document.getElementById('thirtyKThreshold').value = '30000';
    document.getElementById('label20k').value = 'Cheryl Award';
    document.getElementById('label30k').value = 'Megan Award';
    document.getElementById('label15k').value = 'Dean Award';
    await saveAll();
  }

  document.getElementById('saveBtn').addEventListener('click', saveAll);
  document.getElementById('reloadBtn').addEventListener('click', loadSettings);
  document.getElementById('resetBtn').addEventListener('click', resetDefaults);

  // Milestones editor handlers
  function tryParseMilestones(txt) {
    try {
      const v = JSON.parse(txt);
      if (!Array.isArray(v)) throw new Error('Must be an array');
      for (const it of v) {
        if (typeof it !== 'object' || it === null) throw new Error('Each item must be an object');
        if (!Number.isFinite(Number(it.steps)) || Number(it.steps) <= 0) throw new Error('Each item.steps must be a positive integer');
        if (!it.label || String(it.label).trim() === '') throw new Error('Each item.label must be non-empty');
      }
      return v;
    } catch (e) {
      throw e;
    }
  }

  document.getElementById('formatBtn').addEventListener('click', () => {
    const ta = document.getElementById('milestonesJson');
    try {
      const v = tryParseMilestones(ta.value || '[]');
      ta.value = JSON.stringify(v, null, 2);
      document.getElementById('milestonesStatus').textContent = 'Formatted';
    } catch (e) {
      document.getElementById('milestonesStatus').textContent = 'Format error: ' + (e.message || e);
    }
  });

  document.getElementById('validateBtn').addEventListener('click', () => {
    const ta = document.getElementById('milestonesJson');
    try {
      tryParseMilestones(ta.value || '[]');
      document.getElementById('milestonesStatus').textContent = 'Valid JSON';
    } catch (e) {
      document.getElementById('milestonesStatus').textContent = 'Validation error: ' + (e.message || e);
    }
  });

  document.getElementById('saveMilestonesBtn').addEventListener('click', async () => {
    const ta = document.getElementById('milestonesJson');
    const statusEl = document.getElementById('milestonesStatus');
    statusEl.textContent = 'Saving…';
    let parsed;
    try {
      parsed = tryParseMilestones(ta.value || '[]');
    } catch (e) {
      statusEl.textContent = 'Validation error: ' + (e.message || e);
      return;
    }
    try {
      const r = await postJson(base + 'api/settings_set.php', { key: 'daily.milestones', value: JSON.stringify(parsed) });
      if (!r.ok || (r.json && r.json.error)) {
        statusEl.textContent = 'Save failed';
        return;
      }
      statusEl.textContent = 'Saved';
      setTimeout(()=>{ if (statusEl.textContent === 'Saved') statusEl.textContent = ''; }, 1200);
    } catch (e) {
      statusEl.textContent = 'Save error';
    }
  });

  document.getElementById('resetMilestonesBtn').addEventListener('click', async () => {
    if (!confirm('Reset milestones to defaults from site/config.json?')) return;
    try {
      // Fetch public defaults and overwrite textarea & save
      const pub = await fetch(base + 'api/public_settings.php', { cache: 'no-store' });
      const pj = await pub.json();
      const arr = (pj && Array.isArray(pj.daily_milestones)) ? pj.daily_milestones : [];
      document.getElementById('milestonesJson').value = JSON.stringify(arr, null, 2);
      // Save to DB
      const r = await postJson(base + 'api/settings_set.php', { key: 'daily.milestones', value: JSON.stringify(arr) });
      const statusEl = document.getElementById('milestonesStatus');
      if (!r.ok || (r.json && r.json.error)) { statusEl.textContent = 'Reset save failed'; return; }
      statusEl.textContent = 'Reset and saved';
      setTimeout(()=>{ if (statusEl.textContent === 'Reset and saved') statusEl.textContent = ''; }, 1200);
    } catch (e) {
      document.getElementById('milestonesStatus').textContent = 'Reset failed';
    }
  });

  await loadSettings();
})();
</script>
</body>
</html>
