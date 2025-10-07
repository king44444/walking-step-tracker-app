<?php

namespace App\Config;

use PDO;

final class DB
{
    public static function pdo(): PDO
    {
        // Memoize a single PDO instance per-request to ensure callers share the same connection.
        static $instance = null;
        if ($instance instanceof PDO) {
            return $instance;
        }

        $dbPath = $_ENV['DB_PATH'] ?? (__DIR__ . '/../../data/walkweek.sqlite');
        // Normalize relative DB_PATH to project root (two dirs up from this file)
        if (!preg_match('~^/|^[A-Za-z]:(\\\\|/)~', $dbPath)) { // not absolute (POSIX or Windows)
            $base = dirname(__DIR__, 2); // project root
            $dbPath = rtrim($base, '/\\') . '/' . ltrim($dbPath, './\\');
        }
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);

        // Core pragmas to make SQLite resilient under concurrency
        try {
            // enable foreign keys
            $pdo->exec('PRAGMA foreign_keys=ON');

            // busy timeout (milliseconds) — also keep PDO::ATTR_TIMEOUT (seconds) for driver-level waits
            $pdo->exec('PRAGMA busy_timeout=10000');

            // Ensure WAL mode is enabled and verify result
            $current = strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn());
            if ($current !== 'wal') {
                // request WAL; sqlite returns the new mode when setting it
                $pdo->exec('PRAGMA journal_mode=WAL');
                $current = strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn());
            }

            // Other performance/resilience pragmas
            $pdo->exec('PRAGMA synchronous=NORMAL');
            $pdo->exec('PRAGMA temp_store=MEMORY');
            $pdo->exec('PRAGMA wal_autocheckpoint=1000');
            $pdo->exec('PRAGMA journal_size_limit=67108864');
        } catch (\Throwable $e) {
            // Avoid hard-failing here; log and continue with best-effort pragmas
            error_log('DB::pdo PRAGMA setup failed: ' . $e->getMessage());
        }

        $instance = $pdo;
        return $instance;
    }
}
