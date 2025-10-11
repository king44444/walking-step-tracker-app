<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

use App\Controllers\AdminSmsController;

$controller = new AdminSmsController();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'index';

// Route to controller methods
if ($method === 'GET') {
    if ($action === 'index') {
        echo $controller->index();
    }
    elseif ($action === 'messages') {
        echo $controller->messages();
    }
    else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
}
elseif ($method === 'POST') {
    if ($action === 'send') {
        echo $controller->send();
    }
    elseif ($action === 'upload') {
        echo $controller->upload();
    }
    elseif ($action === 'start-user') {
        echo $controller->startUser();
    }
    else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
}
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
