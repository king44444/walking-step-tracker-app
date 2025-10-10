<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;

function jerr(int $code, string $msg) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

try {
  $pdo = DB::pdo();
  ob_start(); require_once __DIR__ . '/migrate.php'; ob_end_clean();

  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id <= 0) jerr(400, 'bad_id');

  $st = $pdo->prepare('SELECT id,name,sex,age,tag,photo_path,is_active FROM users WHERE id = :id LIMIT 1');
  $st->execute([':id'=>$id]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) jerr(404, 'not_found');
  $name = (string)$u['name'];

  // lifetime totals across entries by name
  $sum = $pdo->prepare("SELECT 
    COALESCE(SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)),0) AS total,
    SUM((CASE WHEN monday IS NOT NULL THEN 1 ELSE 0 END)
        +(CASE WHEN tuesday IS NOT NULL THEN 1 ELSE 0 END)
        +(CASE WHEN wednesday IS NOT NULL THEN 1 ELSE 0 END)
        +(CASE WHEN thursday IS NOT NULL THEN 1 ELSE 0 END)
        +(CASE WHEN friday IS NOT NULL THEN 1 ELSE 0 END)
        +(CASE WHEN saturday IS NOT NULL THEN 1 ELSE 0 END)) AS days,
    COALESCE(MAX(COALESCE(monday,0)),0) AS max_mon,
    COALESCE(MAX(COALESCE(tuesday,0)),0) AS max_tue,
    COALESCE(MAX(COALESCE(wednesday,0)),0) AS max_wed,
    COALESCE(MAX(COALESCE(thursday,0)),0) AS max_thu,
    COALESCE(MAX(COALESCE(friday,0)),0) AS max_fri,
    COALESCE(MAX(COALESCE(saturday,0)),0) AS max_sat
    FROM entries WHERE name = :n");
  $sum->execute([':n'=>$name]);
  $row = $sum->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'days'=>0,'max_mon'=>0,'max_tue'=>0,'max_wed'=>0,'max_thu'=>0,'max_fri'=>0,'max_sat'=>0];
  $total = (int)$row['total'];
  $days  = (int)$row['days'];
  $best  = max((int)$row['max_mon'],(int)$row['max_tue'],(int)$row['max_wed'],(int)$row['max_thu'],(int)$row['max_fri'],(int)$row['max_sat']);

  // weeks participated (>0 total in week)
  $weeks = (int)$pdo->prepare("SELECT COUNT(1) FROM entries WHERE name = :n AND 
    (COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)) > 0")
    ->execute([':n'=>$name]) || 0;
  // above won't fetch; do properly:
  $stWeeks = $pdo->prepare("SELECT COUNT(1) FROM entries WHERE name = :n AND 
    (COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)) > 0");
  $stWeeks->execute([':n'=>$name]);
  $weeks = (int)$stWeeks->fetchColumn();

  $avg = $days > 0 ? (int)round($total / $days) : 0;

  // compute rank: number of users with higher lifetime total + 1
  $stTotals = $pdo->query("SELECT name,
      COALESCE((SELECT SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)) FROM entries e WHERE e.name = u.name),0) AS total
    FROM users u");
  $rank = 1; $higher = 0;
  foreach ($stTotals->fetchAll(PDO::FETCH_ASSOC) as $t) { if ((int)$t['total'] > $total) $higher++; }
  $rank = $higher + 1;

  // awards
  $aw = $pdo->prepare('SELECT kind, milestone_value, image_path, created_at FROM ai_awards WHERE user_id = :id ORDER BY created_at ASC');
  $aw->execute([':id'=>$id]);
  $awards = array_map(function($r){
    return [
      'kind' => $r['kind'],
      'milestone_value' => (int)$r['milestone_value'],
      'image_path' => $r['image_path'],
      'created_at' => $r['created_at'],
    ];
  }, $aw->fetchAll(PDO::FETCH_ASSOC));

  echo json_encode(['ok'=>true,
    'user' => [
      'id'=>(int)$u['id'], 'name'=>$u['name'], 'sex'=>$u['sex'], 'age'=>$u['age'], 'tag'=>$u['tag'], 'photo_path'=>$u['photo_path'], 'is_active'=>$u['is_active']
    ],
    'lifetime' => [ 'total'=>$total, 'days'=>$days, 'avg'=>$avg, 'best'=>$best, 'weeks'=>$weeks, 'rank'=>$rank ],
    'awards' => $awards
  ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}

