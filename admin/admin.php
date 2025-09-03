<?php
// Change credentials on Pi after deploy.
declare(strict_types=1);
ini_set('display_errors','1'); error_reporting(E_ALL);
/* Basic auth (edit credentials) */
const ADMIN_USER = 'mike';
const ADMIN_PASS = 'nikki100378';

if (!isset($_SERVER['PHP_AUTH_USER']) ||
    $_SERVER['PHP_AUTH_USER'] !== ADMIN_USER ||
    $_SERVER['PHP_AUTH_PW'] !== ADMIN_PASS) {
  header('WWW-Authenticate: Basic realm="KW Admin"');
  header('HTTP/1.0 401 Unauthorized');
  echo 'Auth required';
  exit;
}

try {
require __DIR__ . '/../api/db.php';
// Ensure schema exists without echoing anything
ob_start();
require __DIR__ . '/../api/migrate.php';
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
  $action = post('action', '');
  try {
    if ($action === 'create_week') {
      $week  = trim((string)post('week'));
      $label = trim((string)post('label'));
      if (!$week) throw new Exception('Week is required (e.g., 2025-08-24)');
      $st = $pdo->prepare("INSERT INTO weeks(week, label, finalized) VALUES(:w,:l,0)
                           ON CONFLICT(week) DO UPDATE SET label=excluded.label");
      $st->execute([':w'=>$week, ':l'=>$label ?: $week]);
      $info = "Week '$week' created/updated.";
    }
    if ($action === 'save_entry') {
      $week = trim((string)post('week'));
      $name = trim((string)post('name'));
      if (!$week || !$name) throw new Exception('Week and name required.');
      $days = [];
      foreach (['monday','tuesday','wednesday','thursday','friday','saturday'] as $d) {
        $days[$d] = norm_day(post($d));
      }
      $sex = trim((string)post('sex')) ?: null;
      $age = post('age'); $age = strlen((string)$age) ? (int)$age : null;
      $tag = trim((string)post('tag')) ?: null;

      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO weeks(week, label, finalized) VALUES(:w, :l, 0)
                     ON CONFLICT(week) DO NOTHING")
          ->execute([':w'=>$week, ':l'=>$week]);

      $exists = $pdo->prepare("SELECT id FROM entries WHERE week=:w AND name=:n");
      $exists->execute([':w'=>$week, ':n'=>$name]);
      $id = $exists->fetchColumn();
      if ($id) {
        $upd = $pdo->prepare("UPDATE entries SET monday=:mo,tuesday=:tu,wednesday=:we,thursday=:th,friday=:fr,saturday=:sa,sex=:sex,age=:age,tag=:tag,updated_at=datetime('now') WHERE id=:id");
        $upd->execute([
          ':mo'=>$days['monday'], ':tu'=>$days['tuesday'], ':we'=>$days['wednesday'],
          ':th'=>$days['thursday'], ':fr'=>$days['friday'], ':sa'=>$days['saturday'],
          ':sex'=>$sex, ':age'=>$age, ':tag'=>$tag, ':id'=>$id
        ]);
      } else {
        $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                              VALUES(:w,:n,:mo,:tu,:we,:th,:fr,:sa,:sex,:age,:tag)");
        $ins->execute([
          ':w'=>$week, ':n'=>$name,
          ':mo'=>$days['monday'], ':tu'=>$days['tuesday'], ':we'=>$days['wednesday'],
          ':th'=>$days['thursday'], ':fr'=>$days['friday'], ':sa'=>$days['saturday'],
          ':sex'=>$sex, ':age'=>$age, ':tag'=>$tag
        ]);
      }
      $pdo->commit();
      $info = "Saved entry for $name ($week).";
    }

    if ($action === 'create_user') {
      $nm  = trim((string)post('u_name'));
      $sx  = trim((string)post('u_sex')) ?: null;
      $ag  = post('u_age'); $ag = strlen((string)$ag) ? (int)$ag : null;
      $tg  = trim((string)post('u_tag')) ?: null;
      if (!$nm) throw new Exception('Name required.');
      $pdo->prepare("INSERT INTO users(name,sex,age,tag) VALUES(:n,:s,:a,:t)")
          ->execute([':n'=>$nm, ':s'=>$sx, ':a'=>$ag, ':t'=>$tg]);
      $info = "User '$nm' created.";
    }

    if ($action === 'update_user') {
      $id = (int)post('u_id');
      $nm = trim((string)post('u_name'));
      $sx = trim((string)post('u_sex')) ?: null;
      $ag = post('u_age'); $ag = strlen((string)$ag) ? (int)$ag : null;
      $tg = trim((string)post('u_tag')) ?: null;
      $ac = (int)(post('u_active', '1') === '1');

      if (!$nm) throw new Exception('Name required.');
      $pdo->prepare("UPDATE users SET name=:n, sex=:s, age=:a, tag=:t, is_active=:ac, updated_at=datetime('now') WHERE id=:id")
          ->execute([':n'=>$nm, ':s'=>$sx, ':a'=>$ag, ':t'=>$tg, ':ac'=>$ac, ':id'=>$id]);
      $info = "User '$nm' updated.";
    }

    if ($action === 'add_participant_from_user') {
      $week = trim((string)post('week'));
      $uid  = (int)post('user_id');
      if (!$week || !$uid) throw new Exception('Week and user required.');

      $u = $pdo->prepare("SELECT name,sex,age,tag FROM users WHERE id=:id");
      $u->execute([':id'=>$uid]);
      $user = $u->fetch();
      if (!$user) throw new Exception('User not found.');

      $exists = $pdo->prepare("SELECT 1 FROM entries WHERE week=:w AND name=:n");
      $exists->execute([':w'=>$week, ':n'=>$user['name']]);
      if (!$exists->fetchColumn()) {
        $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                              VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)");
        $ins->execute([
          ':w'=>$week, ':n'=>$user['name'],
          ':sex'=>$user['sex'] ?: null,
          ':age'=>$user['age'],
          ':tag'=>$user['tag'] ?: null
        ]);
        $info = "Added {$user['name']} to $week.";
      } else {
        $info = "{$user['name']} already in $week.";
      }
    }

    /* Bulk add selected users to a week */
    if ($action === 'bulk_add_selected_users') {
      $week = trim((string)post('week'));
      assert_week_fmt($week);
      $ids = $_POST['user_ids'] ?? [];
      if (!is_array($ids) || !count($ids)) throw new Exception('Select at least one user.');

      $pdo->beginTransaction();
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
      $pdo->commit();
      $info = "Added $added user(s) to $week. Skipped $skipped.";
    }

    /* Add all active users to a week */
    if ($action === 'add_all_active_to_week') {
      $week = trim((string)post('week'));
      assert_week_fmt($week);
      $pdo->beginTransaction();
      $q = $pdo->query("SELECT name,sex,age,tag FROM users WHERE is_active=1");
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0; $skipped=0;
      foreach ($q as $u) {
        $ins->execute([':w'=>$week, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
        $skipped += ($ins->rowCount() === 0) ? 1 : 0;
      }
      $pdo->commit();
      $info = "Added $added active user(s) to $week. Skipped $skipped.";
    }

    /* Create new week and copy all active users into it */
    if ($action === 'create_week_copy_active') {
      $week = trim((string)post('week'));
      $label = trim((string)post('label'));
      assert_week_fmt($week);
      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO weeks(week,label,finalized) VALUES(:w,:l,0)
                     ON CONFLICT(week) DO UPDATE SET label=COALESCE(:l,label)")
          ->execute([':w'=>$week, ':l'=>$label ?: $week]);
      $q = $pdo->query("SELECT name,sex,age,tag FROM users WHERE is_active=1");
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0;
      foreach ($q as $u) {
        $ins->execute([':w'=>$week, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
      }
      $pdo->commit();
      $info = "Week '$week' created. Added $added active user(s).";
    }

    /* Create new week and copy roster from current open week */
    if ($action === 'create_week_copy_from_current') {
      $newWeek = trim((string)post('week'));
      assert_week_fmt($newWeek);
      // determine current open week (latest)
      $srcWeek = $pdo->query("SELECT week FROM weeks ORDER BY week DESC LIMIT 1")->fetchColumn();
      if (!$srcWeek) throw new Exception('No current open week to copy from.');
      $label = trim((string)post('label'));

      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO weeks(week,label,finalized) VALUES(:w,:l,0)
                     ON CONFLICT(week) DO UPDATE SET label=COALESCE(:l,label)")
          ->execute([':w'=>$newWeek, ':l'=>$label ?: $newWeek]);

      $sel = $pdo->prepare("SELECT name,sex,age,tag FROM entries WHERE week=:w");
      $sel->execute([':w'=>$srcWeek]);
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0;
      foreach ($sel as $u) {
        $ins->execute([':w'=>$newWeek, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
      }
      $pdo->commit();
      $info = "Week '$newWeek' created. Copied $added from $srcWeek.";
    }

    /* Copy roster from another week into current open week */
    if ($action === 'copy_roster_from_week') {
      $target = trim((string)post('week'));  // current open week
      $source = trim((string)post('source_week'));
      assert_week_fmt($target);
      assert_week_fmt($source);

      $pdo->beginTransaction();
      $sel = $pdo->prepare("SELECT name,sex,age,tag FROM entries WHERE week=:w");
      $sel->execute([':w'=>$source]);
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0; $skipped=0;
      foreach ($sel as $u) {
        $ins->execute([':w'=>$target, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
        $skipped += ($ins->rowCount() === 0) ? 1 : 0;
      }
      $pdo->commit();
      $info = "Copied $added user(s) to $target from $source. Skipped $skipped.";
    }

    /* Bulk add selected users to a week */
    if ($action === 'bulk_add_selected_users') {
      $week = trim((string)post('week'));
      assert_week_fmt($week);
      $ids = $_POST['user_ids'] ?? [];
      if (!is_array($ids) || !count($ids)) throw new Exception('Select at least one user.');

      $pdo->beginTransaction();
      $sel = $pdo->prepare("SELECT name,sex,age,tag FROM users WHERE id=:id AND (is_active=1 OR is_active IS NULL)");
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
      $pdo->commit();
      $info = "Added $added user(s) to $week. Skipped $skipped.";
    }

    /* Add all active users to a week */
    if ($action === 'add_all_active_to_week') {
      $week = trim((string)post('week'));
      assert_week_fmt($week);
      $pdo->beginTransaction();
      $q = $pdo->query("SELECT name,sex,age,tag FROM users WHERE is_active=1");
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0; $skipped=0;
      foreach ($q as $u) {
        $ins->execute([':w'=>$week, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
        $skipped += ($ins->rowCount() === 0) ? 1 : 0;
      }
      $pdo->commit();
      $info = "Added $added active user(s) to $week. Skipped $skipped.";
    }

    /* Create new week and copy all active users into it */
    if ($action === 'create_week_copy_active') {
      $week = trim((string)post('week'));
      $label = trim((string)post('label'));
      assert_week_fmt($week);
      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO weeks(week,label,finalized) VALUES(:w,:l,0)
                     ON CONFLICT(week) DO UPDATE SET label=COALESCE(:l,label)")
          ->execute([':w'=>$week, ':l'=>$label ?: $week]);
      $q = $pdo->query("SELECT name,sex,age,tag FROM users WHERE is_active=1");
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0;
      foreach ($q as $u) {
        $ins->execute([':w'=>$week, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
      }
      $pdo->commit();
      $info = "Week '$week' created. Added $added active user(s).";
    }

    /* Create new week and copy roster from current open week */
    if ($action === 'create_week_copy_from_current') {
      $newWeek = trim((string)post('week'));
      assert_week_fmt($newWeek);
      // determine current open week (latest)
      $srcWeek = $pdo->query("SELECT week FROM weeks ORDER BY week DESC LIMIT 1")->fetchColumn();
      if (!$srcWeek) throw new Exception('No current open week to copy from.');
      $label = trim((string)post('label'));

      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO weeks(week,label,finalized) VALUES(:w,:l,0)
                     ON CONFLICT(week) DO UPDATE SET label=COALESCE(:l,label)")
          ->execute([':w'=>$newWeek, ':l'=>$label ?: $newWeek]);

      $sel = $pdo->prepare("SELECT name,sex,age,tag FROM entries WHERE week=:w");
      $sel->execute([':w'=>$srcWeek]);
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0;
      foreach ($sel as $u) {
        $ins->execute([':w'=>$newWeek, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
      }
      $pdo->commit();
      $info = "Week '$newWeek' created. Copied $added from $srcWeek.";
    }

    /* Copy roster from another week into current open week */
    if ($action === 'copy_roster_from_week') {
      $target = trim((string)post('week'));  // current open week
      $source = trim((string)post('source_week'));
      assert_week_fmt($target);
      assert_week_fmt($source);

      $pdo->beginTransaction();
      $sel = $pdo->prepare("SELECT name,sex,age,tag FROM entries WHERE week=:w");
      $sel->execute([':w'=>$source]);
      $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                            VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                            ON CONFLICT(week,name) DO NOTHING");
      $added=0; $skipped=0;
      foreach ($sel as $u) {
        $ins->execute([':w'=>$target, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age'], ':tag'=>$u['tag']?:null]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
        $skipped += ($ins->rowCount() === 0) ? 1 : 0;
      }
      $pdo->commit();
      $info = "Copied $added user(s) to $target from $source. Skipped $skipped.";
    }

    if ($action === 'delete_entry') {
      $id = (int)post('id');
      $pdo->prepare("DELETE FROM entries WHERE id=:id")->execute([':id'=>$id]);
      $info = "Deleted entry id $id.";
    }
    if ($action === 'finalize') {
      $week = trim((string)post('week'));
      if (!$week) throw new Exception('Week is required.');
      $q = $pdo->prepare("SELECT name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag FROM entries WHERE week=:w ORDER BY LOWER(name)");
      $q->execute([':w'=>$week]);
      $rows = $q->fetchAll();
      $json = json_encode($rows, JSON_UNESCAPED_SLASHES);

      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO snapshots(week,json) VALUES(:w,:j)
                     ON CONFLICT(week) DO UPDATE SET json=excluded.json, created_at=datetime('now')")
          ->execute([':w'=>$week, ':j'=>$json]);
      $pdo->prepare("UPDATE weeks SET finalized=1, finalized_at=datetime('now') WHERE week=:w")
          ->execute([':w'=>$week]);
      $pdo->commit();
      $info = "Finalized $week (snapshot saved).";
    }
    if ($action === 'unfinalize') {
      $week = trim((string)post('week'));
      if (!$week) throw new Exception('Week is required.');
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM snapshots WHERE week=:w")->execute([':w'=>$week]);
      $pdo->prepare("UPDATE weeks SET finalized=0, finalized_at=NULL WHERE week=:w")->execute([':w'=>$week]);
      $pdo->commit();
      $info = "Unfinalized $week (snapshot removed).";
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

$weeks = $pdo->query("SELECT week, COALESCE(label, week) AS label, finalized FROM weeks ORDER BY week DESC")->fetchAll();
$curWeek = $_GET['week'] ?? ($weeks[0]['week'] ?? '');
$entries = [];
if ($curWeek) {
  $st = $pdo->prepare("SELECT * FROM entries WHERE week = :w ORDER BY LOWER(name)");
  $st->execute([':w'=>$curWeek]);
  $entries = $st->fetchAll();
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>KW Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body { font: 14px system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#0b1020; color:#e6ecff; }
    a { color:#9ecbff; }
    .wrap { max-width: 1000px; margin: 24px auto; padding: 0 16px; }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; margin-bottom:16px; }
    input, select { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border-top:1px solid rgba(255,255,255,0.08); }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    .ok { color:#7ce3a1; } .err { color:#f79; }
    .split { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    @media (max-width:900px){ .split{ grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="wrap">
    <div class="card">
      <h2>KW Admin</h2>
      <div>Signed in as <b><?=htmlspecialchars($_SERVER['PHP_AUTH_USER'])?></b>. · <a href="/dev/html/walk/site/">View Dashboard</a> · <a href="phones.php">Phones</a> · <a href="/dev/html/walk/api/lifetime.php">Lifetime JSON</a></div>
    <?php
      if ($curWeek) {
        $wk = null;
        foreach ($weeks as $w) { if ($w['week'] === $curWeek) { $wk = $w; break; } }
        if ($wk) {
          echo '<div style="margin-top:8px">Open week: <b>' . htmlspecialchars($wk['label']) . '</b> ' .
               ($wk['finalized'] ? '<span class="ok">(finalized)</span>' : '') . "</div>";
        }
      }
    ?>
    <?php if($info): ?><div class="ok"><?=$info?></div><?php endif; ?>
    <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
  </div>

  <div class="split">
    <div class="card">
      <h3>Create / Update Week</h3>
      <form method="post">
        <input type="hidden" name="action" value="create_week" />
        <div class="row">
          <label>Week (YYYY-MM-DD): <input name="week" placeholder="2025-08-24" required></label>
          <label>Label: <input name="label" placeholder="Aug 24–30"></label>
          <button class="btn" type="submit">Save Week</button>
        </div>
      </form>
      <?php if (!$weeks): ?>
        <p class="ok" style="margin-top:8px;">No weeks yet. Create one above to get started.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Users</h3>
      <form method="post" class="row" style="margin-bottom:8px">
        <input type="hidden" name="action" value="create_user" />
        <label>Name: <input name="u_name" required placeholder="Name"></label>
        <label>Sex: <input name="u_sex" style="width:60px" placeholder=""></label>
        <label>Age: <input type="number" min="0" name="u_age" style="width:70px" placeholder=""></label>
        <label>Tag: <input name="u_tag" style="width:160px" placeholder="Pregnant, Injured, ..."></label>
        <button class="btn" type="submit">Add User</button>
      </form>

      <input id="userSearch" placeholder="Search users..." style="margin:6px 0;width:100%;max-width:260px">

      <div class="row" style="margin:6px 0">
        <form method="post" id="bulkAddForm" style="display:inline;margin-right:8px">
          <input type="hidden" name="action" value="bulk_add_selected_users" />
          <input type="hidden" name="week" value="<?=$curWeek?>" />
          <button class="btn" type="submit">Add selected to week</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="add_all_active_to_week" />
          <input type="hidden" name="week" value="<?=$curWeek?>" />
          <button class="btn" type="submit">Add all active to week</button>
        </form>
      </div>

      <?php
        $users = $pdo->query("SELECT id,name,sex,age,tag,is_active FROM users ORDER BY LOWER(name)")->fetchAll();
        if ($users):
      ?>
      <table id="usersTable">
        <thead><tr><th style="width:28px"><input type="checkbox" id="chkAll"></th><th>Name</th><th>Sex</th><th>Age</th><th>Tag</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach($users as $u): ?>
          <tr>
            <td><input type="checkbox" class="uChk" value="<?=$u['id']?>"></td>
            <form method="post">
              <input type="hidden" name="action" value="update_user" />
              <input type="hidden" name="u_id" value="<?=$u['id']?>" />
              <td><input name="u_name" value="<?=htmlspecialchars($u['name'])?>" required></td>
              <td><input name="u_sex" value="<?=htmlspecialchars((string)$u['sex'])?>" style="width:60px"></td>
              <td><input type="number" min="0" name="u_age" value="<?=htmlspecialchars((string)$u['age'])?>" style="width:70px"></td>
              <td><input name="u_tag" value="<?=htmlspecialchars((string)$u['tag'])?>" style="width:160px"></td>
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

    <div class="card">
      <h3>Weeks</h3>
      <form method="get" action="admin.php" class="row">
        <label>Open week:
          <select name="week" onchange="this.form.submit()">
            <?php foreach($weeks as $w): ?>
              <option value="<?=$w['week']?>" <?=$w['week']===$curWeek?'selected':''?>>
                <?=$w['label']?> <?=$w['finalized']?'(finalized)':''?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
    </div>
  </div>

  <?php if($curWeek): ?>
  <div class="card">
    <h3>Finalize</h3>
    <form method="post" class="row">
      <input type="hidden" name="week" value="<?=$curWeek?>" />
      <button class="btn" name="action" value="finalize" type="submit">Finalize this week (create/update snapshot)</button>
      <button class="btn warn" name="action" value="unfinalize" type="submit">Unfinalize (delete snapshot)</button>
    </form>
  </div>

  <div class="card">
    <h3>Edit Entries — <?=$curWeek?></h3>

    <form method="post" class="row" style="margin: 0 0 8px 0;">
      <input type="hidden" name="action" value="add_participant_from_user" />
      <input type="hidden" name="week" value="<?=$curWeek?>" />
      <label>Select user:
        <select name="user_id" required>
          <option value="">— choose —</option>
          <?php foreach($users as $u): ?>
            <option value="<?=$u['id']?>"><?=$u['name']?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit">Add to week</button>
    </form>

    <table>
      <thead>
        <tr>
          <th>Name</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
          <th>Sex</th><th>Age</th><th>Tag</th><th></th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($entries as $e): ?>
          <tr>
            <form method="post">
              <input type="hidden" name="action" value="save_entry" />
              <input type="hidden" name="week" value="<?=$curWeek?>" />
              <td><input name="name" value="<?=htmlspecialchars($e['name'])?>" required></td>
              <?php foreach(['monday','tuesday','wednesday','thursday','friday','saturday'] as $d): ?>
                <td><input type="number" min="0" name="<?=$d?>" value="<?=htmlspecialchars((string)$e[$d])?>"></td>
              <?php endforeach; ?>
              <td><input name="sex" value="<?=htmlspecialchars((string)$e['sex'])?>" style="width:60px"></td>
              <td><input type="number" min="0" name="age" value="<?=htmlspecialchars((string)$e['age'])?>" style="width:70px"></td>
              <td><input name="tag" value="<?=htmlspecialchars((string)$e['tag'])?>" style="width:120px"></td>
              <td><button class="btn" type="submit">Save</button></td>
            </form>
            <td>
              <form method="post" onsubmit="return confirm('Delete entry?');">
                <input type="hidden" name="action" value="delete_entry" />
                <input type="hidden" name="id" value="<?=$e['id']?>" />
                <button class="btn warn" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>

        <!-- Add new row -->
        <tr>
          <form method="post">
            <input type="hidden" name="action" value="save_entry" />
            <input type="hidden" name="week" value="<?=$curWeek?>" />
            <td><input name="name" placeholder="Name" required></td>
            <?php foreach(['monday','tuesday','wednesday','thursday','friday','saturday'] as $d): ?>
              <td><input type="number" min="0" name="<?=$d?>" placeholder="0"></td>
            <?php endforeach; ?>
            <td><input name="sex" placeholder=""></td>
            <td><input type="number" min="0" name="age" placeholder=""></td>
            <td><input name="tag" placeholder="Pregnant, Injured, ..."></td>
            <td><button class="btn" type="submit">Add</button></td>
            <td></td>
          </form>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php } catch (Throwable $e) {
  http_response_code(500);
  echo "<pre style='color:#f88;background:#220;padding:8px;border:1px solid #844;border-radius:6px;'>" .
       htmlspecialchars($e->getMessage()) . "</pre>";
  throw $e;
} ?>
<script>
(function(){
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
