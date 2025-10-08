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
  $target = trim((string)($in['target'] ?? $in['week'] ?? ''));
  $source = trim((string)($in['source'] ?? ''));
  if ($target === '') { echo json_encode(['ok'=>false,'error'=>'target_required']); exit; }
  $pdo = pdo();

  if ($source === '') {
    $st = $pdo->prepare('SELECT week FROM weeks WHERE week < :t ORDER BY week DESC LIMIT 1');
    $st->execute([':t'=>$target]);
    $source = (string)($st->fetchColumn() ?: '');
  }
  if ($source === '') { echo json_encode(['ok'=>false,'error'=>'no_source_week']); exit; }

  $pdo->beginTransaction();
  try {
    // Ensure target week exists
    $pdo->prepare('INSERT INTO weeks(week,label,finalized) VALUES(:w,:l,0) ON CONFLICT(week) DO NOTHING')
        ->execute([':w'=>$target, ':l'=>$target]);

    $sel = $pdo->prepare('SELECT name,sex,age,tag FROM entries WHERE week = :w');
    $sel->execute([':w'=>$source]);
    $rows = $sel->fetchAll();

    $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                          VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                          ON CONFLICT(week,name) DO NOTHING");
    $added = 0;
    foreach ($rows as $u) {
      $ins->execute([':w'=>$target, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age']?:null, ':tag'=>$u['tag']?:null]);
      $added += ($ins->rowCount() > 0) ? 1 : 0;
    }
    $pdo->commit();
    echo json_encode(['ok'=>true,'source'=>$source,'target'=>$target,'added'=>$added]);
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
