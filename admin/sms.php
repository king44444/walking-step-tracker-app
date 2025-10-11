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

// Route to controller methods
if ($method === 'GET') {
    // /admin/sms.php -> index
    if (preg_match('#^/admin/sms\.php$#', $path)) {
        echo $controller->index();
    }
    // /admin/sms.php/messages -> messages
    elseif (preg_match('#^/admin/sms\.php/messages$#', $path)) {
        echo $controller->messages();
    }
    else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
}
elseif ($method === 'POST') {
    // /admin/sms.php/send -> send
    if (preg_match('#^/admin/sms\.php/send$#', $path)) {
        echo $controller->send();
    }
    // /admin/sms.php/upload -> upload
    elseif (preg_match('#^/admin/sms\.php/upload$#', $path)) {
        echo $controller->upload();
    }
    // /admin/sms.php/start-user -> startUser
    elseif (preg_match('#^/admin/sms\.php/start-user$#', $path)) {
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
