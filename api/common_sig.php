<?php
declare(strict_types=1);

// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');

/**
 * Build the URL Twilio would have seen for signature verification.
 * Uses X-Forwarded headers when present (for Cloudflare / proxies).
 */
function twilio_seen_url(): string {
  // Scheme: prefer X-Forwarded-Proto if it's present and valid
  if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $proto = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
    if ($proto === 'https' || $proto === 'http') {
      $scheme = $proto;
    } else {
      $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    }
  } else {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  }

  // Host: prefer X-Forwarded-Host if present, else HTTP_HOST.
  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
  if (strpos($host, ',') !== false) {
    $host = trim(explode(',', $host)[0]);
  }

  // Path: use the path portion of REQUEST_URI (no query string)
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

  return $scheme . '://' . $host . $path;
}

/**
 * Verify Twilio signature and return diagnostic info.
 * - $post: POST params as-assoc
 * - $headerSig: value from X-Twilio-Signature header
 * - $authToken: Twilio auth token
 *
 * Returns array: ['url','joined','expected','header','match']
 */
function twilio_verify(array $post, string $headerSig, string $authToken): array {
  // Sort by key using string sort
  ksort($post, SORT_STRING);

  $url = twilio_seen_url();

  // Build joined string: url + concat(key . value) for sorted keys
  $joinedParts = array_map(function($k) use ($post) { return $k . ($post[$k] ?? ''); }, array_keys($post));
  $joined = $url . implode('', $joinedParts);

  // HMAC-SHA1 and base64 encode
  $expected = base64_encode(hash_hmac('sha1', $joined, $authToken, true));
  $match = hash_equals($expected, $headerSig ?? '');

  return [
    'url' => $url,
    'joined' => $joined,
    'expected' => $expected,
    'header' => $headerSig ?? '',
    'match' => $match,
  ];
}

/**
 * When running local tests or when TWILIO_TEST_MODE=1, allow unsigned requests.
 * Also allow some safe local IPs.
 */
function twilio_should_skip(): bool {
  if (getenv('TWILIO_TEST_MODE') === '1') return true;
  $remote = $_SERVER['REMOTE_ADDR'] ?? '';
  return in_array($remote, ['127.0.0.1','::1','192.168.0.134'], true);
}
