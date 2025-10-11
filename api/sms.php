<?php
// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');

// Bootstrap and delegate to controller
require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

$smsController = new \App\Controllers\SmsController();
$smsController->inbound();
