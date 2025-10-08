<?php
declare(strict_types=1);

// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;
$pdo = DB::pdo();

function api_error(int $code, string $msg) {
  if (isset($_POST['redirect'])) {
    header('Location: ../admin/photos.php?err=' . urlencode($msg));
    exit;
  }
  http_response_code($code);
  exit($msg);
}

require_once __DIR__ . '/../app/Security/Csrf.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!\App\Security\Csrf::validate((string)$csrf)) { api_error(403, 'invalid_csrf'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(400, 'bad_method'); }
$id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
if ($id <= 0) { api_error(400, 'bad_input'); }

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

if (isset($_POST['redirect'])) {
  header('Location: ../admin/photos.php?ok=1');
  exit;
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode(['ok' => true]);
