<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/lib/admin_auth.php';

// Protect: admin only
require_admin();

// Prefer the real LLM generation log; fall back to stub log if missing.
$prefer = __DIR__ . '/../data/logs/ai/ai_generation.log';
$fallback = __DIR__ . '/../data/logs/ai_stub.log';
$path = file_exists($prefer) ? $prefer : $fallback;

if (!file_exists($path)) { echo json_encode(['ok'=>true,'entries'=>[],'source'=>null]); exit; }

// Read last N lines (default 50)
$n = 50;
$lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) { echo json_encode(['ok'=>false,'error'=>'read_failed']); exit; }
$slice = array_slice($lines, -$n);
$slice = array_reverse($slice);

echo json_encode(['ok'=>true,'entries'=>$slice,'source'=> (strpos($path, 'ai_generation.log') !== false ? 'llm' : 'stub')]);
