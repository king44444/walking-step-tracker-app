<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/lib/admin_auth.php';

require_once __DIR__ . '/../app/Security/Csrf.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!\App\Security\Csrf::validate((string)$csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'invalid_csrf']); exit; }

function json_input(): array { $raw=file_get_contents('php://input')?:''; $j=json_decode($raw,true); return is_array($j)?$j:[]; }

try {
  $in = array_merge($_POST, json_input());
  $week = trim((string)($in['week'] ?? ''));
  if ($week === '') { echo json_encode(['ok'=>false,'error'=>'week_required']); exit; }
  $pdo = pdo();

  $added = 0; $skipped = 0;
  $pdo->beginTransaction();
  try {
    $q = $pdo->query("SELECT name,sex,age,tag FROM users WHERE is_active=1");
    $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                          VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                          ON CONFLICT(week,name) DO NOTHING");
    foreach ($q as $u) {
      $ins->execute([':w'=>$week, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age']?:null, ':tag'=>$u['tag']?:null]);
      $added += ($ins->rowCount() > 0) ? 1 : 0;
      $skipped += ($ins->rowCount() === 0) ? 1 : 0;
    }
    $pdo->commit();
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

  echo json_encode(['ok'=>true, 'added'=>$added, 'skipped'=>$skipped]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
