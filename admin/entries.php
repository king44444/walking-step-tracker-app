<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = \App\Security\Csrf::token();

use App\Config\DB;

$SITE_ASSETS = '../site/assets';

$pdo = DB::pdo();
$weeks = $pdo->query("SELECT week, COALESCE(label, week) AS label, COALESCE(finalized, 0) AS finalized FROM weeks ORDER BY week DESC")->fetchAll();
$curWeek = $_GET['week'] ?? ($weeks[0]['week'] ?? '');
$entries = [];
if ($curWeek) {
  $st = $pdo->prepare("SELECT id, name, monday, tuesday, wednesday, thursday, friday, saturday, sex, age, tag FROM entries WHERE week = :w ORDER BY LOWER(name)");
  try { $st->execute([':w'=>$curWeek]); $entries = $st->fetchAll(); } catch (Throwable $e) { $entries = []; }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>KW Admin — Entries</title>
  <link rel="icon" href="../favicon.ico" />
  <link rel="stylesheet" href="../public/assets/css/app.css" />
  <style>
    body { background:#0b1020; color:#e6ecff; font:14px system-ui,-apple-system,"Segoe UI",Roboto,Arial; }
    .wrap { max-width: 1200px; margin: 24px auto; padding: 0 16px; }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; margin-bottom:16px; }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    label input, select { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; }
    h1 { font-size: 20px; font-weight: 800; margin: 0; }
    .muted { color: rgba(230,236,255,0.7); font-size: 12px; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border-top:1px solid rgba(255,255,255,0.08); text-align:left; }
    th { position: sticky; top: 0; background: #0f1530; }
    .num { width: 90px; }
    .name { min-width: 200px; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="kicker">Kings Walk Week</div>
        <h1>Entries</h1>
      </div>
      <div class="row">
        <a class="btn" href="index.php">Home</a>
        <a class="btn" href="weeks.php">Weeks</a>
        <a class="btn" href="users.php">Users</a>
        <a class="btn" href="ai.php">AI</a>
        <a class="btn" href="../site/">Dashboard</a>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between; margin-bottom:8px">
      <div class="row">
        <label>Week:
          <select id="weekSel" onchange="onWeekChange()">
            <?php foreach ($weeks as $w): ?>
              <option value="<?= htmlspecialchars($w['week']) ?>" <?= ($w['week']===$curWeek?'selected':'') ?>><?= htmlspecialchars($w['label']) ?><?= !empty($w['finalized']) ? ' (finalized)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <span class="muted">Total: <?= count($entries) ?> entries</span>
      </div>
      <div class="row">
        <button class="btn" onclick="finalizeWeek()">Finalize</button>
        <button class="btn" onclick="unfinalizeWeek()">Unfinalize</button>
        <button class="btn" onclick="addAllActive()">Add all active</button>
      </div>
    </div>

    <div style="overflow:auto">
      <table id="tbl">
        <thead>
          <tr>
            <th class="name">Name</th>
            <th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
            <th>Tag</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): ?>
            <tr data-id="<?= (int)$e['id'] ?>">
              <td class="name">
                <span><?= htmlspecialchars($e['name']) ?></span>
              </td>
              <td><input class="num" type="number" min="0" name="monday" value="<?= htmlspecialchars((string)($e['monday'] ?? '')) ?>"></td>
              <td><input class="num" type="number" min="0" name="tuesday" value="<?= htmlspecialchars((string)($e['tuesday'] ?? '')) ?>"></td>
              <td><input class="num" type="number" min="0" name="wednesday" value="<?= htmlspecialchars((string)($e['wednesday'] ?? '')) ?>"></td>
              <td><input class="num" type="number" min="0" name="thursday" value="<?= htmlspecialchars((string)($e['thursday'] ?? '')) ?>"></td>
              <td><input class="num" type="number" min="0" name="friday" value="<?= htmlspecialchars((string)($e['friday'] ?? '')) ?>"></td>
              <td><input class="num" type="number" min="0" name="saturday" value="<?= htmlspecialchars((string)($e['saturday'] ?? '')) ?>"></td>
              <td><input type="text" name="tag" value="<?= htmlspecialchars((string)($e['tag'] ?? '')) ?>"></td>
              <td>
                <button class="btn" onclick="saveRow(this)">Save</button>
                <button class="btn warn" onclick="deleteRow(this)">Delete</button>
                <input type="hidden" name="sex" value="<?= htmlspecialchars((string)($e['sex'] ?? '')) ?>">
                <input type="hidden" name="age" value="<?= htmlspecialchars((string)($e['age'] ?? '')) ?>">
              </td>
            </tr>
          <?php endforeach; ?>
          <tr id="newRow">
            <td><input class="name" name="name" placeholder="Name"></td>
            <td><input class="num" type="number" min="0" name="monday" placeholder="0"></td>
            <td><input class="num" type="number" min="0" name="tuesday" placeholder="0"></td>
            <td><input class="num" type="number" min="0" name="wednesday" placeholder="0"></td>
            <td><input class="num" type="number" min="0" name="thursday" placeholder="0"></td>
            <td><input class="num" type="number" min="0" name="friday" placeholder="0"></td>
            <td><input class="num" type="number" min="0" name="saturday" placeholder="0"></td>
            <td><input type="text" name="tag" placeholder=""></td>
            <td><button class="btn" onclick="addRow(event)">Add</button></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const CSRF = "<?= htmlspecialchars($csrf) ?>";
function curWeek(){ const s=document.getElementById('weekSel'); return s ? s.value : ''; }
function onWeekChange(){ const w=curWeek(); const u=new URL(window.location.href); u.searchParams.set('week', w); window.location.href=u.toString(); }

async function postForm(url, params) {
  const body = new URLSearchParams(params || {});
  const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF}, body });
  return { ok: res.ok, text: await res.text() };
}

