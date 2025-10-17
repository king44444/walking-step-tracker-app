<?php
$path = getenv('DB_PATH') ?: __DIR__ . '/data/walkweek.sqlite';
$dbDir = dirname($path);
$dbName = basename($path, '.sqlite');
return [
  'paths' => [
    'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
    'seeds' => '%%PHINX_CONFIG_DIR%%/database/seeds',
  ],
  'environments' => [
    'default_environment' => 'prod',
    'prod' => [
      'adapter' => 'sqlite',
      'name' => $dbName,
      'path' => $dbDir,
      'suffix' => '.sqlite',
      'connection' => new PDO('sqlite:' . $path),
    ],
  ],
  'version_order' => 'creation'
];
