<?php

namespace App\Services;

use App\Config\DB;

final class Outbound
{
    public static function sendSMS(string $to, string $body): ?string
    {
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

        // Read Twilio credentials from environment (Dotenv populates $_ENV)
        $accountSid = $_ENV['TWILIO_ACCOUNT_SID'] ?? null;
        $authToken  = $_ENV['TWILIO_AUTH_TOKEN'] ?? null;
        $fromNumber = $_ENV['TWILIO_FROM_NUMBER'] ?? null;

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
        $post = http_build_query([
            'To'   => $to,
            'From' => $fromNumber,
            'Body' => $body,
        ]);

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
