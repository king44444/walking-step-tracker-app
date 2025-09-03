<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// TEMP DEBUG (remove after fix)
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
  require __DIR__ . '/db.php';

  $rows = $pdo->query("SELECT week, COALESCE(label, week) AS label, finalized FROM weeks ORDER BY week DESC")->fetchAll();
  echo json_encode(['weeks' => $rows], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
