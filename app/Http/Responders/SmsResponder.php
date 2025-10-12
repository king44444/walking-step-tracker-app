<?php

namespace App\Http\Responders;

class SmsResponder
{
    /**
     * Send a success response
     */
    public static function ok(string $message, ?string $format = null): void
    {
        $format = $format ?? self::detectFormat();
        self::sendResponse($message, 'ok', 200, $format);
    }

    /**
     * Send an error response
     */
    public static function error(string $message, string $errorType, int $httpCode = 400, ?string $format = null): void
    {
        $format = $format ?? self::detectFormat();
        self::sendResponse($message, $errorType, $httpCode, $format);
    }

    /**
     * Auto-detect response format from request
     */
    private static function detectFormat(): string
    {
        // Check explicit query parameter first
        $queryFormat = $_GET['format'] ?? '';
        if (in_array(strtolower($queryFormat), ['xml', 'json'])) {
            return strtolower($queryFormat);
        }

        // Auto-detect from Twilio signature header
        $isTwilio = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);
        return $isTwilio ? 'xml' : 'json';
    }

    /**
     * Send the actual response
     */
    private static function sendResponse(string $message, string $errorType, int $httpCode, string $format): void
    {
        $message = self::withReminder($message);
        if ($format === 'xml') {
            self::sendXmlResponse($message, $httpCode);
        } else {
            self::sendJsonResponse($message, $errorType, $httpCode);
        }

        // In CLI (tests), avoid exit to keep buffers stable
        if (PHP_SAPI === 'cli') {
            return;
        }

        // Don't exit in test environment
        if (!class_exists('PHPUnit\Framework\TestCase', false)) {
            exit;
        }
    }

    /**
     * Send TwiML XML response
     */
    private static function sendXmlResponse(string $message, int $httpCode): void
    {
        header('Content-Type: text/xml; charset=utf-8');
        // Twilio expects 200 OK for webhook responses, even on logical errors
        http_response_code(200);
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response><Message>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</Message></Response>";
    }

    /**
     * Send JSON response
     */
    private static function sendJsonResponse(string $message, string $errorType, int $httpCode): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);

        if ($errorType === 'ok') {
            // Success response
            echo json_encode([
                'ok' => true,
                'message' => $message
            ]);
        } else {
            // Error response
            echo json_encode([
                'error' => $errorType,
                'message' => $message
            ]);
        }
    }

    /**
     * Append site reminder unless already present.
     * - SITE_URL from env; else DB/settings via api/lib/config.php (get_setting('site.url') or app.public_base_url)
     * - Avoid duplicates if URL already present
     */
    private static function withReminder(string $message): string
    {
        // Fast-path: allow tests to inject via $_ENV directly
        $site = isset($_ENV['SITE_URL']) ? (string)$_ENV['SITE_URL'] : '';
        if ($site === '') {
            try {
                $site = self::siteUrl();
            } catch (\Throwable $e) {
                // Fail safe: if SITE_URL cannot be resolved, do not append a footer
                error_log('SmsResponder footer disabled: ' . $e->getMessage());
                return $message;
            }
        }
        // Normalize trailing slash to exactly one
        $site = rtrim(trim($site), "/") . "/";
        if ($site === '') return $message;

        // If message already contains the site URL (case-insensitive), do not append
        if (stripos($message, $site) !== false) return $message;
        // Also consider variant without trailing slash to avoid duplicates
        $siteNoSlash = rtrim($site, '/');
        if ($siteNoSlash !== '' && stripos($message, $siteNoSlash) !== false) return $message;

        // If message already contains the standard hint, avoid duplicate footer entirely
        $hintPhrase = 'text "walk" or "menu" for menu';
        if (stripos($message, $hintPhrase) !== false) return $message;

        // Build single-line footer, appended on a new line
        $reminder = "Visit {$site} — text \"walk\" or \"menu\" for menu.";
        $msg = rtrim($message);
        $nl = (str_ends_with($msg, "\n")) ? '' : "\n";
        return $msg . $nl . $reminder;
    }

    private static function siteUrl(): string
    {
        static $cached = null;
        // Load lightweight config helpers
        require_once __DIR__ . '/../../../api/lib/config.php';
        // 1) Env SITE_URL takes precedence and bypasses cache (tests rely on this)
        $envUrl = (string)env('SITE_URL', '');
        if ($envUrl !== '') {
            return $cached = $envUrl;
        }
        if ($cached !== null) return $cached;

        // 2) settings.site.url in SQLite
        $url = '';
        if (function_exists('get_setting')) {
            $url = (string)(get_setting('site.url') ?? '');
        }

        // 3) Fail if missing
        if ($url === '') {
            throw new \RuntimeException('SITE_URL missing: set SITE_URL in .env or settings.site.url');
        }

        return $cached = $url;
    }
}
