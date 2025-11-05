<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

use App\Config\DB;

require_once __DIR__ . '/lib/awards.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Validate user_id parameter
    if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid user_id parameter']);
        exit;
    }
    
    $userId = (int)$_GET['user_id'];
    
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid user_id']);
        exit;
    }
    
    // Validate type parameter (currently only 'lifetime' supported)
    $type = $_GET['type'] ?? 'lifetime';
    if ($type !== 'lifetime') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type parameter. Only "lifetime" is supported.']);
        exit;
    }
    
    // Get database connection
    $pdo = DB::pdo();
    
    // Verify user exists
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id");
    $userStmt->execute([':id' => $userId]);
    if (!$userStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    
    // Get award collections
    $stepAwards = get_lifetime_awards($pdo, $userId);
    $attendanceAwards = get_attendance_days_awards($pdo, $userId);

    $response = [
        'sections' => [
            [
                'id' => 'lifetime_steps',
                'title' => 'Lifetime Steps',
                'subtitle' => 'Lifetime step milestones',
                'kind' => 'lifetime_steps',
                'awards' => $stepAwards,
            ],
            [
                'id' => 'attendance_days',
                'title' => 'Lifetime Attendance',
                'subtitle' => 'Days reported / checked in',
                'kind' => 'attendance_days',
                'awards' => $attendanceAwards,
            ],
        ],
    ];

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    error_log('user_awards.php error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
