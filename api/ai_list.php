<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/lib/admin_auth.php';

require_admin();

$pdo = pdo();
$status = $_GET['status'] ?? 'unsent';
$where = '1=1';
if ($status === 'unsent') { $where = 'm.sent_at IS NULL'; }
elseif ($status === 'sent') { $where = 'm.sent_at IS NOT NULL'; }

$sql = "SELECT m.id, m.user_id, u.name AS user, m.week, m.content AS body, m.approved_by, m.sent_at, m.model
        FROM ai_messages m
        LEFT JOIN users u ON u.id = m.user_id
        WHERE $where
        ORDER BY m.created_at DESC, m.id DESC";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$out = array_map(function($r){
  return [
    'id' => (int)$r['id'],
    'user' => (string)($r['user'] ?? ''),
    'week' => (string)($r['week'] ?? ''),
    'body' => (string)($r['body'] ?? ''),
    'approved' => ($r['approved_by'] !== null && $r['approved_by'] !== '') ? 1 : 0,
    'sent_at' => $r['sent_at'] ?? null,
    'model' => (string)($r['model'] ?? ''),
  ];
}, $rows);
echo json_encode(['ok'=>true,'messages'=>$out]);

