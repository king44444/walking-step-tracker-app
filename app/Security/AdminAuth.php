<?php

namespace App\Security;

final class AdminAuth
{
    public static function require(): void
    {
        $u = $_ENV['ADMIN_USER'] ?? null;
        $p = $_ENV['ADMIN_PASS'] ?? null;
        if (!$u || !$p) {
            return;
        }
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        if ($user !== $u || $pass !== $p) {
            header('WWW-Authenticate: Basic realm="KW Admin"');
            http_response_code(401);
            echo 'Auth required';
            exit;
        }
    }
}
