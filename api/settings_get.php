<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/lib/settings.php';

require_admin();

try {
  $resp = [
    'ai.enabled' => setting_get('ai.enabled', '1') === '1',
    'ai.nudge.enabled' => setting_get('ai.nudge.enabled', '1') === '1',
    'ai.recap.enabled' => setting_get('ai.recap.enabled', '1') === '1',
    'ai.award.enabled' => setting_get('ai.award.enabled', '1') === '1',
  ];
  echo json_encode($resp);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error']);
}

