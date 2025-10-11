<?php

namespace App\Controllers;

use App\Security\AdminAuth;
use App\Config\DB;
use App\Security\Csrf;
use App\Support\Tx;

final class AdminUsersController
{
    public function index(): string
    {
        AdminAuth::require();
        $pdo = DB::pdo();
        $users = $pdo->query("SELECT id,name,phone_e164,sex,age,tag,is_active,photo_path FROM users ORDER BY LOWER(name)")->fetchAll();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $csrfToken = Csrf::token();
        ob_start();
        require __DIR__ . '/../../templates/admin/users.php';
        return ob_get_clean();
    }

    public function save(): string
    {
        AdminAuth::require();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? null);
        // accept JSON body too
        if (!$csrf) {
            $body = file_get_contents('php://input');
            $json = json_decode($body, true);
            if (is_array($json) && isset($json['csrf'])) $csrf = $json['csrf'];
        }
        if (!is_string($csrf) || !Csrf::validate($csrf)) {
            http_response_code(403);
            return json_encode(['error' => 'invalid csrf']);
        }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true) ?: $_POST;

        $action = $data['action'] ?? 'save';
        $pdo = DB::pdo();

        try {
            if ($action === 'delete') {
                $id = (int)($data['id'] ?? 0);
                if (!$id) throw new \Exception('id required');
                $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id'=>$id]);
                return json_encode(['ok'=>1]);
            }

            // save (create/update)
            $u = $data['user'] ?? $data;
            $name = trim((string)($u['name'] ?? ''));
            if ($name === '') throw new \Exception('name required');

            Tx::with(function($pdo) use ($u, $name) {
                // Normalize phone to E.164 if provided
                $phone = isset($u['phone_e164']) ? trim((string)$u['phone_e164']) : '';
                if ($phone !== '') {
                    require_once dirname(__DIR__, 2) . '/api/lib/phone.php';
                    $norm = to_e164($phone);
                    $phone = $norm ?: $phone; // fall back to raw if cannot normalize
                } else {
                    $phone = null;
                }
                if (!empty($u['id'])) {
                    $stmt = $pdo->prepare("UPDATE users SET name=:name, phone_e164=:phone, sex=:sex, age=:age, tag=:tag, is_active=:active, photo_path=:photo WHERE id=:id");
                    $stmt->execute([
                        ':name'=>$name,
                        ':phone'=>$phone,
                        ':sex'=>$u['sex'] ?? null,
                        ':age'=>strlen((string)($u['age'] ?? '')) ? $u['age'] : null,
                        ':tag'=>$u['tag'] ?? null,
                        ':active'=>!empty($u['is_active']) ? 1 : 0,
                        ':photo'=>$u['photo_path'] ?? null,
                        ':id'=>$u['id']
                    ]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO users(name,phone_e164,sex,age,tag,is_active,photo_path) VALUES(:name,:phone,:sex,:age,:tag,:active,:photo)");
                    $ins->execute([
                        ':name'=>$name,
                        ':phone'=>$phone,
                        ':sex'=>$u['sex'] ?? null,
                        ':age'=>strlen((string)($u['age'] ?? '')) ? $u['age'] : null,
                        ':tag'=>$u['tag'] ?? null,
                        ':active'=>!empty($u['is_active']) ? 1 : 0,
                        ':photo'=>$u['photo_path'] ?? null
                    ]);
                }
            });

            return json_encode(['ok'=>1]);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['error'=>$e->getMessage()]);
        }
    }
}
