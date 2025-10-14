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
// Determine the deployed base path dynamically (parent of /public)
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$script = $_SERVER['SCRIPT_NAME'] ?? '';
// Derive project base as parent of the public/ directory in the script path
$scriptDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
$base = rtrim(dirname($scriptDir), '/');
if ($base !== '' && $base !== '/' && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
}
if ($path === '') { $path = '/'; }
// Expose for debug routes
$_SERVER['X_ROUTER_PATH'] = $path;
$router->dispatch($_SERVER['REQUEST_METHOD'], $path);
