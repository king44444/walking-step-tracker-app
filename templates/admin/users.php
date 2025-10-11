<?php /** @var array $users */ /** @var string $csrfToken */ ?>
<?php ob_start(); ?>
<h1 class="admin-title">KW Admin: Users</h1>
<input type="hidden" id="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
<div>
  <button id="newUserBtn" class="btn">New user</button>
  <a class="btn" href="/admin/entries" style="margin-left:8px;">Go to Entries</a>
  <a class="btn" href="/admin/sms" style="margin-left:8px;">Go to SMS</a>
  <a class="btn" href="/admin/ai" style="margin-left:8px;">Go to AI</a>
  </div>

<table id="usersTable">
  <thead><tr><th></th><th>Name</th><th>Active</th><th>Tag</th><th>Actions</th></tr></thead>
  <tbody>
    <?php foreach ($users as $u): ?>
      <tr data-id="<?= (int)$u['id'] ?>">
        <td><img src="/public/assets/images/users/<?= (int)$u['id'] ?>/selfie.jpg" style="width:32px;height:32px;border-radius:4px" onerror="this.style.display='none'"></td>
        <td class="name"><?= htmlspecialchars($u['name']) ?></td>
        <td class="active"><?= (int)($u['is_active'] ?? 0) ? 'Yes' : 'No' ?></td>
        <td class="tag"><?= htmlspecialchars($u['tag'] ?? '') ?></td>
        <td>
          <button class="btn edit">Edit</button>
          <button class="btn warn delete">Delete</button>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div id="userForm" style="display:none">
  <h3 id="formTitle">Edit User</h3>
  <form id="uForm">
    <input type="hidden" name="id" id="u_id">
    <label>Name: <input name="name" id="u_name" required></label><br>
    <label>Tag: <input name="tag" id="u_tag"></label><br>
    <label>Sex: <input name="sex" id="u_sex"></label><br>
    <label>Age: <input name="age" id="u_age" type="number" min="0"></label><br>
    <label>Active: <input type="checkbox" name="is_active" id="u_active"></label><br>
    <button class="btn" id="saveUser">Save</button>
    <button class="btn" id="cancelUser">Cancel</button>
  </form>
</div>

<script>
const CSRF = document.getElementById('csrf').value;
function apiPost(path, data){ return fetch(path, {method:'POST',headers:{'Content-Type':'application/json','X-CSRF':CSRF},body:JSON.stringify(data)}); }

document.getElementById('newUserBtn').addEventListener('click', ()=> {
  document.getElementById('userForm').style.display = 'block';
  document.getElementById('formTitle').textContent = 'New user';
  document.getElementById('uForm').reset();
  document.getElementById('u_id').value = '';
});

document.querySelectorAll('#usersTable .edit').forEach(btn=>{
  btn.addEventListener('click', (e)=>{
    const tr = e.target.closest('tr');
    const id = tr.dataset.id;
    document.getElementById('userForm').style.display = 'block';
    document.getElementById('formTitle').textContent = 'Edit user';
    document.getElementById('u_id').value = id;
    document.getElementById('u_name').value = tr.querySelector('.name').textContent.trim();
    document.getElementById('u_tag').value = tr.querySelector('.tag').textContent.trim();
    document.getElementById('u_active').checked = tr.querySelector('.active').textContent.trim() === 'Yes';
  });
});

document.querySelectorAll('#usersTable .delete').forEach(btn=>{
  btn.addEventListener('click', async (e)=>{
    if (!confirm('Delete user?')) return;
    const id = e.target.closest('tr').dataset.id;
    const res = await apiPost('/admin/users/save', {action:'delete', id});
    if (!res.ok) return alert('Delete failed');
    location.reload();
  });
});

document.getElementById('cancelUser').addEventListener('click',(e)=>{ e.preventDefault(); document.getElementById('userForm').style.display='none'; });

document.getElementById('saveUser').addEventListener('click', async (e)=>{
  e.preventDefault();
  const id = document.getElementById('u_id').value;
  const payload = { user: {
    id: id ? parseInt(id,10) : null,
    name: document.getElementById('u_name').value,
    tag: document.getElementById('u_tag').value,
    sex: document.getElementById('u_sex').value,
    age: document.getElementById('u_age').value,
    is_active: document.getElementById('u_active').checked ? 1 : 0
  } };
  const res = await apiPost('/admin/users/save', payload);
  if (!res.ok) return alert('Save failed');
  const j = await res.json();
  if (j.error) return alert('Save failed: ' + j.error);
  location.reload();
});
<?php $content = ob_get_clean(); $title = 'Admin · Users'; $extraHead = '';
require __DIR__ . '/_layout.php'; ?>
</script>
