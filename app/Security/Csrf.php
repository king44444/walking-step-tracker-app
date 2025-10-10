<?php

namespace App\Security;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_secret'])) {
            $_SESSION['csrf_secret'] = $_ENV['CSRF_SECRET'] ?? bin2hex(random_bytes(16));
        }
        $t = bin2hex(random_bytes(16));
        $_SESSION['csrf_tokens'][$t] = time();
        return $t;
    }
    public static function validate(string $t): bool
    {
        if (!isset($_SESSION['csrf_tokens'][$t])) {
            return false;
        }
        unset($_SESSION['csrf_tokens'][$t]);
        return true;
    }
}
