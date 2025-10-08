<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();

require __DIR__.'/../api/util.php';
$pdo = pdo();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrfToken = \App\Security\Csrf::token();

$SITE_ASSETS = '../site/assets'; // standardized base for site assets

$users = $pdo->query("SELECT id,name,sex,age,tag,is_active,photo_path FROM users ORDER BY LOWER(name)")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>KW Admin — Photos</title>
  <link rel="icon" href="../favicon.ico" />
  <link rel="stylesheet" href="../public/assets/css/app.css" />
  <style>
    body { background:#0b1020; color:#e6ecff; font:14px system-ui,-apple-system,"Segoe UI",Roboto,Arial; }
    .wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; margin-bottom:16px; }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .btn { padding:8px 10px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    input[type="file"] { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; }
    h1 { font-size: 20px; font-weight: 800; margin: 0; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border-top:1px solid rgba(255,255,255,0.08); text-align:left; vertical-align: middle; }
    img.thumb{width:48px;height:48px;object-fit:cover;border-radius:50%}
    .nav { display:flex; flex-wrap:wrap; gap:8px; }
    form.inline{display:inline-block;margin:0}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="kicker">Kings Walk Week</div>
        <h1>Photos</h1>
      </div>
      <div class="nav">
        <a class="btn" href="index.php">Home</a>
        <a class="btn" href="weeks.php">Weeks</a>
        <a class="btn" href="entries.php">Entries</a>
        <a class="btn" href="users.php">Users</a>
        <a class="btn" href="ai.php">AI</a>
        <a class="btn" href="phones.php">Phones</a>
        <a class="btn" href="../site/">Dashboard</a>
      </div>
    </div>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr><th style="width:280px">Name</th><th style="width:120px">Photo</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): 
        $name = htmlspecialchars($u['name']);
        $photoPath = $u['photo_path'] ?? '';
        if ($photoPath) {
          // convert absolute URL to path
          if (preg_match('~^https?://~i', $photoPath)) {
            $p = parse_url($photoPath, PHP_URL_PATH) ?: $photoPath;
            $photoPath = $p;
          }
          // normalize: remove leading slashes, optional leading "site/" and "assets/"
          $photoPath = preg_replace('#^/+#', '', $photoPath);
          $photoPath = preg_replace('#^site/#', '', $photoPath);
          $photoPath = preg_replace('#^assets/#', '', $photoPath);
          // point to ../site/assets/... consistently
          $thumbRel = $SITE_ASSETS . '/' . ltrim($photoPath, '/');
        } else {
          $thumbRel = $SITE_ASSETS . '/admin/no-photo.svg';
        }
      ?>
      <tr>
        <td><?= $name ?></td>
        <td><img src="<?= htmlspecialchars($thumbRel) ?>" alt="photo" class="thumb"></td>
        <td>
          <form class="inline" action="../api/admin_upload_photo.php" method="post" enctype="multipart/form-data" style="display:inline-flex;gap:8px;align-items:center">
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="redirect" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
            <button class="btn" type="submit">Upload</button>
          </form>
          <?php if ($u['photo_path']): ?>
          <form class="inline" action="../api/admin_delete_photo.php" method="post" onsubmit="return confirm('Remove photo?');" style="display:inline-flex;gap:8px;align-items:center;margin-left:8px">
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="redirect" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <button class="btn warn" type="submit">Remove</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
