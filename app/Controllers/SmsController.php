<?php

namespace App\Controllers;

use App\Config\DB;
use App\Security\TwilioSignature;
use App\Services\Outbound;
use App\Support\Tx;

class SmsController
{
    public function inbound()
    {
        // Bootstrap environment
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        \App\Core\Env::bootstrap(dirname(__DIR__, 2));

        $pdo = DB::pdo();
        $from = $_POST['From'] ?? '';
        $body = trim($_POST['Body'] ?? '');
        $e164 = $this->to_e164($from);
        $now = now_in_tz();
        $createdAt = $now->format(\DateTime::ATOM);

        $insAudit = $pdo->prepare("INSERT INTO sms_audit(created_at,from_number,raw_body,parsed_day,parsed_steps,resolved_week,resolved_day,status) VALUES(?,?,?,?,?,?,?,?)");

        $audit_exec = function(array $params) use ($insAudit) {
            with_file_lock(dirname(__DIR__, 2) . '/data/sqlite.write.lock', function() use ($insAudit, $params) {
                for ($i = 0; $i < 5; $i++) {
                    try { $insAudit->execute($params); return; }
                    catch (\PDOException $e) {
                        $m = $e->getMessage();
                        if (stripos($m, 'locked') !== false || stripos($m, 'SQLITE_BUSY') !== false) { usleep(200000); continue; }
                        throw $e;
                    }
                }
            });
        };

        $auth = env('TWILIO_AUTH_TOKEN','');
        $is_twilio = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);
        if (!$this->isInternalRequest() && $auth !== '') {
            $url = TwilioSignature::buildTwilioUrl();
            $ok = TwilioSignature::verify($_POST, $url, $auth);
            if (!$ok) {
                $audit_exec([$createdAt,$e164,$body,null,null,null,null,'bad_signature']);
                http_response_code(403);
                echo json_encode(['error'=>'bad_signature']);
                exit;
            }
        }

        if (!$e164 || $body==='') {
            $audit_exec([$createdAt,$from,$body,null,null,null,null,'bad_request']);
            $errMsg = 'Sorry, we could not read your number or message. Please try again.';
            $this->respondError($errMsg, 'bad_request', 400);
        }

        // Rate limit per number on last ok (configurable window)
        $rateWindowSec = (int)setting_get('sms.inbound_rate_window_sec', 60);
        $cut = (clone $now)->modify("-{$rateWindowSec} seconds")->format(\DateTime::ATOM);
        $stRL = $pdo->prepare("SELECT 1 FROM sms_audit WHERE from_number=? AND status='ok' AND created_at>=? LIMIT 1");
        $stRL->execute([$e164, $cut]);
        if ($stRL->fetchColumn()) {
            $audit_exec([$createdAt,$e164,$body,null,null,null,null,'rate_limited']);
            $errMsg = 'Got it! Please wait a minute before sending another update.';
            $this->respondError($errMsg, 'rate_limited', 429);
        }

        // Parse input with single numeric group rule
        $raw_body = $body;
        $body_norm = preg_replace('/(?<=\d)[\p{Zs}\x{00A0}\x{202F},\.](?=\d{3}\b)/u', '', $body);
        $body_norm = trim($body_norm);

        if (preg_match_all('/\d+/', $body_norm, $mm) && count($mm[0]) > 1) {
            $audit_exec([$createdAt,$e164,$raw_body,null,null,null,null,'too_many_numbers']);
            $errMsg = "Please send one number like 12345 or 'Tue 12345'.";
            $this->respondError($errMsg, 'too_many_numbers', 400);
        }

        $dayOverride = null; $steps = null;
        if (preg_match('/^\s*([A-Za-z]{3,9})\b\D*([0-9]{2,})\s*$/', $body_norm, $m)) {
            $dayOverride=$m[1]; $steps=intval($m[2]);
        } elseif (preg_match('/^\s*([0-9]{2,})\s*$/', $body_norm, $m)) {
            $steps=intval($m[1]);
        } else {
            $audit_exec([$createdAt,$e164,$raw_body,null,null,null,null,'no_steps']);
            $errMsg = "Please send one number like 12345 or 'Tue 12345'.";
            $this->respondError($errMsg, 'no_steps', 400);
        }

