<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

use App\Config\DB;

try {
    $userId = isset($argv[1]) ? (int)$argv[1] : 9998;
    $pdo = DB::pdo();
    require_once __DIR__ . '/../api/lib/awards.php';
    $awards = get_lifetime_awards($pdo, $userId);
    echo json_encode($awards, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
