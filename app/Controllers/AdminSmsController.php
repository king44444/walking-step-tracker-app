<?php

namespace App\Controllers;

use App\Security\AdminAuth;
use App\Config\DB;
use App\Security\Csrf;
use App\Services\Outbound;

final class AdminSmsController
{
    public function index(): string
    {
        AdminAuth::require();
        $pdo = DB::pdo();

        // Get users for dropdown
        $users = $pdo->query("SELECT id, name FROM users WHERE phone_e164 IS NOT NULL ORDER BY LOWER(name)")->fetchAll();

        // CSRF token
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $csrfToken = Csrf::token();

        ob_start();
        require __DIR__ . '/../../templates/admin/sms.php';
        return ob_get_clean();
    }

    public function send(): string
    {
        AdminAuth::require();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();

        // Validate CSRF
        $csrf = $_POST['csrf'] ?? '';
        if (!Csrf::validate($csrf)) {
            http_response_code(403);
            return json_encode(['error' => 'Invalid CSRF']);
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        $attachmentIds = $_POST['attachments'] ?? [];

        if (!$userId || $body === '') {
            http_response_code(400);
            return json_encode(['error' => 'Missing user_id or body']);
        }

        $pdo = DB::pdo();

        // Get user phone
        $stmt = $pdo->prepare("SELECT name, phone_e164 FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !$user['phone_e164']) {
            http_response_code(404);
            return json_encode(['error' => 'User not found or no phone']);
        }

        // Build media URLs from attachment IDs
        $mediaUrls = [];
        if (!empty($attachmentIds)) {
            foreach ($attachmentIds as $attachId) {
                $stmt = $pdo->prepare("SELECT url FROM sms_attachments WHERE id = ? AND user_id = ?");
                $stmt->execute([$attachId, $userId]);
                $url = $stmt->fetchColumn();
                if ($url) {
                    $mediaUrls[] = $url;
                }
            }
        }

        // Send SMS
        $sid = Outbound::sendSMS($user['phone_e164'], $body, $mediaUrls);

        if ($sid) {
            // Store attachment metadata if any
            if (!empty($mediaUrls)) {
                $meta = json_encode(['attachments' => $mediaUrls]);
                $stmt = $pdo->prepare("UPDATE sms_outbound_audit SET meta = ? WHERE sid = ?");
                $stmt->execute([$meta, $sid]);
            }

            return json_encode(['ok' => true, 'sid' => $sid]);
        } else {
            http_response_code(500);
            return json_encode(['error' => 'Send failed']);
        }
    }

    public function upload(): string
    {
        AdminAuth::require();

        $userId = (int)($_POST['user_id'] ?? 0);
        if (!$userId) {
            http_response_code(400);
            return json_encode(['error' => 'Missing user_id']);
        }

        $uploadedFiles = [];
        $errors = [];

        // Create upload directory
        $uploadDir = sprintf('%s/public/assets/sms/%d/%s/%s',
            dirname(__DIR__, 2),
            $userId,
            date('Y'),
            date('m')
        );

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Process uploaded files
        if (!empty($_FILES['files'])) {
            $files = is_array($_FILES['files']['name']) ? $_FILES['files'] : [$this->rearrayFiles($_FILES['files'])];

            foreach ($files as $file) {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Upload error for ' . $file['name'];
                    continue;
                }

                // Validate file
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
                $maxSize = 5 * 1024 * 1024; // 5MB

                if (!in_array($file['type'], $allowedTypes)) {
                    $errors[] = 'Invalid file type: ' . $file['name'];
                    continue;
                }

                if ($file['size'] > $maxSize) {
                    $errors[] = 'File too large: ' . $file['name'];
                    continue;
                }

                // Generate unique filename
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = sprintf('%s.%s', bin2hex(random_bytes(16)), $ext);
                $filepath = $uploadDir . '/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    $url = sprintf('/assets/sms/%d/%s/%s/%s', $userId, date('Y'), date('m'), $filename);

                    // Store in database
                    $pdo = DB::pdo();
                    $stmt = $pdo->prepare("INSERT INTO sms_attachments(user_id, filename, url, mime_type, size, created_at) VALUES(?, ?, ?, ?, ?, datetime('now'))");
                    $stmt->execute([$userId, $file['name'], $url, $file['type'], $file['size']]);

                    $uploadedFiles[] = [
                        'id' => $pdo->lastInsertId(),
                        'name' => $file['name'],
                        'url' => $url,
                        'type' => $file['type'],
                        'size' => $file['size']
                    ];
                } else {
                    $errors[] = 'Failed to save: ' . $file['name'];
                }
            }
        }

        return json_encode([
            'uploaded' => $uploadedFiles,
            'errors' => $errors
        ]);
    }

    public function messages(): string
    {
        // Temporarily remove auth for debugging
        // AdminAuth::require();

        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) {
            http_response_code(400);
            $result = json_encode(['error' => 'Missing user_id']);
            error_log('messages() returning error: ' . $result);
            return $result;
        }

        // For now, return empty messages array
        $result = json_encode(['messages' => []]);
        error_log('messages() returning success: ' . $result);
        return $result;
    }

    public function startUser(): string
    {
        AdminAuth::require();

        $userId = (int)($_POST['user_id'] ?? 0);
        if (!$userId) {
            http_response_code(400);
            return json_encode(['error' => 'Missing user_id']);
        }

        $pdo = DB::pdo();

        // Get user phone
        $stmt = $pdo->prepare("SELECT phone_e164 FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $phone = $stmt->fetchColumn();

        if (!$phone) {
            http_response_code(404);
            return json_encode(['error' => 'User not found']);
        }

        // Clear opt-out and log START
        $stmt = $pdo->prepare("UPDATE users SET phone_opted_out = 0 WHERE id = ?");
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare("INSERT INTO sms_consent_log(user_id, action, phone_number, created_at) VALUES(?, 'START', ?, datetime('now'))");
        $stmt->execute([$userId, $phone]);

        return json_encode(['ok' => true]);
    }

    private function parseAttachments(?string $meta): array
    {
        if (!$meta) return [];

        $data = json_decode($meta, true);
        if (!$data) return [];

        $attachments = [];
        if (isset($data['attachments'])) {
            $attachments = $data['attachments'];
        } elseif (isset($data['files'])) {
            foreach ($data['files'] as $file) {
                $attachments[] = [
                    'url' => $file['url'] ?? '',
                    'mime' => $file['mime'] ?? '',
                    'size' => $file['size'] ?? 0
                ];
            }
        }

        return $attachments;
    }

    private function rearrayFiles(array $file): array
    {
        return [
            'name' => $file['name'],
            'type' => $file['type'],
            'tmp_name' => $file['tmp_name'],
            'error' => $file['error'],
            'size' => $file['size']
        ];
    }
}
