<?php

namespace App\Core;

final class Router
{
    private array $routes = [];
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }
    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        if (isset($this->routes[$method][$path])) {
            echo call_user_func($this->routes[$method][$path]);
            return;
        }
      // fallback to legacy files if they exist
        $legacy = $this->legacyPath($path);
        if ($legacy && file_exists($legacy)) {
            require $legacy;
            return;
        }
        http_response_code(404);
        echo 'Not found';
    }
    private function legacyPath(string $path): ?string
    {
      // keep legacy /api/*.php and /admin/*.php working
        $path = trim($path, '/');
        if (preg_match('#^(api|admin)/.+\.php$#', $path)) {
            return __DIR__ . '/../../' . $path;
        }
        return null;
    }
}
