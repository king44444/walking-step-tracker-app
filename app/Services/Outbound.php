<?php

namespace App\Services;

use App\Config\DB;

final class Outbound
{
    private static function readDotenvValue(string $key): ?string
    {
        // Minimal parser: scan .env.local then .env for KEY=value
        $root = dirname(__DIR__, 2);
        foreach (['.env.local', '.env'] as $file) {
            $path = $root . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) continue;
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) continue;
            foreach ($lines as $line) {
                if (strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                if ($k === $key) {
                    $v = trim($v);
                    if ($v === '') return null;
                    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                        (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    return $v;
                }
            }
        }
        return null;
    }
    public static function sendSMS(string $to, string $body, array $mediaUrls = []): ?string
    {
        // Ensure we can read env() as a fallback if Dotenv didn't populate $_ENV
        $cfgPath = __DIR__ . '/../../api/lib/config.php';
        if (is_file($cfgPath)) {
            require_once $cfgPath; // defines env() only if not already defined
        }
        // Check if recipient has opted out
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT phone_opted_out FROM users WHERE phone_e164 = ?");
        $stmt->execute([$to]);
        $optedOut = $stmt->fetchColumn();
        if ($optedOut) {
            // Log as blocked due to opt-out
            $ins = $pdo->prepare("INSERT INTO sms_outbound_audit(created_at,to_number,body,http_code,sid,error) VALUES(datetime('now'),?,?,null,null,'User opted out')");
            try {
                $ins->execute([$to, $body]);
            } catch (\Throwable $e) {
                error_log('Outbound::sendSMS audit insert failed: ' . $e->getMessage());
            }
            return null; // Block the send
        }

        // Read Twilio credentials: prefer $_ENV (Dotenv), fallback to env() helper which reads .env/.env.local
        $accountSid = $_ENV['TWILIO_ACCOUNT_SID'] ?? (function_exists('env') ? env('TWILIO_ACCOUNT_SID', null) : null);
        $authToken  = $_ENV['TWILIO_AUTH_TOKEN']  ?? (function_exists('env') ? env('TWILIO_AUTH_TOKEN', null)  : null);
        $fromNumber = $_ENV['TWILIO_FROM_NUMBER'] ?? (function_exists('env') ? env('TWILIO_FROM_NUMBER', null) : null);
        // Fallback: read directly from .env files if still missing
        if (!$accountSid) $accountSid = self::readDotenvValue('TWILIO_ACCOUNT_SID');
        if (!$authToken)  $authToken  = self::readDotenvValue('TWILIO_AUTH_TOKEN');
        if (!$fromNumber) $fromNumber = self::readDotenvValue('TWILIO_FROM_NUMBER');

        // Prepare audit insert
        $pdo = DB::pdo();
        $ins = $pdo->prepare("INSERT INTO sms_outbound_audit(created_at,to_number,body,http_code,sid,error) VALUES(datetime('now'),?,?,?,?,?)");

        if (!$accountSid || !$authToken || !$fromNumber) {
            $error = 'Missing TWILIO_ACCOUNT_SID/TWILIO_AUTH_TOKEN/TWILIO_FROM_NUMBER';
            try {
                $ins->execute([$to, $body, null, null, $error]);
            } catch (\Throwable $e) {
                error_log('Outbound::sendSMS audit insert failed: ' . $e->getMessage());
            }
            return null;
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

        $postData = [
            'To'   => $to,
            'From' => $fromNumber,
            'Body' => $body,
        ];

        // Add media URLs if provided
        foreach ($mediaUrls as $i => $mediaUrl) {
            $postData["MediaUrl{$i}"] = $mediaUrl;
        }

        $post = http_build_query($postData);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $accountSid . ':' . $authToken);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // Execute
        $resp = curl_exec($ch);
        $curlErr = null;
        if ($resp === false) {
            $curlErr = curl_error($ch);
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $sid = null;
        $error = null;

        if ($curlErr) {
            $error = 'curl_error: ' . $curlErr;
        } else {
            $json = json_decode((string)$resp, true);
            if (is_array($json) && isset($json['sid'])) {
                $sid = $json['sid'];
            } elseif (is_array($json) && isset($json['message'])) {
                // Twilio error message
                $error = (string)$json['message'];
            } else {
                // Non-JSON or unexpected response: capture raw body on failure
                if ($httpCode < 200 || $httpCode >= 300) {
                    $error = (string)$resp;
                }
            }
        }

        // Audit the attempt (safe to ignore audit failures)
        try {
            $ins->execute([$to, $body, $httpCode ?: null, $sid, $error]);
        } catch (\Throwable $e) {
            error_log('Outbound::sendSMS audit insert failed: ' . $e->getMessage());
        }

        return ($httpCode >= 200 && $httpCode < 300) ? $sid : null;
    }
}
