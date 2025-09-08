<?php
declare(strict_types=1);

function pdo(): PDO {
  $dbPath = __DIR__ . '/../data/walkweek.sqlite';
  $dir = dirname($dbPath);
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

  // Use a single shared PDO instance per-process where possible to avoid
  // repeatedly opening the SQLite file.
  static $instance = null;
  if ($instance instanceof PDO) {
    return $instance;
  }

  $instance = new PDO('sqlite:' . $dbPath);
  $instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $instance->setAttribute(PDO::ATTR_TIMEOUT, 5);            // seconds
  // Enable foreign key constraints for SQLite.
  $instance->exec('PRAGMA foreign_keys=ON');
  $instance->exec('PRAGMA journal_mode=WAL;');               // readers+writer
  $instance->exec('PRAGMA busy_timeout=60000;');             // ms
  $instance->exec('PRAGMA synchronous=NORMAL;');             // faster WAL commits
  $instance->exec('PRAGMA wal_autocheckpoint=1000;');        // limit WAL size
  return $instance;
}

function read_raw_post(): string {
  $s = file_get_contents('php://input');
  return $s === false ? '' : $s;
}
