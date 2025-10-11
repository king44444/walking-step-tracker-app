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
    <div style="margin-top:0px">
      <h2 style="margin:0 0 8px 0">Daily Milestones</h2>
      <div class="muted" style="margin-bottom:8px">Define ordered daily milestones as a JSON array of objects: [{"steps":1000,"label":"1k"}, ...]. The public site will use this list to render chips.</div>
      <div style="margin-bottom:8px">
        <textarea id="milestonesJson" style="width:100%;min-height:160px;background:#07102a;color:#e6ecff;border:1px solid #1e2a5a;padding:8px;border-radius:8px;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"></textarea>
      </div>
      <div class="row">
        <button class="btn" id="formatBtn">Format JSON</button>
        <button class="btn" id="validateBtn">Validate</button>
        <button class="btn" id="saveMilestonesBtn">Save Milestones</button>
        <button class="btn" id="reloadBtn">Reload</button>
        <button class="btn warn" id="resetMilestonesBtn">Reset Milestones to Defaults</button>
        <span id="milestonesStatus" class="muted"></span>
      </div>
      <div class="muted" style="margin-top:8px">
        Tip: Use Format first, then Save Milestones.
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px 0">SMS Settings</h2>
    <div class="muted" style="margin-bottom:8px">Configure SMS behavior, admin controls, and rate limits.</div>

    <div class="row">
      <label>sms.admin_prefix_enabled: <input type="checkbox" id="smsAdminPrefixEnabled"></label>
    </div>
    <div class="row">
      <label>sms.admin_password: <input type="text" id="smsAdminPassword" placeholder="password"></label>
    </div>
    <div class="row">
      <label>app.public_base_url: <input type="text" id="appPublicBaseUrl" placeholder="https://example.com"></label>
    </div>
    <div class="row">
      <label>sms.inbound_rate_window_sec: <input type="number" id="smsInboundRateWindow" min="1" max="3600"></label>
    </div>
    <div class="row">
      <label>sms.ai_rate_window_sec: <input type="number" id="smsAiRateWindow" min="1" max="3600"></label>
    </div>
    <div class="row">
      <label>sms.backfill_days: <input type="number" id="smsBackfillDays" min="0" max="365"></label>
    </div>
    <div class="row">
      <label>reminders.default_morning: <input type="time" id="remindersDefaultMorning"></label>
    </div>
    <div class="row">
      <label>reminders.default_evening: <input type="time" id="remindersDefaultEvening"></label>
    </div>

    <div class="row">
      <button class="btn" id="saveSmsSettingsBtn">Save SMS Settings</button>
      <span id="smsSettingsStatus" class="muted"></span>
    </div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px 0">AI Image Prompts</h2>
    <div class="muted" style="margin-bottom:8px">Manage prompts used for generating award images. Prompts are randomly selected from enabled ones.</div>

    <div style="margin-bottom:16px">
      <h3 style="margin:0 0 8px 0">Regular Award Prompts</h3>
      <div id="regularPromptsList" style="margin-bottom:8px"></div>
      <div class="row">
        <button class="btn" id="addRegularPromptBtn">Add Regular Prompt</button>
        <button class="btn" id="saveRegularPromptsBtn">Save Regular Prompts</button>
        <span id="regularPromptsStatus" class="muted"></span>
      </div>
    </div>

    <div style="margin-bottom:16px">
      <h3 style="margin:0 0 8px 0">Lifetime Award Prompts</h3>
      <div id="lifetimePromptsList" style="margin-bottom:8px"></div>
      <div class="row">
        <button class="btn" id="addLifetimePromptBtn">Add Lifetime Prompt</button>
        <button class="btn" id="saveLifetimePromptsBtn">Save Lifetime Prompts</button>
        <span id="lifetimePromptsStatus" class="muted"></span>
      </div>
    </div>

    <div class="muted" style="margin-top:8px">
      Placeholders: {userName}, {awardLabel}, {milestone}, {interestText} (lifetime only), {styleHint} (lifetime only)
    </div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px 0">HELP Content Preview</h2>
    <div class="muted" style="margin-bottom:8px">Current HELP text that users see when they send HELP.</div>
    <div style="background:#07102a;border:1px solid #1e2a5a;padding:12px;border-radius:8px;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;white-space:pre-line;" id="helpPreview"></div>
  </div>
