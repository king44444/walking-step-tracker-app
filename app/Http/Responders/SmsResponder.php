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
        if ($format === 'xml') {
            self::sendXmlResponse($message, $httpCode);
        } else {
            self::sendJsonResponse($message, $errorType, $httpCode);
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
        http_response_code($httpCode);
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
}
