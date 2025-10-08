<?php
declare(strict_types=1);

function require_admin(): void {
  // Read creds from env or .env file if available
  $user = getenv('ADMIN_USER') ?: '';
  $pass = getenv('ADMIN_PASS') ?: '';

  // If no creds configured, allow all (dev-friendly)
  if ($user === '' && $pass === '') return;

  $gotUser = $_SERVER['PHP_AUTH_USER'] ?? '';
  $gotPass = $_SERVER['PHP_AUTH_PW']   ?? '';

  // If Apache/Nginx didn’t pass auth, try HTTP_AUTHORIZATION fallback
  if ($gotUser === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (stripos($_SERVER['HTTP_AUTHORIZATION'], 'Basic ') === 0) {
      $decoded = base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6));
      if ($decoded !== false && strpos($decoded, ':') !== false) {
        [$gotUser, $gotPass] = explode(':', $decoded, 2);
        // populate PHP_AUTH_* so application code can read them
        $_SERVER['PHP_AUTH_USER'] = $gotUser;
        $_SERVER['PHP_AUTH_PW']   = $gotPass;
      }
    }
  }

  if (!hash_equals((string)$user, (string)$gotUser) || !hash_equals((string)$pass, (string)$gotPass)) {
    header('Content-Type: application/json; charset=utf-8');
    header('WWW-Authenticate: Basic realm="KW Admin"');
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
  }
}

if (!function_exists('require_admin_username')) {
  function require_admin_username(): string {
    require_admin();
    return $_SERVER['PHP_AUTH_USER'] ?? 'admin';
  }
}
