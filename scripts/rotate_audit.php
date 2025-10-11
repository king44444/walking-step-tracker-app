<?php
require __DIR__.'/../api/util.php';
require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
$pdo = \App\Config\DB::pdo();
$cut = (new DateTime('-90 days', new DateTimeZone('UTC')))->format(DateTime::ATOM);
$st = $pdo->prepare("DELETE FROM sms_audit WHERE created_at < ?");
$st->execute([$cut]);
echo "pruned\n";
