<?php
require __DIR__.'/../api/util.php';
$pdo = pdo();
$cut = (new DateTime('-90 days', new DateTimeZone('UTC')))->format(DateTime::ATOM);
$st = $pdo->prepare("DELETE FROM sms_audit WHERE created_at < ?");
$st->execute([$cut]);
echo "pruned\n";
