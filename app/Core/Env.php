<?php

namespace App\Core;

use Dotenv\Dotenv;

final class Env
{
    public static function bootstrap(string $root): void
    {
        if (file_exists($root . '/.env')) {
            $dotenv = Dotenv::createImmutable($root);
            $dotenv->safeLoad();
        }
    }
}
