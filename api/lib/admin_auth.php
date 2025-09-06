<?php
declare(strict_types=1);

function require_admin(): void {
  $u = getenv('ADMIN_USER') ?: '';
  $p = getenv('ADMIN_PASS') ?: '';
  if ($u === '' && $p === '') return; // no auth configured
  if (!isset($_SERVER['PHP_AUTH_USER'])) {
    header('WWW-Authenticate: Basic realm="walk-admin"');
    http_response_code(401);
    exit('auth_required');
  }
  $user = $_SERVER['PHP_AUTH_USER'] ?? '';
  $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
  if (!hash_equals($u, $user) || !hash_equals($p, $pass)) {
    http_response_code(403);
    exit('forbidden');
  }
}
