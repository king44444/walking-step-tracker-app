#!/usr/bin/env php
<?php

// Bootstrap environment
require_once dirname(__DIR__) . '/vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

// Include required libraries (dates.php first to define env(), then settings.php which honors existing env())
require_once dirname(__DIR__) . '/api/lib/dates.php';
require_once dirname(__DIR__) . '/api/lib/settings.php';

use App\Config\DB;
use App\Services\Outbound;

$pdo = DB::pdo();

// Get current date and time in configured WALK_TZ timezone
$now = now_in_tz();
$currentDate = $now->format('Y-m-d');
$currentTime = $now->format('H:i');

// Query users with reminders enabled and not opted out
$stmt = $pdo->prepare("
    SELECT id, name, phone_e164, reminders_when
    FROM users
    WHERE reminders_enabled = 1 AND phone_opted_out = 0 AND phone_e164 IS NOT NULL
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sentCount = 0;

foreach ($users as $user) {
    $userId = (int)$user['id'];
    $name = $user['name'];
    $phone = $user['phone_e164'];
    $when = strtoupper(trim($user['reminders_when'] ?? ''));

    // Check if we already sent a reminder today for this user
    $checkStmt = $pdo->prepare("SELECT 1 FROM reminders_log WHERE user_id = ? AND sent_on_date = ? LIMIT 1");
    $checkStmt->execute([$userId, $currentDate]);
    if ($checkStmt->fetchColumn()) {
        continue; // Already sent today
    }

    // Check if current time matches the user's reminder time
    $shouldSend = false;
    if ($when === 'MORNING') {
        $morningTime = setting_get('reminders.default_morning', '07:30');
        $shouldSend = ($currentTime === $morningTime);
    } elseif ($when === 'EVENING') {
        $eveningTime = setting_get('reminders.default_evening', '20:00');
        $shouldSend = ($currentTime === $eveningTime);
    } elseif (preg_match('/^\d{1,2}:\d{2}$/', $when)) {
        $shouldSend = ($currentTime === $when);
    }

    if ($shouldSend) {
        // Send reminder
        $message = "Reminder to report steps. Reply with a number or HELP.";
        $sid = Outbound::sendSMS($phone, $message);

        if ($sid !== null) {
            // Log successful send
            $logStmt = $pdo->prepare("INSERT INTO reminders_log(user_id, sent_on_date, when_sent, created_at) VALUES(?, ?, ?, datetime('now'))");
            $logStmt->execute([$userId, $currentDate, $when]);
            $sentCount++;
            echo "Sent reminder to {$name} ({$phone}) at {$currentTime}\n";
        } else {
            echo "Failed to send reminder to {$name} ({$phone})\n";
        }
    }
}

echo "Reminders run complete. Sent {$sentCount} reminders.\n";
