<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/admin_auth.php';
require_admin();

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;

require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/ai_images.php';
require_once __DIR__ . '/lib/award_labels.php';

require_once __DIR__ . '/../app/Security/Csrf.php';

function j200($a){ echo json_encode($a, JSON_UNESCAPED_SLASHES); exit; }

try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $csrf = $_SERVER['HTTP_X_CSRF'] ?? '';
  if (!\App\Security\Csrf::validate((string)$csrf)) { j200(['ok'=>false,'error'=>'invalid_csrf']); }

  // Respect flags
  $ai = (string)setting_get('ai.enabled', '1');
  if ($ai !== '1') { j200(['ok'=>true,'skipped'=>0,'generated'=>0,'errors'=>0,'reason'=>'ai.disabled']); }
  $aw = (string)setting_get('ai.award.enabled', '1');
  if ($aw !== '1') { j200(['ok'=>true,'skipped'=>0,'generated'=>0,'errors'=>0,'reason'=>'award.disabled']); }

  // Increase execution time for batch processing
  set_time_limit(120);

  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  if (!is_array($j)) { $j = $_POST ?: []; }
  $kindFilter = isset($j['kind']) ? (string)$j['kind'] : '';
  $limit = isset($j['limit']) ? max(1, min((int)$j['limit'], 50)) : 10; // Default 10, max 50

  $pdo = DB::pdo();
  ob_start(); require_once __DIR__ . '/migrate.php'; ob_end_clean();

  // First, get total count of missing images
  $countSql = 'SELECT COUNT(*) FROM ai_awards a WHERE (a.image_path IS NULL OR a.image_path = "")';
  $countParams = [];
  if ($kindFilter !== '') { $countSql .= ' AND a.kind = :k'; $countParams[':k'] = $kindFilter; }
  $stCount = $pdo->prepare($countSql); $stCount->execute($countParams);
  $totalMissing = (int)$stCount->fetchColumn();

  // Now fetch limited batch to process
  $sql = 'SELECT a.user_id, a.kind, a.milestone_value, u.name FROM ai_awards a JOIN users u ON u.id = a.user_id WHERE (a.image_path IS NULL OR a.image_path = "")';
  $params = [];
  if ($kindFilter !== '') { $sql .= ' AND a.kind = :k'; $params[':k'] = $kindFilter; }
  $sql .= ' ORDER BY a.created_at ASC LIMIT ' . $limit;
  $st = $pdo->prepare($sql); $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $gen=0; $skip=0; $err=0;
  foreach ($rows as $r) {
    $uid = (int)$r['user_id'];
    $name = (string)$r['name'];
    $kind = (string)$r['kind'];
    $val = (int)$r['milestone_value'];
    try {
      $res = ai_image_generate(['user_id'=>$uid,'user_name'=>$name,'award_kind'=>$kind,'milestone_value'=>$val,'style'=>'badge','force'=>false]);
      if (($res['ok'] ?? false) !== true) { $err++; continue; }
      $retPath = (string)$res['path'];
      $storePath = preg_replace('#^assets/#', '', $retPath);
      $metaJson = isset($res['meta']) ? json_encode($res['meta'], JSON_UNESCAPED_SLASHES) : null;
      $pdo->prepare('UPDATE ai_awards SET image_path = :p, meta = :m WHERE user_id = :uid AND kind = :k AND milestone_value = :v')
          ->execute([':p'=>$storePath, ':m'=>$metaJson, ':uid'=>$uid, ':k'=>$kind, ':v'=>$val]);
      $gen++;
    } catch (Throwable $e) { $err++; }
  }

  // Calculate remaining count after this batch
  $remaining = max(0, $totalMissing - $gen - $err);
  j200(['ok'=>true, 'generated'=>$gen, 'skipped'=>$skip, 'errors'=>$err, 'remaining'=>$remaining, 'total_missing'=>$totalMissing]);
} catch (Throwable $e) {
  try {
    $dir = dirname(__DIR__) . '/data/logs/ai'; if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents($dir . '/award_images.log', '['.date('c')."] endpoint_error " . $e->getMessage() . "\n", FILE_APPEND);
  } catch (Throwable $e2) {}
  j200(['ok'=>false,'error'=>'server_error']);
}
