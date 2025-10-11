<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Env;
use App\Core\Router;

App\Core\Env::bootstrap(__DIR__ . '/..'); // load .env

$router = new Router();
require __DIR__ . '/../routes/web.php';

// Normalize path for subdirectory deployments (e.g., /dev/html/walk)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = '/dev/html/walk';
if (strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
    if ($path === '') $path = '/';
}
$router->dispatch($_SERVER['REQUEST_METHOD'], $path);
