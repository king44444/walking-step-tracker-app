<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';

// Protect: only admin may modify settings
require_admin();

// CSRF check
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!\App\Security\Csrf::validate((string)$csrf)) { echo json_encode(['ok'=>false,'error'=>'invalid_csrf']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') { echo json_encode(['ok'=>false,'error'=>'bad_method']); exit; }

$key = isset($_POST['key']) ? (string)$_POST['key'] : '';
$value = isset($_POST['value']) ? (string)$_POST['value'] : '';
if ($key === '') { echo json_encode(['ok'=>false,'error'=>'missing_key']); exit; }

// Optionally constrain keys in future; for now accept generic
set_setting($key, $value);
echo json_encode(['ok'=>true, 'key'=>$key, 'value'=>$value]);
