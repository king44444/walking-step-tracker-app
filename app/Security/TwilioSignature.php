<?php

namespace App\Security;

class TwilioSignature
{
    /**
     * Verify Twilio request signature
     *
     * @param array $request POST parameters
     * @param string $url Full URL Twilio would have seen
     * @param string $authToken Twilio auth token
     * @return bool True if signature is valid or verification is skipped
     */
    public static function verify(array $request, string $url, string $authToken): bool
    {
        // Check if verification should be skipped
        if (self::shouldSkipVerification()) {
            return true;
        }

        // If no auth token, skip verification
        if (empty($authToken)) {
            return true;
        }

        // Get signature from headers
        $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

        // Build the data string: URL + sorted key-value pairs
        $data = $url;
        ksort($request, SORT_STRING);
        foreach ($request as $key => $value) {
            $data .= $key . $value;
        }

        // Calculate expected signature
        $expectedSignature = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        // Use constant-time comparison
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Build the URL that Twilio would have seen, accounting for proxies
     *
     * @return string The full URL
     */
    public static function buildTwilioUrl(): string
    {
        // Scheme: prefer X-Forwarded-Proto if present and valid
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
            $scheme = in_array($proto, ['https', 'http']) ? $proto : self::getDefaultScheme();
        } else {
            $scheme = self::getDefaultScheme();
        }

        // Host: prefer X-Forwarded-Host if present
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
        if (strpos($host, ',') !== false) {
            $host = trim(explode(',', $host)[0]);
        }

        // Path: use the path portion of REQUEST_URI (no query string)
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return $scheme . '://' . $host . $path;
    }

    /**
     * Determine if signature verification should be skipped
     *
     * @return bool True if verification should be skipped
     */
    private static function shouldSkipVerification(): bool
    {
        $appEnv = getenv('APP_ENV') ?: 'prod';
        $skipSig = getenv('TWILIO_SKIP_SIG') ?: '0';

        // Skip if not production and TWILIO_SKIP_SIG is enabled
        if ($appEnv !== 'prod' && $skipSig === '1') {
            return true;
        }

        // Legacy skip logic for backward compatibility
        if (getenv('TWILIO_TEST_MODE') === '1') {
            return true;
        }

        // Allow trusted local IPs when signature header is missing
        $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
        if ($signature === '' && self::isTrustedIp()) {
            return true;
        }

        return false;
    }

    /**
     * Check if the remote IP is trusted for skipping verification
     *
     * @return bool True if IP is trusted
     */
    private static function isTrustedIp(): bool
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($remote, ['127.0.0.1', '::1', '192.168.0.134'], true);
    }

    /**
     * Get the default scheme (http/https)
     *
     * @return string 'https' or 'http'
     */
    private static function getDefaultScheme(): string
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    }
}
