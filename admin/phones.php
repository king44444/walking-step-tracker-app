<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();

require __DIR__.'/../api/util.php';
require __DIR__.'/../api/lib/phone.php';
$pdo = pdo();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrfToken = \App\Security\Csrf::token();

$info = '';
$err = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  // CSRF validate
  require_once __DIR__ . '/../app/Security/Csrf.php';
  $csrf = $_POST['csrf'] ?? '';
  if (!\App\Security\Csrf::validate((string)$csrf)) { http_response_code(403); exit('invalid_csrf'); }
  $action = $_POST['action'] ?? '';
  $name = $_POST['name'] ?? '';
  if ($action==='save') {
    $phone = $_POST['phone'] ?? '';
    $st = $pdo->prepare("UPDATE users SET phone_e164=? WHERE name=?");
    $st->execute([$phone ?: null, $name]);
  } elseif ($action==='normalize') {
    $phone = $_POST['phone'] ?? '';
    $norm = to_e164($phone);
    $st = $pdo->prepare("UPDATE users SET phone_e164=? WHERE name=?");
    $st->execute([$norm, $name]);
  } elseif ($action==='clear') {
    $st = $pdo->prepare("UPDATE users SET phone_e164=NULL WHERE name=?");
    $st->execute([$name]);
  } elseif ($action==='disable_all_reminders') {
    try {
      $st = $pdo->prepare("UPDATE users SET reminders_enabled=0 WHERE reminders_enabled=1");
      $st->execute();
      $count = $st->rowCount();
      $info = "Successfully disabled reminders for {$count} user(s).";
    } catch (Exception $e) {
      $err = "Failed to disable reminders: " . $e->getMessage();
    }
  }
  if ($action !== 'disable_all_reminders') {
    header('Location: phones.php'); exit;
  }
}

$rows = $pdo->query("SELECT name, COALESCE(phone_e164,'') AS phone_e164 FROM users ORDER BY LOWER(name)")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>KW Admin — Phones</title>
  <link rel="icon" href="../favicon.ico" />
  <link rel="stylesheet" href="../public/assets/css/app.css" />
  <style>
    body { background:#0b1020; color:#e6ecff; font:14px system-ui,-apple-system,"Segoe UI",Roboto,Arial; }
    .wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; margin-bottom:16px; }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    label input, input, select { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; }
    h1 { font-size: 20px; font-weight: 800; margin: 0; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border-top:1px solid rgba(255,255,255,0.08); text-align:left; }
    .nav { display:flex; flex-wrap:wrap; gap:8px; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px; }
    .ok { color:#7ce3a1; font-weight:600; }
    .err { color:#f79; font-weight:600; }
    .btn.danger { background:#7a1a1a; border-color:#a33; color:#fff; }
    .btn.danger:hover { background:#8a2a2a; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="kicker">Kings Walk Week</div>
        <h1>Phones</h1>
      </div>
      <div class="nav">
        <a class="btn" href="index.php">Home</a>
        <a class="btn" href="weeks.php">Weeks</a>
        <a class="btn" href="entries.php">Entries</a>
        <a class="btn" href="users.php">Users</a>
        <a class="btn" href="ai.php">AI</a>
        <a class="btn" href="photos.php">Photos</a>
        <a class="btn" href="../site/">Dashboard</a>
      </div>
    </div>
    <?php if($info): ?><div class="ok" style="margin:8px 0"><?=$info?></div><?php endif; ?>
    <?php if($err): ?><div class="err" style="margin:8px 0"><?=$err?></div><?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin:0 0 12px 0">Reminder Controls</h2>
    <form method="post" style="display:inline" onsubmit="return confirm('This will disable reminders for ALL users. Are you sure?');">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>" />
      <input type="hidden" name="action" value="disable_all_reminders" />
      <button class="btn danger" type="submit">Disable All Reminders</button>
    </form>
    <p style="margin:8px 0 0 0; font-size:13px; color:#999;">
      This will set reminders_enabled=0 for all users. Users will stop receiving scheduled reminder SMS messages.
    </p>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr><th style="width:220px">Name</th><th style="width:360px">Phone (E.164)</th><th>Test curl</th></tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): $n=htmlspecialchars($r['name']); $p=htmlspecialchars($r['phone_e164']); ?>
        <tr>
          <td><?= $n ?></td>
          <td>
            <form method="post" class="row" style="gap:6px">
              <input type="hidden" name="name" value="<?= $n ?>" />
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>" />
              <input name="phone" value="<?= $p ?>" placeholder="+18015551234" style="min-width:200px">
              <button class="btn" name="action" value="save">Save</button>
              <button class="btn" name="action" value="normalize" type="submit">Normalize</button>
              <button class="btn warn" name="action" value="clear" type="submit">Clear</button>
            </form>
          </td>
          <td><code>curl -X POST ../api/sms.php --data-urlencode "From=<?= $p ?: '+1XXXXXXXXXX' ?>" --data-urlencode "Body=123"</code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
