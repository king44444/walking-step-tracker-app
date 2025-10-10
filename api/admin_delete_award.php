<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/lib/admin_auth.php';
require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!\App\Security\Csrf::validate((string)$csrf)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'invalid_csrf']);
  exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (empty($in)) { $in = $_POST; }

$id = isset($in['id']) ? (int)$in['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_id']);
  exit;
}

try {
  $pdo = \App\Config\DB::pdo();

  // Fetch image_path so we can remove the file if desired
  $st = $pdo->prepare('SELECT image_path FROM ai_awards WHERE id = :id LIMIT 1');
  $st->execute([':id' => $id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  // Delete DB row
  $del = $pdo->prepare('DELETE FROM ai_awards WHERE id = :id');
  $del->execute([':id' => $id]);

  // Attempt to remove file on disk if it exists and is under site/assets
  if (!empty($row['image_path'])) {
    // image_path is stored like "awards/7/filename.webp" -> actual file at site/assets/awards/7/filename.webp
    $rel = $row['image_path'];
    $fs = realpath(__DIR__ . '/../../site/assets') . '/' . ltrim($rel, '/');
    if ($fs && strpos($fs, realpath(__DIR__ . '/../../site/assets')) === 0 && file_exists($fs)) {
      @unlink($fs);
    }
  }

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  error_log('admin_delete_award error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
