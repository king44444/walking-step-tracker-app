<?php
use App\Core\Router;
use App\Controllers\AdminController;
use App\Controllers\SmsController;

/** @var Router $router */
$router->add('GET', '/', fn() => 'OK');
$router->add('GET', '/admin/ai', [new AdminController(), 'ai']); // kept for compatibility

// AI MVC routes removed; using file-based /api/*.php endpoints instead.

// SMS routes
$sms = new SmsController();
$router->add('POST', '/api/sms', [$sms, 'inbound']);
$router->add('POST', '/api/sms/status', [$sms, 'status']);
$router->add('POST', '/api/send-sms', [$sms, 'send']);

// Admin UI: entries and users
$admin = new \App\Controllers\AdminController();
$router->add('GET', '/admin/entries', [$admin, 'entries']);
$router->add('POST', '/admin/entries/save', [$admin, 'saveEntries']);
$router->add('POST', '/admin/entries/finalize', [$admin, 'finalizeWeek']);
$router->add('POST', '/admin/entries/add-active', [$admin, 'addAllActiveToWeek']);

$adminUsers = new \App\Controllers\AdminUsersController();
$router->add('GET', '/admin/users', [$adminUsers, 'index']);
$router->add('POST', '/admin/users/save', [$adminUsers, 'save']);

return $router;
