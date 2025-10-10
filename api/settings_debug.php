<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/lib/config.php';

require_admin();

try {
  $pdo = cfg_pdo();
  $stmt = $pdo->query("SELECT key, value, updated_at FROM settings WHERE key LIKE 'ai.%' ORDER BY key");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  echo json_encode([
    'ok' => true,
    'settings' => $rows,
    'count' => count($rows)
  ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
