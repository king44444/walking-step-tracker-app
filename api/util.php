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
  // Enable foreign key constraints for SQLite.
  $instance->exec('PRAGMA foreign_keys=ON');
  return $instance;
}

function read_raw_post(): string {
  $s = file_get_contents('php://input');
  return $s === false ? '' : $s;
}
