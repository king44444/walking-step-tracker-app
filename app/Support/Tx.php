<?php

namespace App\Support;

use App\Config\DB;
use Throwable;

final class Tx
{
    /**
     * Run $fn inside a SQLite transaction using BEGIN IMMEDIATE.
     * Retries on error (e.g., "database is locked") with exponential backoff.
     *
     * NOTE: If $pdo->inTransaction() is true we will call $fn directly to avoid nested
     * transactions (assume caller manages the transaction).
     *
     * @param callable $fn function(PDO $pdo)
     * @param int $retries number of retries on failure
     * @param int $sleepMs initial sleep in milliseconds
     * @param int $maxSleepMs maximum sleep between retries in milliseconds
     * @return mixed
     * @throws Throwable
     */
    public static function with(callable $fn, int $retries = 5, int $sleepMs = 200, int $maxSleepMs = 5000)
    {
        $pdo = DB::pdo();

        // If already in a transaction, avoid starting another.
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }

        $attempt = 0;
        $sleep = max(1, $sleepMs);

        while (true) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                $res = $fn($pdo);
                $pdo->exec('COMMIT');
                return $res;
            } catch (Throwable $e) {
                // Ensure we rollback if we started a transaction.
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->exec('ROLLBACK');
                    }
                } catch (Throwable $rb) {
                    // swallow rollback errors so the original exception is preserved
                }

                if ($attempt >= $retries) {
                    throw $e;
                }

                // Exponential backoff with small jitter (+/-10%)
                $jitter = (int)round($sleep * 0.1);
                $rand = ($jitter > 0) ? mt_rand(-$jitter, $jitter) : 0;
                $wait = max(1, min($maxSleepMs, $sleep + $rand));

                usleep($wait * 1000);

                // increase sleep for next attempt
                $sleep = min($maxSleepMs, $sleep * 2);
                $attempt++;
            }
        }
    }
}
