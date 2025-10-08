<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/admin_auth.php';
require_admin();

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

require_once __DIR__ . '/../app/Security/Csrf.php';

function j200($a){ echo json_encode($a, JSON_UNESCAPED_SLASHES); exit; }

try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $csrf = $_SERVER['HTTP_X_CSRF'] ?? '';
  if (!\App\Security\Csrf::validate((string)$csrf)) { j200(['ok'=>false,'error'=>'invalid_csrf']); }

  // Fetch model list from OpenRouter
  $ch = curl_init('https://openrouter.ai/api/v1/models');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_HTTPHEADER => [ 'Accept: application/json' ],
  ]);
  $res = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($res === false || $http >= 400) {
    j200(['ok'=>false,'error'=>'fetch_failed','http'=>$http]);
  }

  $json = json_decode($res, true);
  if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
    j200(['ok'=>false,'error'=>'bad_response']);
  }

  $out = [];
  foreach ($json['data'] as $m) {
    $arch = $m['architecture'] ?? [];
    $outs = $arch['output_modalities'] ?? [];
    if (!is_array($outs)) continue;
    $outs = array_map('strtolower', $outs);
    if (!in_array('image', $outs, true)) continue; // image-capable only
    $out[] = [ 'id' => (string)($m['id'] ?? ''), 'name' => (string)($m['name'] ?? ($m['id'] ?? '')) ];
  }

  // Sort by name to keep stable
  usort($out, function($a,$b){ return strcasecmp($a['name'], $b['name']); });

  // Save to public assets so admin can fetch it directly
  $root = dirname(__DIR__);
  $dir = $root . '/public/assets/models';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $path = $dir . '/ai_image_models.json';
  $payload = [ 'updated_at' => date('c'), 'models' => $out ];
  $ok = @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)) !== false;
  if (!$ok) { j200(['ok'=>false,'error'=>'write_failed']); }

  j200(['ok'=>true, 'count'=>count($out)]);
} catch (Throwable $e) {
  j200(['ok'=>false,'error'=>'server_error']);
}

