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

require_once __DIR__ . '/../app/Security/Csrf.php';

function j200($arr){ echo json_encode($arr, JSON_UNESCAPED_SLASHES); exit; }

try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $csrf = $_SERVER['HTTP_X_CSRF'] ?? '';
  if (!\App\Security\Csrf::validate((string)$csrf)) {
    // Log CSRF failure for debugging
    error_log("CSRF validation failed. Token: '$csrf', Session tokens: " . json_encode($_SESSION['csrf_tokens'] ?? []));
    j200(['ok'=>false,'error'=>'invalid_csrf']);
  }

  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  if (!is_array($j)) { $j = $_POST ?: []; }

  $userId = (int)($j['user_id'] ?? 0);
  $kind = (string)($j['kind'] ?? '');
  $milestone = (int)($j['milestone_value'] ?? 0);
  $force = (bool)($j['force'] ?? false);

  if ($userId <= 0 || $kind === '' || $milestone <= 0) {
    j200(['ok'=>false,'error'=>'bad_request']);
  }

  $pdo = DB::pdo();
  ob_start(); require_once __DIR__ . '/migrate.php'; ob_end_clean();
  $st = $pdo->prepare('SELECT id,name,interests FROM users WHERE id = :id LIMIT 1');
  $st->execute([':id'=>$userId]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) { j200(['ok'=>false,'error'=>'user_not_found']); }
  $userName = (string)$u['name'];

  // Respect flags (log skips)
  $ai = (string)setting_get('ai.enabled', '1');
  if ($ai !== '1') { ai_image_log_event($userId, $userName, $kind, $milestone, 'skipped', 'ai.disabled', 'fallback', null, null, null); j200(['ok'=>true,'skipped'=>true,'reason'=>'ai.disabled']); }
  $aw = (string)setting_get('ai.award.enabled', '1');
  if ($aw !== '1') { ai_image_log_event($userId, $userName, $kind, $milestone, 'skipped', 'award.disabled', 'fallback', null, null, null); j200(['ok'=>true,'skipped'=>true,'reason'=>'award.disabled']); }

  // Check if we can generate images at all
  if (!ai_image_can_generate()) {
    $reason = 'not_configured';
    $ai_check = (string)setting_get('ai.enabled', '1');
    $aw_check = (string)setting_get('ai.award.enabled', '1');
    if ($ai_check !== '1') $reason = 'ai.disabled';
    elseif ($aw_check !== '1') $reason = 'award.disabled';
    ai_image_log_event($userId, $userName, $kind, $milestone, 'skipped', $reason, 'fallback', null, null, null);
    j200(['ok'=>true,'skipped'=>true,'reason'=>$reason]);
  }

  // Ensure ai_awards row
  $chk = $pdo->prepare('SELECT id, image_path FROM ai_awards WHERE user_id = :uid AND kind = :k AND milestone_value = :v LIMIT 1');
  $chk->execute([':uid'=>$userId, ':k'=>$kind, ':v'=>$milestone]);
  $row = $chk->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $pdo->prepare('INSERT INTO ai_awards(user_id,kind,milestone_value,week,image_path,meta) VALUES(:uid,:k,:v,NULL,NULL,NULL)')
        ->execute([':uid'=>$userId, ':k'=>$kind, ':v'=>$milestone]);
  }

  // Generate (or reuse) image
  $res = ai_image_generate([
    'user_id' => $userId,
    'user_name' => $userName,
    'user' => $u,  // Pass complete user object for lifetime awards
    'award_kind' => $kind,
    'milestone_value' => $milestone,
    'force' => $force,
  ]);

  if (($res['ok'] ?? false) !== true) {
    j200(['ok'=>false,'error'=>$res['error'] ?? 'provider_failed']);
  }

  // Normalize stored image_path to omit leading 'assets/' (so site/user.php builds correctly)
  $retPath = (string)$res['path']; // e.g., assets/awards/{uid}/file.webp
  $storePath = preg_replace('#^assets/#', '', $retPath);
  $meta = $res['meta'] ?? null; $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null;

  // Update row (allow overwrite when force)
  $pdo->prepare('UPDATE ai_awards SET image_path = :p, meta = :m WHERE user_id = :uid AND kind = :k AND milestone_value = :v')
      ->execute([':p'=>$storePath, ':m'=>$metaJson, ':uid'=>$userId, ':k'=>$kind, ':v'=>$milestone]);

  j200(['ok'=>true, 'path'=>$retPath]);
} catch (Throwable $e) {
  // Do not leak; return clean JSON error
  try {
    $dir = dirname(__DIR__) . '/data/logs/ai'; if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents($dir . '/award_images.log', '['.date('c')."] endpoint_error " . $e->getMessage() . "\n", FILE_APPEND);
  } catch (Throwable $e2) {}
  j200(['ok'=>false,'error'=>'server_error']);
}