</div>

<script>
(async function(){
  const base = '../';

  async function loadSettings(){
    const statusEl = document.getElementById('milestonesStatus');
    if (statusEl) statusEl.textContent = 'Loading…';
    try {
      const res = await fetch(base + 'api/settings_get.php', { cache:'no-store' });
      const flags = await res.json();

      const raw = flags['daily.milestones'] || '';
      if (raw && typeof raw === 'string' && raw.trim().length > 0) {
        try {
          const arr = JSON.parse(raw);
          document.getElementById('milestonesJson').value = JSON.stringify(arr, null, 2);
        } catch (e) {
          document.getElementById('milestonesJson').value = raw;
        }
      } else {
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

      if (statusEl) statusEl.textContent = '';
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Failed to load settings';
      console.error('loadSettings error', e);
    }
  }

  function tryParseMilestones(txt) {
    const v = JSON.parse(txt);
    if (!Array.isArray(v)) throw new Error('Must be an array');
    for (const it of v) {
      if (typeof it !== 'object' || it === null) throw new Error('Each item must be an object');
      if (!Number.isFinite(Number(it.steps)) || Number(it.steps) <= 0) throw new Error('Each item.steps must be a positive integer');
      if (!it.label || String(it.label).trim() === '') throw new Error('Each item.label must be non-empty');
    }
    return v;
  }

  document.getElementById('formatBtn').addEventListener('click', () => {
    const ta = document.getElementById('milestonesJson');
    const statusEl = document.getElementById('milestonesStatus');
    try {
      const v = tryParseMilestones(ta.value || '[]');
      ta.value = JSON.stringify(v, null, 2);
      if (statusEl) statusEl.textContent = 'Formatted';
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Format error: ' + (e.message || e);
    }
  });

  document.getElementById('validateBtn').addEventListener('click', () => {
    const ta = document.getElementById('milestonesJson');
    const statusEl = document.getElementById('milestonesStatus');
    try {
      tryParseMilestones(ta.value || '[]');
      if (statusEl) statusEl.textContent = 'Valid JSON';
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Validation error: ' + (e.message || e);
    }
  });

  document.getElementById('saveMilestonesBtn').addEventListener('click', async () => {
    const ta = document.getElementById('milestonesJson');
    const statusEl = document.getElementById('milestonesStatus');
    if (statusEl) statusEl.textContent = 'Saving…';
    let parsed;
    try {
      parsed = tryParseMilestones(ta.value || '[]');
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Validation error: ' + (e.message || e);
      return;
    }
    try {
      const r = await postJson(base + 'api/settings_set.php', { key: 'daily.milestones', value: JSON.stringify(parsed) });
      if (!r.ok || (r.json && r.json.error)) {
        if (statusEl) statusEl.textContent = 'Save failed';
        return;
      }
      if (statusEl) statusEl.textContent = 'Saved';
      setTimeout(()=>{ if (statusEl && statusEl.textContent === 'Saved') statusEl.textContent = ''; }, 1200);
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Save error';
    }
  });

  document.getElementById('resetMilestonesBtn').addEventListener('click', async () => {
    if (!confirm('Reset milestones to defaults from site/config.json?')) return;
    const statusEl = document.getElementById('milestonesStatus');
    if (statusEl) statusEl.textContent = 'Resetting…';
    try {
      const pub = await fetch(base + 'api/public_settings.php', { cache:'no-store' });
      const pj = await pub.json();
      const arr = (pj && Array.isArray(pj.daily_milestones)) ? pj.daily_milestones : [];
      document.getElementById('milestonesJson').value = JSON.stringify(arr, null, 2);
      const r = await postJson(base + 'api/settings_set.php', { key: 'daily.milestones', value: JSON.stringify(arr) });
      if (!r.ok || (r.json && r.json.error)) {
        if (statusEl) statusEl.textContent = 'Reset save failed';
        return;
      }
      if (statusEl) statusEl.textContent = 'Reset and saved';
      setTimeout(()=>{ if (statusEl && statusEl.textContent === 'Reset and saved') statusEl.textContent = ''; }, 1200);
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Reset failed';
    }
  });

  document.getElementById('reloadBtn').addEventListener('click', loadSettings);

  // SMS Settings functionality
  async function loadSmsSettings(){
    try {
      const res = await fetch(base + 'api/settings_get.php', { cache:'no-store' });
      const settings = await res.json();

      document.getElementById('smsAdminPrefixEnabled').checked = settings['sms.admin_prefix_enabled'] === '1';
      document.getElementById('smsAdminPassword').value = settings['sms.admin_password'] || '';
      document.getElementById('appPublicBaseUrl').value = settings['app.public_base_url'] || '';
      document.getElementById('smsInboundRateWindow').value = settings['sms.inbound_rate_window_sec'] || '';
      document.getElementById('smsAiRateWindow').value = settings['sms.ai_rate_window_sec'] || '';
      document.getElementById('smsBackfillDays').value = settings['sms.backfill_days'] || '';
      document.getElementById('remindersDefaultMorning').value = settings['reminders.default_morning'] || '';
      document.getElementById('remindersDefaultEvening').value = settings['reminders.default_evening'] || '';

      // Load HELP preview
      loadHelpPreview();
    } catch (e) {
      console.error('loadSmsSettings error', e);
    }
  }

  async function loadHelpPreview(){
    try {
      const res = await fetch(base + 'api/settings_debug.php?key=help_text', { cache:'no-store' });
      const data = await res.json();
      document.getElementById('helpPreview').textContent = data.value || 'HELP text not found';
    } catch (e) {
      document.getElementById('helpPreview').textContent = 'Failed to load HELP preview';
    }
  }

  document.getElementById('saveSmsSettingsBtn').addEventListener('click', async () => {
    const statusEl = document.getElementById('smsSettingsStatus');
    if (statusEl) statusEl.textContent = 'Saving…';

    const settings = [
      { key: 'sms.admin_prefix_enabled', value: document.getElementById('smsAdminPrefixEnabled').checked ? '1' : '0' },
      { key: 'sms.admin_password', value: document.getElementById('smsAdminPassword').value },
      { key: 'app.public_base_url', value: document.getElementById('appPublicBaseUrl').value },
      { key: 'sms.inbound_rate_window_sec', value: document.getElementById('smsInboundRateWindow').value },
      { key: 'sms.ai_rate_window_sec', value: document.getElementById('smsAiRateWindow').value },
      { key: 'sms.backfill_days', value: document.getElementById('smsBackfillDays').value },
      { key: 'reminders.default_morning', value: document.getElementById('remindersDefaultMorning').value },
      { key: 'reminders.default_evening', value: document.getElementById('remindersDefaultEvening').value }
    ];

    try {
      for (const setting of settings) {
        const r = await postJson(base + 'api/settings_set.php', setting);
        if (!r.ok || (r.json && r.json.error)) {
          if (statusEl) statusEl.textContent = `Save failed for ${setting.key}`;
          return;
        }
      }
      if (statusEl) statusEl.textContent = 'Saved';
      setTimeout(()=>{ if (statusEl && statusEl.textContent === 'Saved') statusEl.textContent = ''; }, 1200);
      loadHelpPreview(); // Refresh HELP preview
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Save error';
    }
  });

  // AI Image Prompts functionality
  let regularPrompts = [];
  let lifetimePrompts = [];

  async function loadPrompts(){
    try {
      const res = await fetch(base + 'api/settings_get.php', { cache:'no-store' });
      const settings = await res.json();

      const regularJson = settings['ai.image.prompts.regular'] || '[]';
      const lifetimeJson = settings['ai.image.prompts.lifetime'] || '[]';

      regularPrompts = JSON.parse(regularJson) || [];
      lifetimePrompts = JSON.parse(lifetimeJson) || [];

      renderPrompts('regular', regularPrompts);
      renderPrompts('lifetime', lifetimePrompts);
    } catch (e) {
      console.error('loadPrompts error', e);
    }
  }

  function renderPrompts(type, prompts){
    const container = document.getElementById(type + 'PromptsList');
    container.innerHTML = '';

    if (prompts.length === 0) {
      container.innerHTML = '<div class="muted">No prompts configured</div>';
      return;
    }

    prompts.forEach((prompt, index) => {
      const div = document.createElement('div');
      div.style.cssText = 'background:#07102a;border:1px solid #1e2a5a;border-radius:8px;padding:12px;margin-bottom:8px;';
      div.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
          <div style="flex:1;">
            <strong>${prompt.name || 'Unnamed'}</strong>
            <label style="margin-left:12px;"><input type="checkbox" ${prompt.enabled !== false ? 'checked' : ''} onchange="togglePrompt('${type}', ${index})"> Enabled</label>
          </div>
          <button class="btn warn" onclick="deletePrompt('${type}', ${index})" style="font-size:12px;padding:4px 8px;">Delete</button>
        </div>
        <textarea style="width:100%;min-height:80px;background:#111936;color:#e6ecff;border:1px solid #1e2a5a;padding:8px;border-radius:4px;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;" onchange="updatePromptText('${type}', ${index}, this.value)">${prompt.text || ''}</textarea>
        <input type="text" placeholder="Prompt name" style="width:100%;background:#111936;color:#e6ecff;border:1px solid #1e2a5a;padding:4px 8px;border-radius:4px;margin-top:4px;" value="${prompt.name || ''}" onchange="updatePromptName('${type}', ${index}, this.value)">
      `;
      container.appendChild(div);
    });
  }

  window.togglePrompt = function(type, index){
    const prompts = type === 'regular' ? regularPrompts : lifetimePrompts;
    if (prompts[index]) {
      prompts[index].enabled = !prompts[index].enabled;
    }
  };

  window.deletePrompt = function(type, index){
    if (!confirm('Delete this prompt?')) return;
    const prompts = type === 'regular' ? regularPrompts : lifetimePrompts;
    prompts.splice(index, 1);
    renderPrompts(type, prompts);
  };

  window.updatePromptText = function(type, index, text){
    const prompts = type === 'regular' ? regularPrompts : lifetimePrompts;
    if (prompts[index]) {
      prompts[index].text = text;
    }
  };

  window.updatePromptName = function(type, index, name){
    const prompts = type === 'regular' ? regularPrompts : lifetimePrompts;
    if (prompts[index]) {
      prompts[index].name = name;
    }
  };

  document.getElementById('addRegularPromptBtn').addEventListener('click', () => {
    regularPrompts.push({ name: 'New Prompt', text: 'Create a {style} icon for {userName} achieving {awardLabel} ({milestone}).', enabled: true });
    renderPrompts('regular', regularPrompts);
  });

  document.getElementById('addLifetimePromptBtn').addEventListener('click', () => {
    lifetimePrompts.push({ name: 'New Lifetime Prompt', text: 'Design an award for {userName} reaching {milestone} lifetime steps ({awardLabel}). Incorporate {interestText} with {styleHint}.', enabled: true });
    renderPrompts('lifetime', lifetimePrompts);
  });

  document.getElementById('saveRegularPromptsBtn').addEventListener('click', async () => {
    const statusEl = document.getElementById('regularPromptsStatus');
    if (statusEl) statusEl.textContent = 'Saving…';
    try {
      const r = await postJson(base + 'api/settings_set.php', { key: 'ai.image.prompts.regular', value: JSON.stringify(regularPrompts) });
      if (!r.ok || (r.json && r.json.error)) {
        if (statusEl) statusEl.textContent = 'Save failed';
        return;
      }
      if (statusEl) statusEl.textContent = 'Saved';
      setTimeout(()=>{ if (statusEl && statusEl.textContent === 'Saved') statusEl.textContent = ''; }, 1200);
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Save error';
    }
  });

  document.getElementById('saveLifetimePromptsBtn').addEventListener('click', async () => {
    const statusEl = document.getElementById('lifetimePromptsStatus');
    if (statusEl) statusEl.textContent = 'Saving…';
    try {
      const r = await postJson(base + 'api/settings_set.php', { key: 'ai.image.prompts.lifetime', value: JSON.stringify(lifetimePrompts) });
      if (!r.ok || (r.json && r.json.error)) {
        if (statusEl) statusEl.textContent = 'Save failed';
        return;
      }
      if (statusEl) statusEl.textContent = 'Saved';
      setTimeout(()=>{ if (statusEl && statusEl.textContent === 'Saved') statusEl.textContent = ''; }, 1200);
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Save error';
    }
  });

  await loadSettings();
  await loadSmsSettings();
  await loadPrompts();
})();
</script>
</body>
</html>
