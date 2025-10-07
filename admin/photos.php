<?php
require __DIR__.'/../api/util.php';
$pdo = pdo();

$SITE_ASSETS = '../site/assets'; // standardized base for site assets

$users = $pdo->query("SELECT id,name,sex,age,tag,is_active,photo_path FROM users ORDER BY LOWER(name)")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html>
<meta charset="utf-8">
<title>Photos</title>
<style>
  body{font-family:system-ui;margin:20px}
  table{border-collapse:collapse;width:100%;max-width:900px}
  td,th{border:1px solid #444;padding:6px 8px;vertical-align:top}
  img.thumb{width:48px;height:48px;object-fit:cover;border-radius:50%}
  form.inline{display:inline-block;margin:0}
</style>
<h1>Photos</h1>
<p><a href="admin.php">Back to Admin</a></p>

<table>
  <thead>
    <tr><th>Name</th><th>Photo</th><th>Actions</th></tr>
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
        <form class="inline" action="../api/admin_upload_photo.php" method="post" enctype="multipart/form-data">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="redirect" value="1">
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
          <button type="submit">Upload</button>
        </form>
        <?php if ($u['photo_path']): ?>
        <form class="inline" action="../api/admin_delete_photo.php" method="post" onsubmit="return confirm('Remove photo?');">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="redirect" value="1">
          <button type="submit">Remove</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
