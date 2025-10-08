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

function norm_int($v) {
  if ($v === '' || $v === null) return null;
  if (!is_numeric($v)) return null;
  $i = (int)$v;
  if ($i < 0) $i = 0;
  return $i;
}

try {
  $in = array_merge($_POST, json_input());
  $pdo = pdo();

  // Delete by id
  if (($in['action'] ?? '') === 'delete' || ($in['action'] ?? '') === 'delete_entry') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'invalid_id']); exit; }
    $pdo->prepare('DELETE FROM entries WHERE id = :id')->execute([':id'=>$id]);
    echo json_encode(['ok'=>true]);
    exit;
  }

  // Save single entry
  if (($in['action'] ?? '') === 'save_entry' || isset($in['entries'])) {
    $rows = [];
    if (isset($in['entries']) && is_array($in['entries'])) {
      $rows = $in['entries'];
    } else {
      $rows[] = $in;
    }

    $pdo->beginTransaction();
    try {
      foreach ($rows as $r) {
        $week = trim((string)($r['week'] ?? ''));
        $name = trim((string)($r['name'] ?? ''));
        if ($week === '' || $name === '') throw new Exception('week and name required');

        // Ensure week row exists (label defaults to ISO date)
        $pdo->prepare("INSERT INTO weeks(week, label, finalized) VALUES(:w,:l,0) ON CONFLICT(week) DO NOTHING")
            ->execute([':w'=>$week, ':l'=>$week]);

        $vals = [
          ':mo'=> norm_int($r['monday']    ?? $r['mon'] ?? null),
          ':tu'=> norm_int($r['tuesday']   ?? $r['tue'] ?? null),
          ':we'=> norm_int($r['wednesday'] ?? $r['wed'] ?? null),
          ':th'=> norm_int($r['thursday']  ?? $r['thu'] ?? null),
          ':fr'=> norm_int($r['friday']    ?? $r['fri'] ?? null),
          ':sa'=> norm_int($r['saturday']  ?? $r['sat'] ?? null),
          ':tag'=> ($r['tag'] ?? null),
        ];

        if (!empty($r['id'])) {
          // update by id
          $vals[':id'] = (int)$r['id'];
          $pdo->prepare("UPDATE entries SET monday=:mo,tuesday=:tu,wednesday=:we,thursday=:th,friday=:fr,saturday=:sa,tag=:tag,updated_at=datetime('now') WHERE id=:id")
              ->execute($vals);
        } else {
          // upsert by (week,name) if present, otherwise insert
          $sel = $pdo->prepare('SELECT id FROM entries WHERE week=:w AND name=:n LIMIT 1');
          $sel->execute([':w'=>$week, ':n'=>$name]);
          $id = $sel->fetchColumn();
          if ($id) {
            $vals[':id'] = (int)$id;
            $pdo->prepare("UPDATE entries SET monday=:mo,tuesday=:tu,wednesday=:we,thursday=:th,friday=:fr,saturday=:sa,tag=:tag,updated_at=datetime('now') WHERE id=:id")
                ->execute($vals);
          } else {
            $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sex,age,tag)
                           VALUES(:w,:n,:mo,:tu,:we,:th,:fr,:sa,NULL,NULL,:tag)")
                ->execute(array_merge([':w'=>$week, ':n'=>$name], $vals));
          }
        }
      }
      $pdo->commit();
      echo json_encode(['ok'=>true]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    exit;
  }

  echo json_encode(['ok'=>false,'error'=>'bad_request']);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
