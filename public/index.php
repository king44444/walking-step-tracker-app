<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Env;
use App\Core\Router;

App\Core\Env::bootstrap(__DIR__ . '/..'); // load .env

$router = new Router();
require __DIR__ . '/../routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
