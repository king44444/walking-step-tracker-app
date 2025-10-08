<?php
// Credentials come from .env (ADMIN_USER, ADMIN_PASS)
declare(strict_types=1);

try {
require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrfToken = \App\Security\Csrf::token();
$pdo = \App\Config\DB::pdo();
// Ensure schema exists without echoing anything
ob_start();
require_once __DIR__ . '/../api/migrate.php';
ob_end_clean();

function post($key, $default=null) { return $_POST[$key] ?? $default; }
function is_post() { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

function assert_week_fmt(string $w): void {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $w)) throw new Exception('Week must be YYYY-MM-DD');
}
function norm_day($v) {
  if ($v === '' || $v === null) return null;
  if (!is_numeric($v)) throw new Exception('Day values must be integers');
  $i = (int)$v;
  if ($i < 0) throw new Exception('Day values cannot be negative');
  return $i;
}

$info = '';
$err  = '';

if (is_post()) {
  // CSRF
  if (!\App\Security\Csrf::validate((string)($_POST['csrf'] ?? ''))) { $err = 'invalid_csrf'; } else {
  $action = post('action', '');
  try {
    if ($action === 'create_user') {
      $nm  = trim((string)post('u_name'));
      $sx  = trim((string)post('u_sex')) ?: null;
      $ag  = post('u_age'); $ag = strlen((string)$ag) ? (int)$ag : null;
      $tg  = trim((string)post('u_tag')) ?: null;
      if (!$nm) throw new Exception('Name required.');
      // Ensure new users have explicit AI defaults: ai_opt_in=0, interests='', rival_id=NULL
      $pdo->prepare("INSERT INTO users(name,sex,age,tag,ai_opt_in,interests,rival_id) VALUES(:n,:s,:a,:t,:ai,:int,:rid)")
          ->execute([':n'=>$nm, ':s'=>$sx, ':a'=>$ag, ':t'=>$tg, ':ai'=>0, ':int'=>'', ':rid'=>null]);
      $info = "User '$nm' created.";
    }

    if ($action === 'update_user') {
      $id = (int)post('u_id');
      $nm = trim((string)post('u_name'));
      $sx = trim((string)post('u_sex')) ?: null;
      $ag = post('u_age'); $ag = strlen((string)$ag) ? (int)$ag : null;
      $tg = trim((string)post('u_tag')) ?: null;
      $ac = (int)(post('u_active', '1') === '1');

      // New AI fields
      $ai_opt_in = (int)(post('u_ai_opt_in', '0') === '1');
      $interests = trim((string)post('u_interests', ''));
      $rival_raw = post('u_rival_id', '');
      $rival_id = ($rival_raw === '' || $rival_raw === null) ? null : (int)$rival_raw;

      if (!$nm) throw new Exception('Name required.');
      $stmt = $pdo->prepare("UPDATE users SET name=:n, sex=:s, age=:a, tag=:t, is_active=:ac, ai_opt_in=:ai_opt_in, interests=:interests, rival_id=:rival_id, updated_at=datetime('now') WHERE id=:id");
      $stmt->execute([
        ':n'=>$nm, ':s'=>$sx, ':a'=>$ag, ':t'=>$tg, ':ac'=>$ac,
        ':ai_opt_in'=>$ai_opt_in, ':interests'=>$interests, ':rival_id'=>$rival_id, ':id'=>$id
      ]);
      $info = "User '$nm' updated.";
    }

    /* Bulk add selected users to a week (users-page handler) */
    if ($action === 'bulk_add_selected_users') {
      $week = trim((string)post('week'));
      assert_week_fmt($week);
      $ids = $_POST['user_ids'] ?? [];
      if (!is_array($ids) || !count($ids)) throw new Exception('Select at least one user.');

      \App\Support\Tx::with(function($pdo) use (&$added, &$skipped, $week, $ids) {
      $sel = $pdo->prepare("SELECT name,sex,age,tag FROM users WHERE id=:id");
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0; $skipped=0;
      foreach ($ids as $rid) {
        $rid = (int)$rid;
        $sel->execute([':id'=>$rid]);
        if ($u = $sel->fetch()) {
          $ins->execute([':w'=>$week, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
          $added += ($ins->rowCount() > 0) ? 1 : 0;
          $skipped += ($ins->rowCount() === 0) ? 1 : 0;
        }
      }
      });
      $info = "Added $added user(s) to $week. Skipped $skipped.";
    }

  }
  catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

$weeks = $pdo->query("SELECT week, COALESCE(label, week) AS label, finalized FROM weeks ORDER BY week DESC")->fetchAll();
$curWeek = $_GET['week'] ?? ($weeks[0]['week'] ?? '');
$users = $pdo->query("SELECT id,name,sex,age,tag,is_active,photo_path,ai_opt_in,interests,rival_id FROM users ORDER BY LOWER(name)")->fetchAll();
$allUsers = $pdo->query("SELECT id,name FROM users ORDER BY LOWER(name)")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>KW Admin — Users</title>
  <link rel="icon" href="../favicon.ico" />
  <link rel="stylesheet" href="../public/assets/css/app.css" />
  <style>
    body { background:#0b1020; color:#e6ecff; font: 14px system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
    .wrap { max-width: 1200px; margin: 24px auto; padding: 0 16px; }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; margin-bottom:16px; }
    input, select { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border-top:1px solid rgba(255,255,255,0.08); vertical-align:middle; }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    .ok { color:#7ce3a1; } .err { color:#f79; }
    /* Users table specific */
    #usersTable { width:100%; border-collapse:collapse; table-layout:fixed; }
    #usersTable th, #usersTable td { padding:8px; }
    #userSearch { margin:6px 0;width:100%;max-width:260px; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="kicker">Kings Walk Week</div>
        <h1>Users</h1>
      </div>
      <div class="row" style="gap:8px">
        <a class="btn" href="index.php">Home</a>
        <a class="btn" href="weeks.php">Weeks</a>
        <a class="btn" href="entries.php">Entries</a>
        <a class="btn" href="users.php">Users</a>
        <a class="btn" href="ai.php">AI</a>
        <a class="btn" href="phones.php">Phones</a>
        <a class="btn" href="photos.php">Photos</a>
        <a class="btn" href="../site/">Dashboard</a>
      </div>
    </div>
    <?php if($info): ?><div class="ok"><?=$info?></div><?php endif; ?>
    <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between;align-items:flex-end">
      <h3 style="margin:0">Users</h3>
      <div class="row">
        <label>Week:
          <select id="weekSelect" onchange="onWeekChange()">
            <?php foreach ($weeks as $w): ?>
              <option value="<?= htmlspecialchars($w['week']) ?>" <?= ($w['week'] === ($curWeek ?? '') ? 'selected' : '') ?>>
                <?= htmlspecialchars($w['label']) ?><?= !empty($w['finalized']) ? ' (finalized)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </div>
    <form method="post" class="row" style="margin-bottom:8px">
      <input type="hidden" name="action" value="create_user" />
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>" />
      <label>Name: <input name="u_name" required placeholder="Name"></label>
      <label>Sex: <input name="u_sex" style="width:60px" placeholder=""></label>
      <label>Age: <input type="number" min="0" name="u_age" style="width:70px" placeholder=""></label>
      <label>Tag: <input name="u_tag" style="width:160px" placeholder="Pregnant, Injured, ..."></label>
      <button class="btn" type="submit">Add User</button>
    </form>

    <input id="userSearch" placeholder="Search users...">

    <div class="row" style="margin:6px 0">
      <form method="post" id="bulkAddForm" style="display:inline;margin-right:8px">
        <input type="hidden" name="action" value="bulk_add_selected_users" />
        <input type="hidden" name="week" value="<?=$curWeek?>" />
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>" />
        <button class="btn" type="submit">Add selected to week</button>
      </form>
      <form method="post" action="../api/entries_add_active.php" style="display:inline">
        <input type="hidden" name="week" value="<?=$curWeek?>" />
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>" />
        <button class="btn" type="submit">Add all active to week</button>
      </form>
    </div>

      <?php if ($users): ?>
    <table id="usersTable">
      <thead><tr><th style="width:28px"><input type="checkbox" id="chkAll"></th><th>Name</th><th>Sex</th><th>Age</th><th>Tag</th><th>Interests</th><th>AI Opt-in</th><th>Rival</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php foreach($users as $u): ?>
        <tr>
          <td><input type="checkbox" class="uChk" value="<?=$u['id']?>"></td>
          <form method="post" style="display:contents">
            <input type="hidden" name="action" value="update_user" />
            <input type="hidden" name="u_id" value="<?=$u['id']?>" />
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>" />
            <td><input name="u_name" value="<?=htmlspecialchars($u['name'])?>" required></td>
            <td><input name="u_sex" value="<?=htmlspecialchars((string)$u['sex'])?>" style="width:60px"></td>
            <td><input type="number" min="0" name="u_age" value="<?=htmlspecialchars((string)$u['age'])?>" style="width:70px"></td>
            <td><input name="u_tag" value="<?=htmlspecialchars((string)$u['tag'])?>" style="width:160px"></td>
            <td><input name="u_interests" value="<?=htmlspecialchars((string)$u['interests'])?>" style="width:160px"></td>
            <td>
              <select name="u_ai_opt_in">
                <option value="0" <?=(!$u['ai_opt_in'])?'selected':''?>>No</option>
                <option value="1" <?=($u['ai_opt_in'])?'selected':''?>>Yes</option>
              </select>
            </td>
            <td>
              <select name="u_rival_id">
                <option value=""><?=htmlspecialchars('(none)')?></option>
                <?php foreach($allUsers as $ou): ?>
                  <option value="<?=$ou['id']?>" <?=($ou['id']==$u['rival_id'])?'selected':''?>><?=htmlspecialchars($ou['name'])?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select name="u_active">
                <option value="1" <?=$u['is_active']?'selected':''?>>Yes</option>
                <option value="0" <?=!$u['is_active']?'selected':''?>>No</option>
              </select>
            </td>
            <td><button class="btn" type="submit">Save</button></td>
          </form>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p class="text-white/70">No users yet. Add some above.</p>
    <?php endif; ?>
  </div>

  </div>

</div>
<?php } catch (Throwable $e) {
  http_response_code(500);
  echo "<pre style='color:#f88;background:#220;padding:8px;border:1px solid #844;border-radius:6px;'>" .
       htmlspecialchars($e->getMessage()) . "</pre>";
  throw $e;
} ?>
<script>
(function(){
  function currentWeek(){ const sel=document.getElementById('weekSelect'); return sel? sel.value : '<?= htmlspecialchars($curWeek) ?>'; }
  window.onWeekChange = function(){
    const w = currentWeek();
    const url = new URL(window.location.href);
    url.searchParams.set('week', w);
    window.location.href = url.toString();
  };
  const q = document.getElementById('userSearch');
  const tbl = document.getElementById('usersTable');
  const chkAll = document.getElementById('chkAll');
  const bulkForm = document.getElementById('bulkAddForm');
  if (!tbl) return;
  const tbody = tbl.querySelector('tbody');

  function getRows(){ return Array.from(tbody.querySelectorAll('tr')); }

  function filterUsers(){
    const v = (q && q.value || '').trim().toLowerCase();
    getRows().forEach(tr=>{
      let nameEl = tr.querySelector('input[name="u_name"]') || tr.querySelector('td:nth-child(2)');
      let name = '';
      if (nameEl) {
        if (nameEl.value !== undefined) name = nameEl.value;
        else name = nameEl.textContent || '';
      }
      name = name.trim().toLowerCase();
      tr.style.display = (v === '' || name.indexOf(v) !== -1) ? '' : 'none';
    });
  }

  if (q) q.addEventListener('input', filterUsers);

  if (chkAll) {
    chkAll.addEventListener('change', ()=>{
      const checked = chkAll.checked;
      getRows().forEach(tr=>{
        if (tr.style.display === 'none') return;
        const cb = tr.querySelector('input.uChk');
        if (cb) cb.checked = checked;
      });
    });
  }

  if (bulkForm) {
    bulkForm.addEventListener('submit', function(e){
      // remove previous user_ids[] inputs
      Array.from(bulkForm.querySelectorAll('input[name="user_ids[]"]')).forEach(n=>n.remove());
      const checked = Array.from(tbl.querySelectorAll('tbody input.uChk:checked'))
        .filter(cb => cb.closest('tr') && cb.closest('tr').style.display !== 'none')
        .map(cb => cb.value);
      if (!checked.length) {
        e.preventDefault();
        alert('Select at least one user.');
        return;
      }
      // ensure hidden week input matches selector
      const w = currentWeek();
      const weekInput = bulkForm.querySelector('input[name="week"]');
      if (weekInput) weekInput.value = w;
      checked.forEach(id=>{
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = 'user_ids[]'; h.value = id;
        bulkForm.appendChild(h);
      });
    });
  }
})();
</script>
</body>
</html>
