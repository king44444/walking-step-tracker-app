<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

try {
  $path = dirname(__DIR__) . '/data/models/ai_image_models.json';
  if (!is_file($path)) {
    echo json_encode(['updated_at'=>null,'models'=>[]], JSON_UNESCAPED_SLASHES);
    exit;
  }
  $txt = file_get_contents($path);
  if ($txt === false) { echo json_encode(['updated_at'=>null,'models'=>[]]); exit; }
  $j = json_decode($txt, true);
  if (!is_array($j) || !isset($j['models'])) { echo json_encode(['updated_at'=>null,'models'=>[]]); exit; }
  echo json_encode($j, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  echo json_encode(['updated_at'=>null,'models'=>[]], JSON_UNESCAPED_SLASHES);
}

