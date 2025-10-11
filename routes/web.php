<?php
use App\Core\Router;
use App\Controllers\AdminController;
use App\Controllers\SmsController;

/** @var Router $router */
$router->add('GET', '/', fn() => 'OK');
// Debug route has been removed per request
$router->add('GET', '/admin/ai', [new AdminController(), 'ai']); // kept for compatibility

// AI MVC routes removed; using file-based /api/*.php endpoints instead.

// SMS routes
$sms = new SmsController();
$router->add('POST', '/api/sms', [$sms, 'inbound']);
$router->add('POST', '/api/sms/status', [$sms, 'status']);
$router->add('POST', '/api/send-sms', [$sms, 'send']);

// Temporary compatibility routes for legacy Twilio webhooks during alpha.
// These avoid requiring the old files while Twilio is reconfigured.
// Legacy .php path mappings removed (Twilio now points to /api/sms).

// Admin UI: entries and users
$admin = new \App\Controllers\AdminController();
$router->add('GET', '/admin/entries', [$admin, 'entries']);
$router->add('POST', '/admin/entries/save', [$admin, 'saveEntries']);
$router->add('POST', '/admin/entries/finalize', [$admin, 'finalizeWeek']);
$router->add('POST', '/admin/entries/add-active', [$admin, 'addAllActiveToWeek']);

$adminUsers = new \App\Controllers\AdminUsersController();
$router->add('GET', '/admin/users', [$adminUsers, 'index']);
$router->add('POST', '/admin/users/save', [$adminUsers, 'save']);

$adminSms = new \App\Controllers\AdminSmsController();
$router->add('GET', '/admin/sms', [$adminSms, 'index']);
$router->add('POST', '/admin/sms/send', [$adminSms, 'send']);
$router->add('POST', '/admin/sms/upload', [$adminSms, 'upload']);
$router->add('GET', '/admin/sms/messages', [$adminSms, 'messages']);
$router->add('POST', '/admin/sms/start-user', [$adminSms, 'startUser']);

return $router;
