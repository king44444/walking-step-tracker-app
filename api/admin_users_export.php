<?php
declare(strict_types=1);

try {
  require_once __DIR__ . '/../vendor/autoload.php';
  \App\Core\Env::bootstrap(dirname(__DIR__));
  require_once __DIR__ . '/lib/admin_auth.php';
  require_admin();

  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  // Admin auth already required by require_admin(); accept request without consuming the one-time CSRF token.
  // Read JSON body (optional)
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true) ?: $_POST;

  $pdo = \App\Config\DB::pdo();

  $stmt = $pdo->query("SELECT id, name, sex, age, tag, photo_path, photo_consent, phone_e164, is_active, ai_opt_in, interests, rival_id, created_at, updated_at FROM users ORDER BY id");
  $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true, 'users'=>$users]);
}
catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
  exit;
}
