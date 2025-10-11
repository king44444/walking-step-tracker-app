<?php /** @var array $weeks */ /** @var array $entries */ /** @var array $users */ /** @var string $csrfToken */ /** @var string $curWeek */ ?>
<?php ob_start(); ?>
<h1 class="admin-title">KW Admin: Entries</h1>
<input type="hidden" id="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
<section>
  <label>Week:</label>
  <select id="weekSelect" onchange="onWeekChange()">
    <?php foreach ($weeks as $w): ?>
      <option value="<?= htmlspecialchars($w['week']) ?>" data-finalized="<?= (int)($w['finalized'] ?? 0) ?>" <?= ($w['week'] === ($curWeek ?? '') ? 'selected' : '') ?>>
        <?= htmlspecialchars($w['label']) ?><?= !empty($w['finalized']) ? ' (finalized)' : '' ?>
      </option>
    <?php endforeach; ?>
  </select>

  <button id="addActive" class="btn">Add all active to week</button>
  <button id="finalize" class="btn warn">Finalize week</button>
  <button id="saveAll" class="btn">Save changes</button>
</section>

<div id="grid" class="entries-wrap">
  <table class="entries-table" id="entriesTable">
    <thead>
      <tr>
        <th class="col-name">Name</th>
        <th class="col-day">Mon</th><th class="col-day">Tue</th><th class="col-day">Wed</th><th class="col-day">Thu</th><th class="col-day">Fri</th><th class="col-day">Sat</th><th class="col-day">Sun</th>
        <th class="col-tag">Tag</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($entries as $e): ?>
        <tr data-id="<?= (int)$e['id'] ?>" data-locked="<?= (int)($e['locked'] ?? 0) ?>">
          <td class="col-name name-cell">
            <span class="name-text"><?= htmlspecialchars($e['name']) ?></span>
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
          </td>
          <?php foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $d): ?>
            <td class="col-day"><input type="number" min="0" name="<?= $d ?>" value="<?= htmlspecialchars((string)($e[$d] ?? '')) ?>"></td>
          <?php endforeach; ?>
          <td class="col-tag"><input name="tag" value="<?= htmlspecialchars((string)($e['tag'] ?? '')) ?>"></td>
          <td><button class="btn warn delete-row">Delete</button></td>
        </tr>
      <?php endforeach; ?>

      <!-- New row template -->
      <tr id="newRow">
        <td class="col-name"><input name="name" placeholder="Name"></td>
        <?php foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $d): ?>
          <td class="col-day"><input type="number" min="0" name="<?= $d ?>" placeholder="0"></td>
        <?php endforeach; ?>
        <td class="col-tag"><input name="tag" placeholder="Pregnant, Injured, ..."></td>
        <td><button class="btn" id="addRowBtn">Add</button></td>
      </tr>
    </tbody>
  </table>
</div>

<script>
const CSRF = document.getElementById('csrf').value;
function apiPost(path, data){
  return fetch(path, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF': CSRF},
    body: JSON.stringify(data)
  });
}

function currentWeek(){ return document.getElementById('weekSelect').value; }
function currentWeekFinalized(){
  const opt = document.getElementById('weekSelect').selectedOptions[0];
  return opt && opt.dataset && opt.dataset.finalized === '1';
}

document.getElementById('addActive').addEventListener('click', async ()=>{
  const week = currentWeek();
  if (!week) return alert('Select a week');
  await apiPost('/admin/entries/add-active', {week});
  location.reload();
});

document.getElementById('finalize').addEventListener('click', async ()=>{
  const week = currentWeek();
  if (!week) return alert('Select a week');
  if (!confirm('Finalize this week? This will lock entries.')) return;
  await apiPost('/admin/entries/finalize', {week});
  location.reload();
});

document.getElementById('addRowBtn').addEventListener('click', async (e)=>{
  e.preventDefault();
  if (currentWeekFinalized()) return alert('Week is finalized; cannot add rows.');
  const tr = document.getElementById('newRow');
  const name = tr.querySelector('input[name="name"]').value.trim();
  if (!name) return alert('Name required');
  const data = { week: currentWeek(), name, mon: tr.querySelector('input[name="monday"]').value, tue: tr.querySelector('input[name="tuesday"]').value, wed: tr.querySelector('input[name="wednesday"]').value, thu: tr.querySelector('input[name="thursday"]').value, fri: tr.querySelector('input[name="friday"]').value, sat: tr.querySelector('input[name="saturday"]').value, sun: tr.querySelector('input[name="sunday"]').value, tag: tr.querySelector('input[name="tag"]').value };
  const res = await apiPost('/admin/entries/save', data);
  if (!res.ok) return alert('Add failed');
  location.reload();
});

document.getElementById('saveAll').addEventListener('click', async ()=>{
  if (currentWeekFinalized()) return alert('Week is finalized; cannot save changes.');
  const rows = Array.from(document.querySelectorAll('#entriesTable tbody tr')).filter(r => r.id !== 'newRow');
  const entries = [];
  for (const tr of rows) {
    const id = tr.dataset.id ? parseInt(tr.dataset.id,10) : null;
    const locked = tr.dataset.locked === '1';
    if (locked) continue;
    const obj = { week: currentWeek() };
    if (id) obj.id = id;
    obj.mon = tr.querySelector('input[name="monday"]')?.value ?? null;
    obj.tue = tr.querySelector('input[name="tuesday"]')?.value ?? null;
    obj.wed = tr.querySelector('input[name="wednesday"]')?.value ?? null;
    obj.thu = tr.querySelector('input[name="thursday"]')?.value ?? null;
    obj.fri = tr.querySelector('input[name="friday"]')?.value ?? null;
    obj.sat = tr.querySelector('input[name="saturday"]')?.value ?? null;
    obj.sun = tr.querySelector('input[name="sunday"]')?.value ?? null;
    obj.tag = tr.querySelector('input[name="tag"]')?.value ?? null;
    entries.push(obj);
  }
  const res = await apiPost('/admin/entries/save', {entries});
  if (!res.ok) {
    const txt = await res.text();
    alert('Save failed: ' + (txt||res.statusText));
    return;
  }
  const j = await res.json();
  if (j.error) return alert('Save failed: ' + j.error);
  alert('Saved');
  location.reload();
});

// delete row
document.querySelectorAll('.delete-row').forEach(btn=>{
  btn.addEventListener('click', async (e)=>{
    const tr = e.target.closest('tr');
    const id = tr.dataset.id;
    if (!id) { tr.remove(); return; }
    if (!confirm('Delete entry?')) return;
    const res = await apiPost('/admin/entries/save', {action:'delete', id: parseInt(id,10)});
    if (!res.ok) return alert('Delete failed');
    location.reload();
  });
});

function onWeekChange(){
  const w = currentWeek();
  const url = new URL(window.location.href);
  url.searchParams.set('week', w);
  window.location.href = url.toString();
}

// Disable inputs when week finalized or row locked
(function disableLocked(){
  if (currentWeekFinalized()) {
    document.querySelectorAll('#entriesTable input, #entriesTable button#addRowBtn, #addRowBtn, #saveAll').forEach(n=>{
      n.disabled = true;
    });
  } else {
    document.querySelectorAll('#entriesTable tr[data-locked="1"] input, #entriesTable tr[data-locked="1"] .delete-row').forEach(n=>{
      n.disabled = true;
    });
  }
})();
</script>
<?php $content = ob_get_clean(); $title = 'Admin · Entries'; $extraHead = '';
require __DIR__ . '/_layout.php'; ?>