        if ($steps < 0 || $steps > 200000) {
            $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'invalid_steps']);
            $errMsg='That number looks off. Try a value between 0 and 200,000.';
            $this->respondError($errMsg, 'invalid_steps', 400);
        }

        $stU = $pdo->prepare("SELECT name FROM users WHERE phone_e164=?");
        $stU->execute([$e164]);
        $u = $stU->fetch(\PDO::FETCH_ASSOC);
        if (!$u) {
            $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'unknown_number']);
            $errMsg='We do not recognize this number. Ask admin to enroll your phone.';
            $this->respondError($errMsg, 'unknown_number', 404);
        }
        $name = $u['name'];

        $dayCol = $this->resolveTargetDay($now, $dayOverride);
        if (!$dayCol) {
            $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'bad_day']);
            $errMsg='Unrecognized day. Use Mon..Sat or leave it out.';
            $this->respondError($errMsg, 'bad_day', 400);
        }

        $week = $this->resolveActiveWeek($pdo);
        if (!$week) {
            $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,$dayCol,'no_active_week']);
            $errMsg='No active week to record to. Please try again later.';
            $this->respondError($errMsg, 'no_active_week', 404);
        }

        Tx::with(function(\PDO $pdo) use ($week, $name, $dayCol, $steps) {
            $this->upsertSteps($pdo, $week, $name, $dayCol, $steps);
        });
        $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,$week,$dayCol,'ok']);

        $aiOn = setting_get('ai.enabled', '1');
        if ($aiOn === '1') {
            $this->handleAiReply($pdo, $name, $raw_body, $week, $e164);
        }

        $noonRule = !$dayOverride ? (intval($now->format('H'))<12 ? 'yesterday' : 'today') : strtolower($dayCol);
        $msg = "Recorded ".number_format($steps)." for $name on $noonRule.";

        $this->respondSuccess($msg, $name, $steps, $noonRule);
    }

    public function status()
    {
        // Bootstrap environment
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        \App\Core\Env::bootstrap(dirname(__DIR__, 2));

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit;
        }

        $authToken = getenv('TWILIO_AUTH_TOKEN') ?: '';
        $url = TwilioSignature::buildTwilioUrl();
        if (!TwilioSignature::verify($_POST, $url, $authToken)) {
            http_response_code(403);
            exit;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $rec = [
            ':message_sid' => $_POST['MessageSid'] ?? $_POST['SmsSid'] ?? null,
            ':message_status' => $_POST['MessageStatus'] ?? $_POST['SmsStatus'] ?? null,
            ':to_number' => $_POST['To'] ?? null,
            ':from_number' => $_POST['From'] ?? null,
            ':error_code' => $_POST['ErrorCode'] ?? null,
            ':error_message' => $_POST['ErrorMessage'] ?? null,
            ':messaging_service_sid' => $_POST['MessagingServiceSid'] ?? null,
            ':account_sid' => $_POST['AccountSid'] ?? null,
            ':api_version' => $_POST['ApiVersion'] ?? null,
            ':raw_payload' => json_encode($_POST, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            ':received_at_utc' => $now,
        ];

        try {
            $pdo = DB::pdo();
            $pdo->exec('PRAGMA foreign_keys=ON');

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS message_status (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  message_sid TEXT UNIQUE,
                  message_status TEXT,
                  to_number TEXT,
                  from_number TEXT,
                  error_code TEXT,
                  error_message TEXT,
                  messaging_service_sid TEXT,
                  account_sid TEXT,
                  api_version TEXT,
                  raw_payload TEXT,
                  received_at_utc TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_message_status_sid ON message_status(message_sid);
                CREATE INDEX IF NOT EXISTS idx_message_status_status ON message_status(message_status);
            ");

            $stmt = $pdo->prepare("
                INSERT INTO message_status (
                  message_sid, message_status, to_number, from_number, error_code, error_message,
                  messaging_service_sid, account_sid, api_version, raw_payload, received_at_utc
                ) VALUES (
                  :message_sid, :message_status, :to_number, :from_number, :error_code, :error_message,
                  :messaging_service_sid, :account_sid, :api_version, :raw_payload, :received_at_utc
                )
                ON CONFLICT(message_sid) DO UPDATE SET
                  message_status=excluded.message_status,
                  to_number=excluded.to_number,
                  from_number=excluded.from_number,
                  error_code=excluded.error_code,
                  error_message=excluded.error_message,
                  messaging_service_sid=excluded.messaging_service_sid,
                  account_sid=excluded.account_sid,
                  api_version=excluded.api_version,
                  raw_payload=excluded.raw_payload,
                  received_at_utc=excluded.received_at_utc
            ");
            $stmt->execute($rec);
        } catch (\Throwable $e) {
            error_log('SmsController::status error: '.$e->getMessage());
        }

        http_response_code(200);
    }

    public function send()
    {
        // Bootstrap environment
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        \App\Core\Env::bootstrap(dirname(__DIR__, 2));

        header('Content-Type: application/json; charset=utf-8');

        // Internal auth
        $secret = env('INTERNAL_API_SECRET','');
        $hdr = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
        if ($secret === '' || !hash_equals($secret, $hdr)) {
            http_response_code(403);
            echo json_encode(['error'=>'forbidden']);
            exit;
        }

        // Read params
        $to = $_POST['to'] ?? $_POST['To'] ?? '';
        $body = $_POST['body'] ?? $_POST['Body'] ?? '';

        if ($to === '' || $body === '') {
            http_response_code(400);
            echo json_encode(['error'=>'missing to/body']);
            exit;
        }

        // Use the Outbound service
        $success = Outbound::sendSMS($to, $body);

        if ($success) {
            echo json_encode(['ok'=>true]);
        } else {
            http_response_code(502);
            echo json_encode(['error'=>'send_failed']);
        }
    }

    private function isInternalRequest(): bool
    {
        $secret = env('INTERNAL_API_SECRET','');
        $is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1']);
        $has_secret = $secret !== '' && (isset($_SERVER['HTTP_X_INTERNAL_SECRET']) && hash_equals($secret, $_SERVER['HTTP_X_INTERNAL_SECRET']));
        return $is_local || $has_secret;
    }



    private function to_e164(string $phone): string
    {
        require_once dirname(__DIR__, 2) . '/api/lib/phone.php';
        return to_e164($phone);
    }

    private function resolveTargetDay(\DateTime $now, ?string $dayOverride): ?string
    {
        require_once dirname(__DIR__, 2) . '/api/lib/dates.php';
        return resolve_target_day($now, $dayOverride);
    }

    private function resolveActiveWeek(\PDO $pdo): ?string
    {
        require_once dirname(__DIR__, 2) . '/api/lib/dates.php';
        return resolve_active_week($pdo);
    }

    private function upsertSteps(\PDO $pdo, string $week, string $name, string $dayCol, int $steps): void
    {
        require_once dirname(__DIR__, 2) . '/api/lib/entries.php';
        upsert_steps($pdo, $week, $name, $dayCol, $steps);
    }

    private function handleAiReply(\PDO $pdo, string $name, string $raw_body, string $week, string $e164): void
    {
        try {
            require_once dirname(__DIR__, 2) . '/api/lib/ai_sms.php';
            $stUId = $pdo->prepare('SELECT id, phone_e164 FROM users WHERE name = :n');
            $stUId->execute([':n'=>$name]);
            $usr = $stUId->fetch(\PDO::FETCH_ASSOC);
            $userId = (int)($usr['id'] ?? 0);
            $toPhone = (string)($usr['phone_e164'] ?? '');

            $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats(user_id INTEGER PRIMARY KEY, last_ai_at TEXT)");
            $last = $pdo->prepare('SELECT last_ai_at FROM user_stats WHERE user_id = ?');
            $last->execute([$userId]);
            $lastAt = (string)($last->fetchColumn() ?: '');
            $can = true;
            if ($lastAt !== '') {
                $diff = time() - strtotime($lastAt);
                $aiRateWindowSec = (int)setting_get('sms.ai_rate_window_sec', 120);
                if ($diff < $aiRateWindowSec) $can = false;
            }
            if ($can) {
                $gen = generate_ai_sms_reply($name, $raw_body, ['week_label' => '']);
                $content = (string)$gen['content'];
                $model = (string)$gen['model'];
                $rawJson = json_encode($gen['raw'], JSON_UNESCAPED_SLASHES);
                $cost = $gen['cost_usd'];
                $ins = $pdo->prepare("INSERT INTO ai_messages(type,scope_key,user_id,week,content,model,prompt_hash,approved_by,created_at,sent_at,provider,raw_json,cost_usd)
                                VALUES('sms',NULL,:uid,:wk,:body,:model,NULL,NULL,datetime('now'),NULL,:prov,:raw,:cost)");
                $ins->execute([':uid'=>$userId, ':wk'=>$week, ':body'=>$content, ':model'=>$model, ':prov'=>'openrouter', ':raw'=>$rawJson, ':cost'=>$cost]);
                $pdo->prepare('INSERT INTO user_stats(user_id,last_ai_at) VALUES(:u, datetime(\'now\')) ON CONFLICT(user_id) DO UPDATE SET last_ai_at = excluded.last_ai_at')->execute([':u'=>$userId]);

                $auto = get_setting('ai_autosend');
                if ($auto === '1' && $toPhone !== '') {
                    require_once dirname(__DIR__, 2) . '/api/lib/outbound.php';
                    try {
                        send_outbound_sms($toPhone, $content);
                        $pdo->prepare('UPDATE ai_messages SET sent_at = datetime(\'now\') WHERE id = :id')->execute([':id'=>$pdo->query('SELECT last_insert_rowid()')->fetchColumn()]);
                    } catch (\Throwable $e) {
                        // leave unsent
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('SmsController::inbound AI error: ' . $e->getMessage());
        }
    }

    private function respondError(string $message, string $error, int $code): void
    {
        $isTwilio = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);
        if (!$this->isInternalRequest() && $isTwilio) {
            header('Content-Type: text/xml; charset=utf-8');
            http_response_code(200);
            echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($message, ENT_QUOTES, 'UTF-8')."</Message></Response>";
        } else {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
            echo json_encode(['error'=>$error,'message'=>$message]);
        }
        exit;
    }

    private function respondSuccess(string $message, string $name, int $steps, string $day): void
    {
        $isTwilio = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);
        if (!$this->isInternalRequest() && $isTwilio) {
            header('Content-Type: text/xml; charset=utf-8');
            echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response><Message>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</Message></Response>";
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'message'=>$message,'name'=>$name,'steps'=>$steps,'day'=>$day]);
        }
    }
}
