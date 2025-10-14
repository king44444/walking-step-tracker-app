<?php

namespace App\Core;

use Dotenv\Dotenv;

final class Env
{
    public static function bootstrap(string $root): void
    {
        // Load .env and .env.local (if present), with later files overriding earlier ones
        $files = [];
        if (file_exists($root . '/.env')) {
            $files[] = '.env';
        }
        if (file_exists($root . '/.env.local')) {
            $files[] = '.env.local';
        }
        if ($files) {
            $dotenv = Dotenv::createImmutable($root, $files);
            $dotenv->safeLoad();
        }
    }
}
