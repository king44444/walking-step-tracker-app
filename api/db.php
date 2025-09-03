<?php
declare(strict_types=1);

$DB_PATH = __DIR__ . '/../data/walkweek.sqlite';
if (!file_exists(dirname($DB_PATH))) {
  mkdir(dirname($DB_PATH), 0775, true);
}
$pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA journal_mode=WAL;');
$pdo->exec('PRAGMA foreign_keys=ON;');
