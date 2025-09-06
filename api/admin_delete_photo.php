<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/db.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(400); exit('bad_method'); }
$id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
if ($id <= 0) { http_response_code(400); exit('bad_input'); }

$st = $pdo->prepare('SELECT photo_path FROM users WHERE id = ?');
$st->execute([$id]);
$path = $st->fetchColumn();

if ($path) {
  $full = dirname(__DIR__) . '/site/' . ltrim($path, '/');
  if (file_exists($full)) @unlink($full);
  // try to remove directory if empty
  $dir = dirname($full);
  @rmdir($dir);
}

$pdo->prepare('UPDATE users SET photo_path = NULL, photo_consent = 0 WHERE id = ?')->execute([$id]);

echo 'ok';
