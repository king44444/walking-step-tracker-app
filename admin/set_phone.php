<?php
require __DIR__ . '/../api/util.php';
$pdo = pdo();
$name = $_GET['name'] ?? null;
$phone = $_GET['phone'] ?? null;
if (!$name || !$phone) { echo "usage: ?name=Mike&phone=+18015551234"; exit; }
$st = $pdo->prepare("UPDATE users SET phone_e164=? WHERE name=?");
$st->execute([$phone, $name]);
echo "ok";
