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

  await loadSettings();
})();
</script>
</body>
</html>
