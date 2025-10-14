<?php
declare(strict_types=1);

function require_admin(): void {
  // Read creds from env (Dotenv populates $_ENV). Fallback to getenv for system env.
  $user = $_ENV['ADMIN_USER'] ?? (getenv('ADMIN_USER') ?: '');
  $pass = $_ENV['ADMIN_PASS'] ?? (getenv('ADMIN_PASS') ?: '');
  $appEnv = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: 'prod');

  // If no creds configured, only allow in explicit dev/test/local environments.
  if ($user === '' && $pass === '') {
    $allowEnvs = ['dev','development','local','test','testing'];
    if (in_array(strtolower((string)$appEnv), $allowEnvs, true)) {
      return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('WWW-Authenticate: Basic realm="KW Admin"');
    http_response_code(401);
    echo json_encode(['error' => 'admin_auth_not_configured']);
    exit;
  }

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
