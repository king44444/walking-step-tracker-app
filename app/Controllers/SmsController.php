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

        // Include required libraries
        require_once dirname(__DIR__, 2) . '/api/lib/dates.php';
        require_once dirname(__DIR__, 2) . '/api/lib/settings.php';
        require_once dirname(__DIR__, 2) . '/api/util.php';

        $pdo = DB::pdo();
        $from = $_POST['From'] ?? '';
        $body = trim($_POST['Body'] ?? '');
        $raw_body = $body; // keep original body for auditing
        $e164 = $this->to_e164($from);
        $now = now_in_tz();
        $createdAt = $now->format(\DateTime::ATOM);

        // Admin prefix check
        $ctx = ['is_admin' => false];
        $adminEnabled = setting_get('sms.admin_prefix_enabled', '0') === '1';
        $adminPassword = setting_get('sms.admin_password', '');
        if ($adminEnabled && $adminPassword !== '' && preg_match('/^\s*\[\s*' . preg_quote($adminPassword, '/') . '\s*\]\s*(.*)$/i', $body, $m)) {
            $body = trim($m[1]);
            $ctx['is_admin'] = true;
        }

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

        $primaryAuth = env('TWILIO_AUTH_TOKEN', '');
        $fallbackAuth = env('TWILIO_AUTH_TOKEN_FALLBACK', '');
        $authTokens = array_values(array_filter(array_unique([$primaryAuth, $fallbackAuth])));
        $is_twilio = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);
        if (!$this->isInternalRequest() && !empty($authTokens)) {
            $url = TwilioSignature::buildTwilioUrl();
            $verified = false;
            $usedToken = null;
            foreach ($authTokens as $token) {
                if (TwilioSignature::verify($_POST, $url, $token)) {
                    $verified = true;
                    $usedToken = $token;
                    break;
                }
            }

            if (!$verified) {
                $logDir = dirname(__DIR__, 2) . '/data/logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0775, true);
                }
                [$expected, $sigString] = TwilioSignature::debugSignature($_POST, $url, $primaryAuth ?: $fallbackAuth);
                $tokenHashes = [];
                foreach ($authTokens as $token) {
                    $tokenHashes[] = $token === '' ? '' : substr(hash('sha256', $token), 0, 16);
                }
                $logPayload = [
                    'at' => $now->format(\DateTime::ATOM),
                    'url' => $url,
                    'expected' => $expected,
                    'header' => $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '',
                    'sig_string' => $sigString,
                    'used_token_hash' => $usedToken ? substr(hash('sha256', $usedToken), 0, 16) : null,
                    'raw_tokens' => $authTokens,
                    'token_hashes' => $tokenHashes,
                    'token_count' => count($authTokens),
                    'host' => $_SERVER['HTTP_HOST'] ?? '',
                    'forwarded_host' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '',
                    'forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '',
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'post' => $_POST,
                ];
                $logPath = $logDir . '/sms_bad_sig.log';
                file_put_contents($logPath, json_encode($logPayload, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
                $audit_exec([$createdAt,$e164,$body,null,null,null,null,'bad_signature']);
                http_response_code(403);
                echo json_encode(['error'=>'bad_signature']);
                exit;
            }
        } elseif (empty($authTokens) && !$this->isInternalRequest()) {
            // No tokens configured: respond with configuration error.
            $audit_exec([$createdAt,$e164,$body,null,null,null,null,'missing_auth_token']);
            http_response_code(500);
            echo json_encode(['error'=>'missing_auth_token']);
            exit;
        }

        if (!$e164 || $body==='') {
            $audit_exec([$createdAt,$from,$raw_body,null,null,null,null,'bad_request']);
            $errMsg = 'Sorry, we could not read your number or message. Please try again.';
            \App\Http\Responders\SmsResponder::error($errMsg, 'bad_request', 400);
        }

        // Rate limit per number on last ok (configurable window)
        $rateWindowSec = (int)setting_get('sms.inbound_rate_window_sec', 60);
        $cut = (clone $now)->modify("-{$rateWindowSec} seconds")->format(\DateTime::ATOM);
        $stRL = $pdo->prepare("SELECT 1 FROM sms_audit WHERE from_number=? AND status='ok' AND created_at>=? LIMIT 1");
        $stRL->execute([$e164, $cut]);
        if ($stRL->fetchColumn()) {
            $audit_exec([$createdAt,$e164,$body,null,null,null,null,'rate_limited']);
            $errMsg = 'Got it! Please wait a minute before sending another update.';
            \App\Http\Responders\SmsResponder::error($errMsg, 'rate_limited', 429);
        }

        // Look up user first for commands that need it
        $stU = $pdo->prepare("SELECT id, name FROM users WHERE phone_e164=?");
        $stU->execute([$e164]);
        $u = $stU->fetch(\PDO::FETCH_ASSOC);
        if (!$u) {
            $audit_exec([$createdAt,$e164,$raw_body,null,null,null,null,'unknown_number']);
            $errMsg='We do not recognize this number. Ask admin to enroll your phone.';
            \App\Http\Responders\SmsResponder::error($errMsg, 'unknown_number', 404);
        }
        $userId = (int)$u['id'];
        $name = $u['name'];

        // Parse input - check for commands first
        $body = trim($body);
        $body_upper = strtoupper($body);

        // Command handling
        if ($body_upper === 'HELP' || $body_upper === 'INFO') {
            // Let Twilio handle HELP/INFO auto-reply; do not send our menu here
            http_response_code(200);
            return;
        } elseif ($body_upper === 'WALK' || $body_upper === 'MENU') {
            $msg = $this->getHelpText($ctx['is_admin']);
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif ($body_upper === 'TOTAL') {
            $msg = $this->handleTotalCommand($pdo, $name);
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif ($body_upper === 'WEEK') {
            $msg = $this->handleWeekCommand($pdo, $name);
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif (preg_match('/^INTERESTS\s+SET\s+(.+)$/i', $body, $m)) {
            $msg = $this->handleInterestsSet($pdo, $name, trim($m[1]));
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif (strtoupper($body) === 'INTERESTS LIST') {
            $msg = $this->handleInterestsList($pdo, $name);
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif (preg_match('/^REMINDERS\s+(ON|OFF)$/i', $body, $m)) {
            $msg = $this->handleRemindersToggle($pdo, $name, strtoupper($m[1]));
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif (preg_match('/^REMINDERS\s+WHEN\s+(.+)$/i', $body, $m)) {
            $msg = $this->handleRemindersWhen($pdo, $name, trim($m[1]));
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif ($body_upper === 'STOP') {
            $msg = $this->handleStopCommand($pdo, $userId, $e164);
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif ($body_upper === 'START') {
            $msg = $this->handleStartCommand($pdo, $userId, $e164);
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        } elseif ($body_upper === 'UNDO' && $ctx['is_admin']) {
            $msg = $this->handleUndoCommand($pdo, $name);
            \App\Http\Responders\SmsResponder::ok($msg);
            return;
        }

        // Steps parsing with single numeric group rule
        $body_norm = preg_replace('/(?<=\d)[\p{Zs}\x{00A0}\x{202F},\.](?=\d{3}\b)/u', '', $body);
        $body_norm = trim($body_norm);

        if (preg_match_all('/\d+/', $body_norm, $mm) && count($mm[0]) > 1) {
            $audit_exec([$createdAt,$e164,$raw_body,null,null,null,null,'too_many_numbers']);
            $errMsg = "Please send one number like 12345 or 'Tue 12345'.";
            \App\Http\Responders\SmsResponder::error($errMsg, 'too_many_numbers', 400);
        }

        $dayOverride = null; $steps = null;
        // Support both orders: "MON 8200" and "8200 MON"
        if (preg_match('/^\s*([A-Za-z]{3,9})\b\D*([0-9]{2,})\s*$/', $body_norm, $m)) {
            $dayOverride=$m[1]; $steps=intval($m[2]);
        } elseif (preg_match('/^\s*([0-9]{2,})\s+([A-Za-z]{3,9})\b.*$/', $body_norm, $m)) {
            $steps=intval($m[1]); $dayOverride=$m[2];
        } elseif (preg_match('/^\s*([0-9]{2,})\s*$/', $body_norm, $m)) {
            $steps=intval($m[1]);
        } else {
            $audit_exec([$createdAt,$e164,$raw_body,null,null,null,null,'no_steps']);
            $errMsg = "Please send one number like 12345 or 'Tue 12345'.";
            \App\Http\Responders\SmsResponder::error($errMsg, 'no_steps', 400);
        }

        if ($steps < 0 || $steps > 200000) {
            $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'invalid_steps']);
            $errMsg='That number looks off. Try a value between 0 and 200,000.';
            \App\Http\Responders\SmsResponder::error($errMsg, 'invalid_steps', 400);
        }

        $dayCol = $this->resolveTargetDay($now, $dayOverride);
        if (!$dayCol) {
            $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'bad_day']);
            $errMsg='Unrecognized day. Use Mon..Sat or leave it out.';
            \App\Http\Responders\SmsResponder::error($errMsg, 'bad_day', 400);
        }

        $week = $this->resolveActiveWeek($pdo);
        if (!$week) {
            $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,$dayCol,'no_active_week']);
            $errMsg='No active week to record to. Please try again later.';
            \App\Http\Responders\SmsResponder::error($errMsg, 'no_active_week', 404);
        }

        Tx::with(function(\PDO $pdo) use ($week, $name, $dayCol, $steps) {
            $this->upsertSteps($pdo, $week, $name, $dayCol, $steps);
        });
        $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,$week,$dayCol,'ok']);

        // AI reply processing removed - replaced with opt-in reminder system
        // (AI system still available for other uses but not triggered after step writes)

        // Lifetime award check (best-effort)
        try {
            $this->handleLifetimeAward($pdo, $userId, $name, $e164);
        } catch (\Throwable $e) {
            error_log('SmsController::inbound lifetime award error: ' . $e->getMessage());
            // Continue with confirmation SMS even if award fails
        }

        $noonRule = !$dayOverride ? (intval($now->format('H'))<12 ? 'yesterday' : 'today') : strtolower($dayCol);
        $msg = "Recorded ".number_format($steps)." for $name on $noonRule.";

        \App\Http\Responders\SmsResponder::ok($msg);
    }

    public function status()
    {
        // Bootstrap environment
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        \App\Core\Env::bootstrap(dirname(__DIR__, 2));

        // Include required libraries for signature verification
        require_once dirname(__DIR__, 2) . '/api/common_sig.php';

        // Verify method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit;
        }

        // Verify signature using the same logic as status_callback.php
        $authToken = getenv('TWILIO_AUTH_TOKEN') ?: '';
        $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

        // Allow in test mode or trusted local addrs when no header present
        $shouldSkip = twilio_should_skip() && $signature === '';
        if (!$shouldSkip && $authToken !== '') {
            $info = twilio_verify($_POST, $signature, $authToken);
            if (getenv('TWILIO_SIG_DEBUG') === '1') {
                error_log('SIG url=' . $info['url'] . ' match=' . (int)$info['match'] . ' hdr=' . $info['header'] . ' exp=' . $info['expected'] . ' post=' . json_encode($_POST, JSON_UNESCAPED_SLASHES));
            }
            if (!$info['match']) {
                http_response_code(403);
                exit;
            }
        }

        // Collect fields (Twilio names)
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $record = [
            'message_sid' => $_POST['MessageSid'] ?? $_POST['SmsSid'] ?? null,
            'message_status' => $_POST['MessageStatus'] ?? $_POST['SmsStatus'] ?? null,
            'to_number' => $_POST['To'] ?? null,
            'from_number' => $_POST['From'] ?? null,
            'error_code' => $_POST['ErrorCode'] ?? null,
            'error_message' => $_POST['ErrorMessage'] ?? null,
            'messaging_service_sid' => $_POST['MessagingServiceSid'] ?? null,
            'account_sid' => $_POST['AccountSid'] ?? null,
            'api_version' => $_POST['ApiVersion'] ?? null,
            'raw_payload' => json_encode($_POST, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'received_at_utc' => $nowUtc,
        ];

        // Store to database
        try {
            $pdo = DB::pdo();

            // Upsert by message_sid (table now created by migration)
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
            $stmt->execute($record);
        } catch (\Throwable $e) {
            // Fail closed but acknowledge to Twilio to avoid retries storms
            error_log('SmsController::status error: ' . $e->getMessage());
            http_response_code(200); // acknowledge anyway
        }

        // Twilio expects 200 with no body
        http_response_code(200);
    }

    public function send()
    {
        // Bootstrap environment
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        \App\Core\Env::bootstrap(dirname(__DIR__, 2));
        // Load config helpers (env()) for internal auth
        require_once dirname(__DIR__, 2) . '/api/lib/config.php';

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
        $sid = Outbound::sendSMS($to, $body);

        if ($sid !== null) {
            echo json_encode(['ok' => true, 'sid' => $sid]);
        } else {
            http_response_code(502);
            echo json_encode(['error' => 'send_failed']);
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

            // user_stats table now created by migration
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
                    try {
                        $sid = \App\Services\Outbound::sendSMS($toPhone, $content);
                        if ($sid !== null) {
                            $pdo->prepare('UPDATE ai_messages SET sent_at = datetime(\'now\') WHERE id = :id')->execute([':id'=>$pdo->query('SELECT last_insert_rowid()')->fetchColumn()]);
                        }
                    } catch (\Throwable $e) {
                        // leave unsent
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('SmsController::inbound AI error: ' . $e->getMessage());
        }
    }

    private function getHelpText(bool $isAdmin): string
    {
        $lines = [
            "Commands:",
            "Steps: 12345 or 'Tue 12345'",
            "TOTAL - Your lifetime total",
            "WEEK - This week's progress",
            "INTERESTS SET a,b,c - Set interests",
            "INTERESTS LIST - Show interests",
            "REMINDERS ON|OFF - Toggle reminders",
            "REMINDERS WHEN MORNING|EVENING|HH:MM - Set time",
            "WALK or MENU - Command list"
        ];
        if ($isAdmin) {
            $lines[] = "UNDO - Revert last entry (admin only)";
        }
        return implode("\n", $lines);
    }

    private function handleTotalCommand(\PDO $pdo, string $name): string
    {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(monday,0) + COALESCE(tuesday,0) + COALESCE(wednesday,0) +
                                COALESCE(thursday,0) + COALESCE(friday,0) + COALESCE(saturday,0)), 0) AS total
            FROM entries WHERE name = ?
        ");
        $stmt->execute([$name]);
        $total = (int)$stmt->fetchColumn();
        return "Lifetime total: " . number_format($total) . " steps";
    }

    private function handleWeekCommand(\PDO $pdo, string $name): string
    {
        $week = $this->resolveActiveWeek($pdo);
        if (!$week) {
            return "No active week.";
        }
        $stmt = $pdo->prepare("
            SELECT COALESCE(monday,0) as mon, COALESCE(tuesday,0) as tue, COALESCE(wednesday,0) as wed,
                   COALESCE(thursday,0) as thu, COALESCE(friday,0) as fri, COALESCE(saturday,0) as sat
            FROM entries WHERE week = ? AND name = ?
        ");
        $stmt->execute([$week, $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return "No entries this week.";
        }
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $parts = [];
        foreach ($days as $d) {
            $steps = (int)$row[$d];
            if ($steps > 0) {
                $parts[] = ucfirst(substr($d, 0, 3)) . ": " . number_format($steps);
            }
        }
        $total = array_sum(array_map('intval', $row));
        return "This week: " . implode(", ", $parts) . " (Total: " . number_format($total) . ")";
    }

    private function handleInterestsSet(\PDO $pdo, string $name, string $interestsStr): string
    {
        $parts = array_map('trim', explode(',', $interestsStr));
        $parts = array_filter($parts, fn($x) => $x !== '');
        $parts = array_unique($parts);
        sort($parts);
        $normalized = implode(',', $parts);
        $stmt = $pdo->prepare("UPDATE users SET interests = ? WHERE name = ?");
        $stmt->execute([$normalized, $name]);
        return "Interests updated.";
    }

    private function handleInterestsList(\PDO $pdo, string $name): string
    {
        $stmt = $pdo->prepare("SELECT interests FROM users WHERE name = ?");
        $stmt->execute([$name]);
        $interests = $stmt->fetchColumn();
        if (!$interests) {
            return "No interests set.";
        }
        return "Interests: " . $interests;
    }

    private function handleRemindersToggle(\PDO $pdo, string $name, string $state): string
    {
        $stmt = $pdo->prepare("UPDATE users SET reminders_enabled = ? WHERE name = ?");
        $stmt->execute([$state === 'ON' ? 1 : 0, $name]);
        return "Reminders " . strtolower($state) . ".";
    }

    private function handleRemindersWhen(\PDO $pdo, string $name, string $when): string
    {
        require_once dirname(__DIR__, 2) . '/api/lib/settings.php';
        $raw = trim($when);
        $upVal = strtoupper($raw);
        if (in_array($upVal, ['MORNING', 'EVENING'], true)) {
            $val = $upVal === 'MORNING'
                ? (string)setting_get('reminders.default_morning', '07:30')
                : (string)setting_get('reminders.default_evening', '20:00');
        } else {
            if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*([AP]M)?\s*$/i', $raw, $m)) {
                return "Invalid time. Use MORNING, EVENING, or HH:MM.";
            }
            $h = (int)$m[1]; $min = (int)$m[2]; $ampm = isset($m[3]) ? strtoupper($m[3]) : '';
            if ($min < 0 || $min > 59) return "Invalid time. Use MORNING, EVENING, or HH:MM.";
            if ($ampm === '') {
                if ($h < 0 || $h > 23) return "Invalid time. Use MORNING, EVENING, or HH:MM.";
            } else {
                if ($h < 1 || $h > 12) return "Invalid time. Use MORNING, EVENING, or HH:MM.";
                if ($ampm === 'AM') { if ($h === 12) $h = 0; }
                if ($ampm === 'PM') { if ($h !== 12) $h += 12; }
            }
            $val = sprintf('%02d:%02d', $h, $min);
        }
        $stmt = $pdo->prepare("UPDATE users SET reminders_when = ? WHERE name = ?");
        $stmt->execute([$val, $name]);
        return "Reminder time set to " . $val . ".";
    }

    private function handleUndoCommand(\PDO $pdo, string $name): string
    {
        $undoEnabled = setting_get('sms.undo_enabled', '0') === '1';
        if (!$undoEnabled) {
            return "Undo not enabled.";
        }
        // Find last entry for user
        $stmt = $pdo->prepare("
            SELECT week, monday, tuesday, wednesday, thursday, friday, saturday
            FROM entries WHERE name = ? ORDER BY updated_at DESC LIMIT 1
        ");
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return "No entries to undo.";
        }
        // For simplicity, set the last updated column to NULL
        $week = $row['week'];
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $lastDay = null;
        foreach (array_reverse($days) as $d) {
            if ($row[$d] !== null) {
                $lastDay = $d;
                break;
            }
        }
        if (!$lastDay) {
            return "No steps to undo.";
        }
        $stmt = $pdo->prepare("UPDATE entries SET {$lastDay} = NULL, updated_at = datetime('now') WHERE week = ? AND name = ?");
        $stmt->execute([$week, $name]);
        return "Undid last entry.";
    }

    private function handleStopCommand(\PDO $pdo, int $userId, string $e164): string
    {
        // Set opted out flag
        $stmt = $pdo->prepare("UPDATE users SET phone_opted_out = 1 WHERE id = ?");
        $stmt->execute([$userId]);

        // Log the STOP action
        $stmt = $pdo->prepare("INSERT INTO sms_consent_log(user_id, action, phone_number, created_at) VALUES(?, 'STOP', ?, datetime('now'))");
        $stmt->execute([$userId, $e164]);

        return "You have been opted out of reminders. Reply START to opt back in.";
    }

    private function handleStartCommand(\PDO $pdo, int $userId, string $e164): string
    {
        // Clear opted out flag
        $stmt = $pdo->prepare("UPDATE users SET phone_opted_out = 0 WHERE id = ?");
        $stmt->execute([$userId]);

        // Log the START action
        $stmt = $pdo->prepare("INSERT INTO sms_consent_log(user_id, action, phone_number, created_at) VALUES(?, 'START', ?, datetime('now'))");
        $stmt->execute([$userId, $e164]);

        return "You have been opted back in to reminders.";
    }

    private function handleLifetimeAward(\PDO $pdo, int $userId, string $name, string $e164): void
    {
        require_once dirname(__DIR__, 2) . '/api/lib/awards.php';
        $awards = get_lifetime_awards($pdo, $userId);
        $newMilestones = [];
        foreach ($awards as $award) {
            if ($award['earned'] && $award['awarded_at'] === null) {
                // Check if this is newly earned (no previous award record)
                $stmt = $pdo->prepare("SELECT 1 FROM ai_awards WHERE user_id = ? AND kind = 'lifetime_steps' AND milestone_value = ?");
                $stmt->execute([$userId, $award['threshold']]);
                if (!$stmt->fetchColumn()) {
                    $newMilestones[] = $award['threshold'];
                }
            }
        }
        if (empty($newMilestones)) {
            return; // No new milestones
        }

        // Generate award image for the highest new milestone
        $milestone = max($newMilestones);
        $userData = ['name' => $name];
        try {
            $stmt = $pdo->prepare("SELECT interests FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && array_key_exists('interests', $row)) {
                $userData['interests'] = $row['interests'];
            }
        } catch (\Throwable $e) {
            // Ignore lookup failures; fallback prompt will handle missing interests
        }
        $res = ai_image_generate([
            'user_id' => $userId,
            'user_name' => $name,
            'user' => $userData,
            'award_kind' => 'lifetime_steps',
            'milestone_value' => $milestone
        ]);

        if ($res['ok'] ?? false) {
            $baseUrl = setting_get('app.public_base_url', '');
            if ($baseUrl !== '') {
                $url = rtrim($baseUrl, '/') . "/site/user.php?id={$userId}";
                $msg = "Congrats on " . number_format($milestone) . " steps! See your new award: {$url}";
                try {
                    \App\Services\Outbound::sendSMS($e164, $msg);
                } catch (\Throwable $e) {
                    error_log('Failed to send lifetime award SMS: ' . $e->getMessage());
                }
            }
        }
    }
}
