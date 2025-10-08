<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/lib/outbound.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/../app/Security/Csrf.php';

require_admin();
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!\App\Security\Csrf::validate((string)$csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'invalid_csrf']); exit; }
$pdo = pdo();

$week = isset($_POST['week']) ? (string)$_POST['week'] : '';
$where = 'approved_by IS NOT NULL AND sent_at IS NULL';
$params = [];
if ($week !== '') { $where .= ' AND week = :w'; $params[':w'] = $week; }

$st = $pdo->prepare("SELECT m.id, m.user_id, m.type, u.name, u.phone_e164 AS to_phone, m.content
                      FROM ai_messages m LEFT JOIN users u ON u.id=m.user_id
                      WHERE $where ORDER BY m.created_at ASC");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$sent = []; $errors=[]; $skipped=[];
// Global and category flags
$globalOn = setting_get('ai.enabled', '1') === '1';
$nudgeOn  = setting_get('ai.nudge.enabled', '1') === '1';
$recapOn  = setting_get('ai.recap.enabled', '1') === '1';
$awardOn  = setting_get('ai.award.enabled', '1') === '1';
if (!$globalOn) {
  error_log('[ai] skipped category=all reason=ai.disabled');
  echo json_encode(['ok'=>true,'sent_ids'=>[],'error_ids'=>[],'skipped'=>true,'reason'=>'ai.disabled']);
  return;
}
foreach ($rows as $r) {
  $id = (int)$r['id'];
  $type = (string)($r['type'] ?? '');
  $to = (string)($r['to_phone'] ?? '');
  $body = (string)($r['content'] ?? '');
  // Per-category enforcement
  if ($type === 'nudge' && !$nudgeOn) { $skipped[] = $id; error_log('[ai] skipped category=nudge reason=nudge.disabled id='.$id); continue; }
  if ($type === 'recap' && !$recapOn) { $skipped[] = $id; error_log('[ai] skipped category=recap reason=recap.disabled id='.$id); continue; }
  if ($type === 'award' && !$awardOn) { $skipped[] = $id; error_log('[ai] skipped category=award reason=award.disabled id='.$id); continue; }
  if ($to === '' || $body === '') { $errors[] = $id; continue; }
  try {
    $res = send_outbound_sms($to, $body);
    $sid = $res['sid'] ?? null;
    $upd = $pdo->prepare('UPDATE ai_messages SET sent_at = datetime(\'now\'), provider = COALESCE(provider,\'openrouter\') WHERE id = :id');
    $upd->execute([':id' => $id]);
    $sent[] = $id;
    usleep(200000); // 200ms
  } catch (Throwable $e) {
    $errors[] = $id;
  }
}
echo json_encode(['ok'=>true, 'sent_ids'=>$sent, 'error_ids'=>$errors, 'skipped_ids'=>$skipped]);
