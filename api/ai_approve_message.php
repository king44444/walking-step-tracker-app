<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';

require_admin();
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!\App\Security\Csrf::validate((string)$csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'invalid_csrf']); exit; }
$admin = require_admin_username();
$pdo = pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { echo json_encode(['ok'=>false,'error'=>'bad_method']); exit; }
$id = (int)($_POST['id'] ?? 0);
$approved = (string)($_POST['approved'] ?? '0') === '1';
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }

if ($approved) {
  $st = $pdo->prepare('UPDATE ai_messages SET approved_by = :u WHERE id = :id');
  $st->execute([':u'=> $admin, ':id'=>$id]);
} else {
  $st = $pdo->prepare('UPDATE ai_messages SET approved_by = NULL WHERE id = :id');
  $st->execute([':id'=>$id]);
}
echo json_encode(['ok'=>true]);
