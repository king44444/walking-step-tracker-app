<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../app/Security/Csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$t = \App\Security\Csrf::token();
echo json_encode(['token' => $t], JSON_UNESCAPED_SLASHES);

