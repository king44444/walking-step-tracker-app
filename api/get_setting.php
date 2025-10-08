<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/admin_auth.php';

// Protect this endpoint; admin page fetches it with Basic Auth
require_admin();

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if ($key === '') { echo json_encode(['ok'=>false,'error'=>'missing_key']); exit; }

$val = get_setting($key);
echo json_encode(['ok'=>true, 'key'=>$key, 'value'=>$val]);

