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
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Extract the relevant path part (everything after /admin/)
$adminPath = preg_replace('#^.*?/admin/#', '', $path);

// Route to controller methods
if ($method === 'GET') {
    // sms.php -> index
    if ($adminPath === 'sms.php') {
        echo $controller->index();
    }
    // sms.php/messages -> messages
    elseif ($adminPath === 'sms.php/messages') {
        echo $controller->messages();
    }
    else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
}
elseif ($method === 'POST') {
    // sms.php/send -> send
    if ($adminPath === 'sms.php/send') {
        echo $controller->send();
    }
    // sms.php/upload -> upload
    elseif ($adminPath === 'sms.php/upload') {
        echo $controller->upload();
    }
    // sms.php/start-user -> startUser
    elseif ($adminPath === 'sms.php/start-user') {
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
