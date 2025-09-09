<?php
declare(strict_types=1);
ini_set('display_errors','1'); error_reporting(E_ALL);

try {
  require_once __DIR__ . '/../api/db.php';
  if (isset($_GET['migrate']) && $_GET['migrate'] === '1') {
    require_once __DIR__ . '/../api/migrate.php';
  }
  require_once __DIR__ . '/../api/lib/admin_auth.php';
  require_admin();
  require_once __DIR__ . '/../api/lib/ai.php';
  require_once __DIR__ . '/../api/lib/phone.php';
  require_once __DIR__ . '/../api/lib/env.php';


  $info = '';
  $err = '';
  $sms_send_summary_html = '';
  $internalSecret = env('INTERNAL_API_SECRET','');

  // Load weeks for selector (reuse same approach as admin.php)
  $weeks = $pdo->query("SELECT week, COALESCE(label, week) AS label, finalized FROM weeks ORDER BY week DESC")->fetchAll();
  $curWeek = $_GET['week'] ?? ($weeks[0]['week'] ?? '');
$selectedWeek = $curWeek;

  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
      // Toggle AI enabled (same as previous UI)
      if (isset($_POST['ai_enabled'])) {
        $val = ((string)$_POST['ai_enabled'] === '1') ? '1' : '0';
        $st = $pdo->prepare("INSERT INTO app_settings(key,value) VALUES('ai_enabled',:v) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
        $st->execute([':v'=>$val]);
        $info = $val === '1' ? 'AI toggled ON.' : 'AI toggled OFF.';
      }

      // Approve / Unapprove handlers
      $action = $_POST['action'] ?? '';
      if ($action === 'approve' || $action === 'unapprove') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid message id.');

        if ($action === 'approve') {
          $adminUser = require_admin_username();
          $upd = $pdo->prepare("UPDATE ai_messages SET approved_by = :u WHERE id = :id");
          $upd->execute([':u'=>$adminUser, ':id'=>$id]);
          $info = "Approved message #$id by $adminUser.";
        } else {
          // unapprove
          $upd = $pdo->prepare("UPDATE ai_messages SET approved_by = NULL WHERE id = :id");
          $upd->execute([':id'=>$id]);
          $info = "Unapproved message #$id.";
        }
      }

      // Enqueue demo nudge (seed a test row)
      if ($action === 'enqueue_demo') {
        try {
          // pick first user
          $uid = (int)$pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
          if ($uid) { ai_enqueue($pdo, 'nudge', $uid, $selectedWeek, 'rules-v0', 'demo'); }
          header("Location: ai.php?week=".urlencode($selectedWeek));
          exit;
        } catch (Throwable $e) {
          // swallow
        }
      }

      // Send approved messages for week moved to api/ai_send_approved.php.
      // The admin UI now calls that endpoint via JS to process all approved, unsent messages.
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }

  $enabled = ai_enabled($pdo);

  // Query AI messages for current week (if selected)
  $ai_messages = [];
  if ($curWeek) {
    $q = $pdo->prepare("SELECT m.id, m.type, m.user_id, u.name AS user, m.week, m.model, m.content, m.approved_by, m.created_at, m.sent_at
                        FROM ai_messages m LEFT JOIN users u ON u.id=m.user_id
                        WHERE m.week=? ORDER BY m.created_at DESC");
    $q->execute([$curWeek]);
    $ai_messages = $q->fetchAll();
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre style='color:#f88;background:#220;padding:8px;border:1px solid #844;border-radius:6px;'>" .
       htmlspecialchars($e->getMessage()) . "</pre>";
  throw $e;
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>KW Admin — AI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body { font: 14px system-ui, -apple-system, "Segoe UI", Roboto, Arial; background:#0b1020; color:#e6ecff; }
    a { color:#9ecbff; }
    .wrap { max-width: 900px; margin: 24px auto; padding: 0 16px; }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; margin-bottom:16px; }
    input, label, select { font:inherit; color:inherit; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .ok { color:#7ce3a1; } .err { color:#f79; }
    .row { display:flex; gap:12px; align-items:center; margin-top:12px; flex-wrap:wrap; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border-top:1px solid rgba(255,255,255,0.08); vertical-align:middle; text-align:left; }
    .chip { display:inline-block; padding:6px 8px; border-radius:999px; font-size:12px; }
    .chip.approved { background:#123b20; color:#9df0b8; border:1px solid #2d6f3f; }
    .chip.unapproved { background:#222834; color:#9aa6c8; border:1px solid #2c3a5a; }
    .chip.sent { background:#0f2a3b; color:#a9ddff; border:1px solid #1b5f86; }
    .small { font-size:12px; color:#aab9d9; }
    .actions form { display:inline; margin:0; }
    pre.content-preview { max-height:4.2rem; overflow:hidden; margin:0; color:#cfe8ff; background:transparent; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h2>AI Settings</h2>
      <div><a href="admin.php">Back to Admin</a></div>
      <div class="small"><a href="ai.php?migrate=1&amp;week=<?=urlencode($selectedWeek)?>">Run DB migration</a></div>
      <?php if ($info): ?><div class="ok" style="margin-top:8px;"><?=$info?></div><?php endif; ?>
      <?php if ($err): ?><div class="err" style="margin-top:8px;"><?=$err?></div><?php endif; ?>
      <?php if (!empty($sms_send_summary_html)): ?><div style="margin-top:8px"><?=$sms_send_summary_html?></div><?php endif; ?>

      <form method="post" style="margin-top:12px">
        <label style="display:inline-flex;align-items:center;gap:8px">
          <input type="hidden" name="ai_enabled" value="0" />
          <input type="checkbox" name="ai_enabled" value="1" <?= $enabled ? 'checked' : '' ?> />
          <span>AI enabled</span>
        </label>
        <div class="row">
          <button class="btn" type="submit">Save</button>
          <div style="margin-left:8px">Status: <strong><?= $enabled ? 'AI is Enabled.' : 'AI is Disabled.' ?></strong></div>
        </div>
      </form>
    </div>

    <div class="card">
      <h3>AI Messages</h3>
      <div class="row" style="margin-bottom:12px">
        <form method="get" action="ai.php">
          <label>Week:
            <select name="week" onchange="this.form.submit()">
              <?php foreach($weeks as $w): ?>
                <option value="<?=$w['week']?>" <?=$w['week']===$curWeek?'selected':''?>>
                  <?=$w['label']?> <?=$w['finalized']?'(finalized)':''?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>
        <?php
          $w = $selectedWeek;
          if ($w) {
            $pending = (int)$pdo->query("SELECT COUNT(*) FROM ai_messages WHERE week=".$pdo->quote($w))->fetchColumn();
            echo '<div class="small">AI messages in DB for '.htmlspecialchars((string)$w).': '.$pending.'</div>';
          }
        ?>
        <div class="small">Select a week to view AI messages created for that week. Approve/unapprove messages; do not send from here.</div>

        <!-- Run rules for week -->
        <div class="row" style="margin-top:8px">
          <form id="run-rules-form" method="post" action="../api/ai_rules.php" style="display:inline">
            <input type="hidden" name="week" value="<?=htmlspecialchars((string)$selectedWeek)?>" />
            <button class="btn" type="submit">Run rules for week</button>
          </form>

          <form method="post" style="display:inline;margin-left:8px">
            <input type="hidden" name="action" value="enqueue_demo" />
            <input type="hidden" name="week" value="<?=htmlspecialchars((string)$selectedWeek)?>" />
            <button class="btn" type="submit">Enqueue demo nudge</button>
          </form>

          <form id="send-approved-form" method="post" style="display:inline; margin-left:8px">
            <input type="hidden" id="week-input" name="week" value="<?=htmlspecialchars((string)$selectedWeek)?>" />
            <button class="btn" id="send-approved-btn" type="button">Send approved for week</button>
          </form>

          <span id="run-rules-result" class="small" style="margin-left:8px"></span>
        </div>

        <script>
          (function(){
            const form = document.getElementById('run-rules-form');
            const result = document.getElementById('run-rules-result');
            if (!form) return;
            form.addEventListener('submit', async function(e){
              e.preventDefault();
              result.textContent = 'Running...';
              try {
                const resp = await fetch(form.action, {
                  method: 'POST',
                  body: new FormData(form),
                  credentials: 'same-origin',
                });
                if (!resp.ok) {
                  const txt = await resp.text();
                  result.textContent = 'Error: ' + resp.status + ' ' + txt;
                  return;
                }
                const data = await resp.json();
                result.textContent = 'updated: ' + (data.updated ?? '0');
                // short delay to let user see the count, then reload to show messages
                setTimeout(function(){ window.location.reload(); }, 700);
              } catch (err) {
                result.textContent = 'Request failed';
              }
            });
          })();
        </script>

      </div>

      <?php if (!$curWeek): ?>
        <div class="small">No week selected.</div>
      <?php else: ?>
        <?php if (!count($ai_messages)): ?>
          <div class="small">No AI messages for <?=htmlspecialchars((string)$curWeek)?>.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th><th>Type</th><th>User</th><th>Model</th><th>Content</th><th>Approved</th><th>Created</th><th>Sent</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($ai_messages as $m): ?>
                <tr data-id="<?=htmlspecialchars((string)$m['id'])?>" data-week="<?=htmlspecialchars((string)$m['week'])?>">
                  <td><?=htmlspecialchars((string)$m['id'])?></td>
                  <td><?=htmlspecialchars($m['type'])?></td>
                  <td><?=htmlspecialchars($m['user'] ?? '')?></td>
                  <td><?=htmlspecialchars($m['model'] ?? '')?></td>
                  <td><pre class="content-preview"><?=htmlspecialchars(substr((string)$m['content'],0,300))?></pre></td>
                  <td>
                    <?php if ($m['approved_by']): ?>
                      <span class="chip approved"><?=htmlspecialchars($m['approved_by'])?></span>
                    <?php else: ?>
                      <span class="chip unapproved">unapproved</span>
                    <?php endif; ?>
                  </td>
                  <td class="small"><?=htmlspecialchars((string)$m['created_at'])?></td>
                  <td>
                      <?php if ($m['sent_at']): ?>
                      <span class="chip sent sent-status"><?=htmlspecialchars((string)$m['sent_at'])?></span>
                    <?php else: ?>
                      <span class="chip unapproved sent-status">not sent</span>
                    <?php endif; ?>
                  </td>
                  <td class="actions">
                    <?php if (!$m['approved_by']): ?>
                      <form method="post" onsubmit="return confirm('Approve this message?');" style="display:inline">
                        <input type="hidden" name="action" value="approve" />
                        <input type="hidden" name="id" value="<?=htmlspecialchars((string)$m['id'])?>" />
                        <button class="btn" type="submit">Approve</button>
                      </form>
                    <?php else: ?>
                      <form method="post" onsubmit="return confirm('Unapprove this message?');" style="display:inline">
                        <input type="hidden" name="action" value="unapprove" />
                        <input type="hidden" name="id" value="<?=htmlspecialchars((string)$m['id'])?>" />
                        <button class="btn" type="submit">Unapprove</button>
                      </form>
                    <?php endif; ?>
                    <button class="btn btn-danger btn-sm ai-delete" data-id="<?= htmlspecialchars((string)$m['id']) ?>">Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<script>
document.addEventListener('click', async (e) => {
  const del = e.target.closest('.ai-delete');
  if (del) {
    const id = del.dataset.id;
    if (!confirm('Delete this message?')) return;
    try {
      const form = new FormData(); form.append('id', id);
      const res = await fetch('/api/ai_delete_message.php', { method: 'POST', body: form, credentials: 'same-origin' });
      if (!res.ok) { alert('Delete failed'); return; }
      const json = await res.json();
      if (!json.ok) { alert('Delete failed'); return; }
      const tr = del.closest('tr'); if (tr) tr.remove();
    } catch (err) {
      alert('Delete failed: ' + (err.message || err));
    }
  }
});

document.getElementById('send-approved-btn')?.addEventListener('click', async () => {
  const week = document.querySelector('#week-input')?.value || '';
  if (!week) { alert('Pick a week'); return; }
  try {
    const fd = new FormData(); fd.append('week', week);
    const res = await fetch('/api/ai_send_approved.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    if (!res.ok) { const t = await res.text(); alert('Send failed: ' + t); return; }
    const data = await res.json();
    alert('Sent ' + (data.count||0) + ' message(s).');
    (data.sent||[]).forEach(s => {
      const tr = document.querySelector('tr[data-id=\"' + s.id + '\"]');
      if (tr) {
        const span = tr.querySelector('.sent-status');
        if (span) { span.textContent = 'sent'; span.classList.remove('unapproved'); span.classList.add('sent'); }
      }
    });
  } catch (err) {
    alert('Send failed: ' + (err.message || err));
  }
});
</script>
</body>
</html>
