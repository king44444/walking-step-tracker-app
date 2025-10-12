<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/lib/awards_settings.php';

try {
  $settings = awards_settings_load();
  $payload = $settings; // naked JSON object per spec

  $etag = 'W/"' . sha1(json_encode($payload, JSON_UNESCAPED_SLASHES)) . '"';
  header('ETag: ' . $etag);
  header('Cache-Control: public, max-age=3600');

  $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
  if ($ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
  }

  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'server_error']);
}

