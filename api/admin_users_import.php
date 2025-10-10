<?php
declare(strict_types=1);

try {
  require_once __DIR__ . '/../vendor/autoload.php';
  \App\Core\Env::bootstrap(dirname(__DIR__));
  require_once __DIR__ . '/lib/admin_auth.php';
  require_admin();

  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true) ?: $_POST;
  $csrf = (string)($data['csrf'] ?? '');

  if (!\App\Security\Csrf::validate($csrf)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'invalid_csrf']);
    exit;
  }

  $users = $data['users'] ?? null;
  if (!is_array($users)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'invalid_payload', 'message'=>'Expected "users" array.']);
    exit;
  }

  if (count($users) === 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'empty_payload', 'message'=>'No users provided.']);
    exit;
  }

  $pdo = \App\Config\DB::pdo();

  $insertWithIdSql = "INSERT INTO users(id, name, sex, age, tag, photo_path, photo_consent, phone_e164, is_active, ai_opt_in, interests, rival_id, created_at, updated_at)
VALUES(:id, :name, :sex, :age, :tag, :photo_path, :photo_consent, :phone_e164, :is_active, :ai_opt_in, :interests, :rival_id, :created_at, :updated_at)
ON CONFLICT(id) DO UPDATE SET
  name=excluded.name,
  sex=excluded.sex,
  age=excluded.age,
  tag=excluded.tag,
  photo_path=excluded.photo_path,
  photo_consent=excluded.photo_consent,
  phone_e164=excluded.phone_e164,
  is_active=excluded.is_active,
  ai_opt_in=excluded.ai_opt_in,
  interests=excluded.interests,
  rival_id=excluded.rival_id,
  created_at=excluded.created_at,
  updated_at=excluded.updated_at
";
  // Prepared statements
  $stmtWithId = $pdo->prepare($insertWithIdSql);

  $insertNoIdSql = "INSERT INTO users(name, sex, age, tag, photo_path, photo_consent, phone_e164, is_active, ai_opt_in, interests, rival_id, created_at, updated_at)
VALUES(:name, :sex, :age, :tag, :photo_path, :photo_consent, :phone_e164, :is_active, :ai_opt_in, :interests, :rival_id, :created_at, :updated_at)";
  $stmtNoId = $pdo->prepare($insertNoIdSql);

  $inserted = 0;
  $updated = 0;
  $errors = [];

  $pdo->beginTransaction();
  try {
    foreach ($users as $i => $u) {
      if (!is_array($u)) { $errors[] = "item[$i] is not an object/array"; continue; }

      // Normalize keys we expect
      $id = isset($u['id']) && $u['id'] !== '' ? (int)$u['id'] : null;
      $name = isset($u['name']) ? (string)$u['name'] : null;
      if ($name === null || $name === '') { $errors[] = "item[$i] missing name"; continue; }
      $sex = array_key_exists('sex', $u) ? ($u['sex'] === null ? null : (string)$u['sex']) : null;
      $age = isset($u['age']) && $u['age'] !== '' ? (int)$u['age'] : null;
      $tag = array_key_exists('tag', $u) ? ($u['tag'] === null ? null : (string)$u['tag']) : null;
      $photo_path = array_key_exists('photo_path', $u) ? ($u['photo_path'] === null ? null : (string)$u['photo_path']) : null;
      $photo_consent = array_key_exists('photo_consent', $u) ? (int)$u['photo_consent'] : 0;
      $phone_e164 = array_key_exists('phone_e164', $u) ? ($u['phone_e164'] === null ? null : (string)$u['phone_e164']) : null;
      $is_active = array_key_exists('is_active', $u) ? (int)$u['is_active'] : 0;
      $ai_opt_in = array_key_exists('ai_opt_in', $u) ? (int)$u['ai_opt_in'] : 0;
      $interests = array_key_exists('interests', $u) ? ($u['interests'] === null ? '' : (string)$u['interests']) : '';
      $rival_id = array_key_exists('rival_id', $u) && $u['rival_id'] !== '' && $u['rival_id'] !== null ? (int)$u['rival_id'] : null;
      $created_at = array_key_exists('created_at', $u) ? ($u['created_at'] === null ? null : (string)$u['created_at']) : null;
      $updated_at = array_key_exists('updated_at', $u) ? ($u['updated_at'] === null ? null : (string)$u['updated_at']) : null;

      if ($id !== null) {
        $ok = $stmtWithId->execute([
          ':id'=>$id,
          ':name'=>$name,
          ':sex'=>$sex,
          ':age'=>$age,
          ':tag'=>$tag,
          ':photo_path'=>$photo_path,
          ':photo_consent'=>$photo_consent,
          ':phone_e164'=>$phone_e164,
          ':is_active'=>$is_active,
          ':ai_opt_in'=>$ai_opt_in,
          ':interests'=>$interests,
          ':rival_id'=>$rival_id,
          ':created_at'=>$created_at,
          ':updated_at'=>$updated_at
        ]);
        if ($ok) {
          // Determine whether insert or update by checking changes: rowCount==1 for insert, 0 for update when values same.
          // SQLite returns 1 for insert, 1 for update (??). We'll detect existence prior to operation to classify.
          // Simpler: check whether a user with that id existed before.
          $pre = $pdo->prepare("SELECT 1 FROM users WHERE id = :id LIMIT 1");
          $pre->execute([':id'=>$id]);
          $existed = (bool)$pre->fetchColumn();
          if ($existed) $updated++;
          else $inserted++;
        } else {
          $errors[] = "db_error on item[$i]";
        }
      } else {
        $ok = $stmtNoId->execute([
          ':name'=>$name,
          ':sex'=>$sex,
          ':age'=>$age,
          ':tag'=>$tag,
          ':photo_path'=>$photo_path,
          ':photo_consent'=>$photo_consent,
          ':phone_e164'=>$phone_e164,
          ':is_active'=>$is_active,
          ':ai_opt_in'=>$ai_opt_in,
          ':interests'=>$interests,
          ':rival_id'=>$rival_id,
          ':created_at'=>$created_at,
          ':updated_at'=>$updated_at
        ]);
        if ($ok) $inserted++;
        else $errors[] = "db_error on item[$i]";
      }
    }

    if (count($errors) > 0) {
      $pdo->rollBack();
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false, 'error'=>'validation_errors', 'errors'=>$errors]);
      exit;
    }

    $pdo->commit();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true, 'inserted'=>$inserted, 'updated'=>$updated]);
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
  exit;
}
