<?php
$csrf = \App\Security\Csrf::token();
?>
<!doctype html><meta charset="utf-8"><title>AI Admin</title>
<div>
  <button id="refresh">Refresh</button>
  <input id="week" placeholder="YYYY-W##">
  <button id="send">Send approved for week</button>
  <div id="sendStatus" style="margin-top:8px;color:#222"></div>
  <ul id="items"></ul>
</div>
<script>
/* Inject CSRF hidden input (for fetch requests) if not already present */
if (!document.getElementById('csrf')) {
  const _inp = document.createElement('input');
  _inp.type = 'hidden';
  _inp.id = 'csrf';
  _inp.value = <?= json_encode($csrf) ?>;
  document.body.appendChild(_inp);
}

/* POST JSON helper that includes CSRF header */
async function postJSON(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF': document.getElementById('csrf').value
    },
    body: JSON.stringify(payload)
  });
  if (!res.ok) throw new Error(await res.text());
  return res.json();
}

function mkBtn(text, cls) {
  const b = document.createElement('button');
  b.textContent = text;
  if (cls) b.className = cls;
  return b;
}

async function postForm(url, formData) {
  const res = await fetch(url, { method: 'POST', body: formData });
  let txt;
  try { txt = await res.text(); } catch(e){ txt = ''; }
  let json = null;
  try { json = JSON.parse(txt); } catch(e){ /* not json */ }
  return { ok: res.ok, status: res.status, text: txt, json };
}

async function load() {
  const r = await fetch('/api/ai/list');
  const j = await r.json();
  const ul = document.getElementById('items');
  ul.innerHTML = '';
  if (!j.items || !j.items.length) {
    ul.innerHTML = '<li>No pending messages</li>';
    return;
  }
  j.items.forEach(it => {
    const li = document.createElement('li');
    li.style.marginBottom = '8px';
    const meta = document.createElement('div');
    meta.style.fontSize = '0.9em';
    meta.style.color = '#444';
    meta.textContent = `#${it.id} ${it.created_at ?? ''} ${it.user_id ? ('user:'+it.user_id) : ''}`;
    const body = document.createElement('div');
    body.textContent = it.content ?? it.body ?? '';
    body.style.margin = '4px 0';
    const controls = document.createElement('div');
    controls.style.display = 'flex';
    controls.style.gap = '6px';
    controls.style.alignItems = 'center';

    const approveBtn = mkBtn('Approve', 'approve');
    const rejectBtn = mkBtn('Reject', 'reject');
    const deleteBtn = mkBtn('Delete', 'delete');
    const status = document.createElement('span');
    status.style.marginLeft = '8px';
    status.style.color = '#006400';

    approveBtn.onclick = async () => {
      approveBtn.disabled = true; rejectBtn.disabled = true; deleteBtn.disabled = true;
      status.textContent = 'approving...';
      try {
        const json = await postJSON('/admin/ai/approve', { id: it.id, approved: true });
        if (json && json.ok) {
          status.textContent = 'approved';
          // reload list to remove approved item
          await load();
        } else {
          status.style.color = 'crimson';
          status.textContent = `approve failed: ${json?.error ?? 'unknown'}`;
          approveBtn.disabled = false; rejectBtn.disabled = false; deleteBtn.disabled = false;
        }
      } catch (e) {
        status.style.color = 'crimson';
        status.textContent = 'approve failed: ' + e.message;
        approveBtn.disabled = false; rejectBtn.disabled = false; deleteBtn.disabled = false;
      }
    };

    // Use legacy delete endpoint for both Reject and Delete actions.
    // Ensure CSRF is included; server-side file will be updated to validate CSRF.
    async function doDelete(actionName) {
      approveBtn.disabled = true; rejectBtn.disabled = true; deleteBtn.disabled = true;
      status.style.color = '#444';
      status.textContent = actionName + '...';
      try {
        const json = await postJSON('/admin/ai/delete', { id: it.id });
        if (json && json.ok) {
          status.style.color = '#006400';
          status.textContent = actionName + ' OK';
          await load();
        } else {
          status.style.color = 'crimson';
          status.textContent = `${actionName} failed: ${json?.error ?? 'unknown'}`;
          approveBtn.disabled = false; rejectBtn.disabled = false; deleteBtn.disabled = false;
        }
      } catch (e) {
        status.style.color = 'crimson';
        status.textContent = `${actionName} failed: ${e.message}`;
        approveBtn.disabled = false; rejectBtn.disabled = false; deleteBtn.disabled = false;
      }
    }

    rejectBtn.onclick = () => doDelete('rejected');
    deleteBtn.onclick = () => doDelete('deleted');

    controls.appendChild(approveBtn);
    controls.appendChild(rejectBtn);
    controls.appendChild(deleteBtn);
    controls.appendChild(status);

    li.appendChild(meta);
    li.appendChild(body);
    li.appendChild(controls);
    ul.appendChild(li);
  });
}

document.getElementById('refresh').onclick = load;

document.getElementById('send').onclick = async () => {
  const weekVal = document.getElementById('week').value.trim();
  const sendStatus = document.getElementById('sendStatus');
  sendStatus.style.color = '#444';
  sendStatus.textContent = 'sending...';
  try {
    const json = await postJSON('/admin/ai/send-approved', { week: weekVal });
    if (json && json.ok) {
      sendStatus.style.color = '#006400';
      sendStatus.textContent = `Sent ${json.count ?? 0} messages.`;
    } else {
      sendStatus.style.color = 'crimson';
      sendStatus.textContent = `Send failed: ${json?.error ?? 'unknown'}`;
    }
  } catch (e) {
    sendStatus.style.color = 'crimson';
    sendStatus.textContent = `Send failed: ${e.message}`;
  }
  // refresh list after send to reflect sent_at
  await load();
};

load();
</script>
