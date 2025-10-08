<?php
require_once __DIR__ . '/../db.php';

/**
 * Executes $fn($pdo) in a retrying IMMEDIATE transaction.
 *
 * Usage:
 *   with_txn_retry(function($pdo) {
 *     // do quick DB writes here
 *   });
 */
function with_txn_retry(callable $fn, int $retries = 5, int $sleepMs = 200) {
  $pdo = pdo();
  for ($i = 0; $i <= $retries; $i++) {
    try {
      $pdo->exec('BEGIN IMMEDIATE;');
      $res = $fn($pdo);
      $pdo->exec('COMMIT;');
      return $res;
    } catch (Throwable $e) {
      try { $pdo->exec('ROLLBACK;'); } catch (Throwable $_) {}
      if ($i === $retries) throw $e;
      usleep($sleepMs * 1000);
    }
  }
}
