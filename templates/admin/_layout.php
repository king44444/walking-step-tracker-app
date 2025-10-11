<?php
// Shared admin layout. Expects $title (string) and $content (string) to be set.
// Provides consistent header + navigation across admin pages.
// Uses relative asset paths to comply with repo pathing rules.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title ?? 'Admin') ?></title>
  <link rel="stylesheet" href="../public/assets/css/app.css">
  <style>
    /* Lightweight, page-agnostic admin nav styling */
    .admin-shell { max-width: 1200px; margin: 0 auto; padding: 16px; }
    .admin-nav { display: flex; gap: 12px; align-items: center; padding: 10px 0 16px; border-bottom: 1px solid #e0e0e0; }
    .admin-nav a { text-decoration: none; color: #1a237e; font-weight: 600; padding: 6px 8px; border-radius: 4px; }
    .admin-nav a:hover { background: #e8eaf6; }
    .admin-nav a.active { background: #1a237e; color: #fff; }
    .admin-title { margin: 16px 0; }
  </style>
  <?php /* Allow pages to inject extra <head> content via $extraHead */ ?>
  <?= $extraHead ?? '' ?>
  </head>
<body>
  <div class="admin-shell">
    <nav class="admin-nav">
      <?php
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $is = function(string $path) use ($uri) {
          return strpos($uri, $path) === 0 ? 'active' : '';
        };
      ?>
      <a class="<?= $is('/admin/entries') ?>" href="/admin/entries">Entries</a>
      <a class="<?= $is('/admin/users') ?>" href="/admin/users">Users</a>
      <a class="<?= $is('/admin/sms') ?>" href="/admin/sms">SMS</a>
      <a class="<?= $is('/admin/ai') ?>" href="/admin/ai">AI</a>
    </nav>
    <main>
      <?= $content ?? '' ?>
    </main>
  </div>
</body>
</html>

