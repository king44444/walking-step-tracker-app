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

function json_input(): array {
  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

try {
  $in = array_merge($_POST, json_input());
  $week = trim((string)($in['week'] ?? ''));
  $action = (string)($in['action'] ?? 'finalize');
  if ($week === '') { echo json_encode(['ok'=>false,'error'=>'week_required']); exit; }
  $pdo = pdo();

  if ($action === 'unfinalize') {
    $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM snapshots WHERE week = :w')->execute([':w'=>$week]);
      $pdo->prepare("UPDATE weeks SET finalized=0, finalized_at=NULL WHERE week=:w")->execute([':w'=>$week]);
      $pdo->commit();
      echo json_encode(['ok'=>true, 'unfinalized'=>1]);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    exit;
  }

  // finalize: snapshot rows and mark finalized
  $q = $pdo->prepare("SELECT name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag FROM entries WHERE week=:w ORDER BY LOWER(name)");
  $q->execute([':w'=>$week]);
  $rows = $q->fetchAll();
  $json = json_encode($rows, JSON_UNESCAPED_SLASHES);

  $pdo->beginTransaction();
  try {
    $pdo->prepare("INSERT INTO snapshots(week,json) VALUES(:w,:j)
                   ON CONFLICT(week) DO UPDATE SET json=excluded.json, created_at=datetime('now')")
        ->execute([':w'=>$week, ':j'=>$json]);
    $pdo->prepare("UPDATE weeks SET finalized=1, finalized_at=datetime('now') WHERE week=:w")->execute([':w'=>$week]);
    // Best-effort: lock entries if column exists
    try { $pdo->prepare('UPDATE entries SET locked=1 WHERE week=:w')->execute([':w'=>$week]); } catch (Throwable $e) { /* ignore */ }
    $pdo->commit();
    echo json_encode(['ok'=>true, 'finalized'=>1]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