async function saveRow(btn){
  const tr = btn.closest('tr');
  const id = tr.getAttribute('data-id');
  const fields = ['monday','tuesday','wednesday','thursday','friday','saturday','tag','sex','age'];
  const data = { action:'save_entry', week: curWeek(), name: tr.querySelector('td span')?.textContent || tr.querySelector('input[name="name"]')?.value || '' };
  if (id) data.id = id;
  fields.forEach(k=>{ const el=tr.querySelector(`[name="${k}"]`); if (el) data[k]=el.value; });
  if (!data.name) { alert('Name required'); return; }
  const r = await postForm('../api/entries_save.php', data);
  if (!r.ok) { alert('Save failed'); return; }
  location.reload();
}

async function deleteRow(btn){
  const tr = btn.closest('tr');
  const id = tr.getAttribute('data-id');
  if (!id) { tr.remove(); return; }
  if (!confirm('Delete entry?')) return;
  const r = await postForm('../api/entries_save.php', { action:'delete', id });
  if (!r.ok) { alert('Delete failed'); return; }
  location.reload();
}

async function addRow(e){
  e.preventDefault();
  const tr = document.getElementById('newRow');
  const data = { action:'save_entry', week: curWeek(), name: tr.querySelector('[name="name"]').value.trim() };
  if (!data.name) { alert('Enter a name'); return; }
  ['monday','tuesday','wednesday','thursday','friday','saturday','tag'].forEach(k=>{ const el=tr.querySelector(`[name="${k}"]`); if (el && el.value !== '') data[k]=el.value; });
  const r = await postForm('../api/entries_save.php', data);
  if (!r.ok) { alert('Add failed'); return; }
  location.reload();
}

async function finalizeWeek(){
  const w = curWeek(); if (!w) return;
  if (!confirm('Finalize '+w+'?')) return;
  const r = await postForm('../api/entries_finalize.php', { action:'finalize', week: w });
  if (!r.ok) { alert('Finalize failed'); return; }
  location.reload();
}
async function unfinalizeWeek(){
  const w = curWeek(); if (!w) return;
  if (!confirm('Unfinalize '+w+'?')) return;
  const r = await postForm('../api/entries_finalize.php', { action:'unfinalize', week: w });
  if (!r.ok) { alert('Unfinalize failed'); return; }
  location.reload();
}
async function addAllActive(){
  const w = curWeek(); if (!w) return;
  const r = await postForm('../api/entries_add_active.php', { week: w });
  if (!r.ok) { alert('Add failed'); return; }
  alert('Added active users to '+w);
  location.reload();
}
</script>
</body>
</html>
