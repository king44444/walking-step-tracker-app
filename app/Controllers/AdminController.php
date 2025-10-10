<?php

namespace App\Controllers;

use App\Security\AdminAuth;
use App\Config\DB;
use App\Security\Csrf;
use App\Support\Tx;

final class AdminController
{
    public function ai(): string
    {
        AdminAuth::require();
        ob_start();
        require __DIR__ . '/../../templates/admin/ai.php';
        return ob_get_clean();
    }

    public function entries(): string
    {
        AdminAuth::require();
        $pdo = DB::pdo();

        // Load weeks (compatible with legacy schema)
        $weeks = $pdo->query("SELECT week, COALESCE(label, week) AS label, COALESCE(finalized, finalized_at IS NOT NULL, 0) AS finalized FROM weeks ORDER BY week DESC")->fetchAll();

        $curWeek = $_GET['week'] ?? ($weeks[0]['week'] ?? '');
        $entries = [];
        if ($curWeek) {
            $st = $pdo->prepare("SELECT * FROM entries WHERE week = :w ORDER BY LOWER(name)");
            $st->execute([':w' => $curWeek]);
            $entries = $st->fetchAll();
        }

        // Minimal users list for dropdowns
        $users = $pdo->query("SELECT id,name FROM users ORDER BY LOWER(name)")->fetchAll();

        // CSRF token
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $csrfToken = Csrf::token();

        ob_start();
        require __DIR__ . '/../../templates/admin/entries.php';
        return ob_get_clean();
    }

    private function getCsrfFromRequest(): ?string
    {
        // Header 'X-CSRF' or JSON/body param 'csrf' or POST param
        $h = $_SERVER['HTTP_X_CSRF'] ?? null;
        if ($h) return $h;
        if (php_sapi_name() !== 'cli') {
            $body = file_get_contents('php://input');
            $json = json_decode($body, true);
            if (is_array($json) && isset($json['csrf'])) return $json['csrf'];
        }
        return $_POST['csrf'] ?? null;
    }

    public function saveEntries(): string
    {
        AdminAuth::require();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $csrf = $this->getCsrfFromRequest();
        if (!is_string($csrf) || !Csrf::validate($csrf)) {
            http_response_code(403);
            return 'Invalid CSRF';
        }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true) ?: $_POST;

        $pdo = DB::pdo();

        if (isset($data['action']) && $data['action'] === 'delete') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                return json_encode(['error' => 'invalid id']);
            }
            $pdo->prepare("DELETE FROM entries WHERE id = :id")->execute([':id' => $id]);
            return json_encode(['ok' => 1]);
        }

        try {
            Tx::with(function($pdo) use ($data) {
                // Support bulk rows or single row updates
                $rows = [];
                if (isset($data['entries']) && is_array($data['entries'])) {
                    $rows = $data['entries'];
                } else {
                    // infer single row from posted fields
                    $r = [];
                    foreach (['id','name','week','mon','tue','wed','thu','fri','sat','sun','tag'] as $k) {
                        if (isset($data[$k])) $r[$k] = $data[$k];
                    }
                    if (!empty($r)) $rows[] = $r;
                }

                foreach ($rows as $r) {
                    $week = $r['week'] ?? null;
                    if (!$week) throw new \Exception('week is required');
                    // ensure week exists
                    $pdo->prepare("INSERT INTO weeks(week, label, finalized) VALUES(:w, :l, 0)
                                   ON CONFLICT(week) DO NOTHING")
                        ->execute([':w'=>$week, ':l'=>$week]);

                    // If id present -> update that row
                    if (!empty($r['id'])) {
                        // Check locked state for this entry
                        $chk = $pdo->prepare("SELECT locked FROM entries WHERE id = :id LIMIT 1");
                        $chk->execute([':id' => $r['id']]);
                        $locked = $chk->fetchColumn();
                        if ($locked) throw new \Exception('Entry is locked and cannot be modified.');

                        $upd = $pdo->prepare("UPDATE entries SET monday=:mo,tuesday=:tu,wednesday=:we,thursday=:th,friday=:fr,saturday=:sa,sunday=:su,tag=:tag,updated_at=datetime('now') WHERE id=:id");
                        $upd->execute([
                            ':mo' => $r['mon'] ?? null,
                            ':tu' => $r['tue'] ?? null,
                            ':we' => $r['wed'] ?? null,
                            ':th' => $r['thu'] ?? null,
                            ':fr' => $r['fri'] ?? null,
                            ':sa' => $r['sat'] ?? null,
                            ':su' => $r['sun'] ?? null,
                            ':tag'=> $r['tag'] ?? null,
                            ':id' => $r['id']
                        ]);
                    } else {
                        // upsert by week+name
                        $name = trim((string)($r['name'] ?? ''));
                        if ($name === '') throw new \Exception('name is required for insert');

                        $exists = $pdo->prepare("SELECT id, locked FROM entries WHERE week=:w AND name=:n LIMIT 1");
                        $exists->execute([':w'=>$week, ':n'=>$name]);
                        $ex = $exists->fetch();
                        if ($ex && !empty($ex['locked'])) throw new \Exception('Entry is locked and cannot be modified.');

                        if ($ex) {
                            $upd = $pdo->prepare("UPDATE entries SET monday=:mo,tuesday=:tu,wednesday=:we,thursday=:th,friday=:fr,saturday=:sa,sunday=:su,tag=:tag,updated_at=datetime('now') WHERE id=:id");
                            $upd->execute([
                                ':mo' => $r['mon'] ?? null,
                                ':tu' => $r['tue'] ?? null,
                                ':we' => $r['wed'] ?? null,
                                ':th' => $r['thu'] ?? null,
                                ':fr' => $r['fri'] ?? null,
                                ':sa' => $r['sat'] ?? null,
                                ':su' => $r['sun'] ?? null,
                                ':tag'=> $r['tag'] ?? null,
                                ':id' => $ex['id']
                            ]);
                        } else {
                            $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sunday,sex,age,tag)
                                                  VALUES(:w,:n,:mo,:tu,:we,:th,:fr,:sa,:su,NULL,NULL,:tag)");
                            $ins->execute([
                                ':w'=>$week, ':n'=>$name,
                                ':mo' => $r['mon'] ?? null,
                                ':tu' => $r['tue'] ?? null,
                                ':we' => $r['wed'] ?? null,
                                ':th' => $r['thu'] ?? null,
                                ':fr' => $r['fri'] ?? null,
                                ':sa' => $r['sat'] ?? null,
                                ':su' => $r['sun'] ?? null,
                                ':tag'=> $r['tag'] ?? null
                            ]);
                        }
                    }
                }
            });
            return json_encode(['ok'=>1]);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['error'=>$e->getMessage()]);
        }
    }

    public function finalizeWeek(): string
    {
        AdminAuth::require();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $csrf = $this->getCsrfFromRequest();
        if (!is_string($csrf) || !Csrf::validate($csrf)) {
            http_response_code(403);
            return 'Invalid CSRF';
        }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true) ?: $_POST;
        $week = trim((string)($data['week'] ?? ''));

        if (!$week) {
            http_response_code(400);
            return 'week required';
        }

        $pdo = DB::pdo();

        try {
            Tx::with(function($pdo) use ($week) {
                // Snapshot entries
                $q = $pdo->prepare("SELECT name,monday,tuesday,wednesday,thursday,friday,saturday,sunday,sex,age,tag FROM entries WHERE week=:w ORDER BY LOWER(name)");
                $q->execute([':w'=>$week]);
                $rows = $q->fetchAll();
                $json = json_encode($rows, JSON_UNESCAPED_SLASHES);

                // create snapshots table if missing (migrate.php should have it)
                $pdo->prepare("INSERT INTO snapshots(week,json) VALUES(:w,:j)
                               ON CONFLICT(week) DO UPDATE SET json=excluded.json, created_at=datetime('now')")->execute([':w'=>$week, ':j'=>$json]);

                // set finalized flag and timestamp if column exists
                // Try both finalized (int) and finalized_at (datetime) approaches for compatibility
                try {
                    $pdo->prepare("UPDATE weeks SET finalized=1 WHERE week=:w")->execute([':w'=>$week]);
                } catch (\Throwable $e) {
                    // ignore
                }
                try {
                    $pdo->prepare("UPDATE weeks SET finalized_at=datetime('now') WHERE week=:w")->execute([':w'=>$week]);
                } catch (\Throwable $e) {
                    // ignore
                }

                // Lock entries if column exists
                try {
                    $pdo->prepare("UPDATE entries SET locked=1 WHERE week=:w")->execute([':w'=>$week]);
                } catch (\Throwable $e) {
                    // ignore
                }
            });

            return json_encode(['ok'=>1]);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['error'=>$e->getMessage()]);
        }
    }

    public function addAllActiveToWeek(): string
    {
        AdminAuth::require();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $csrf = $this->getCsrfFromRequest();
        if (!is_string($csrf) || !Csrf::validate($csrf)) {
            http_response_code(403);
            return 'Invalid CSRF';
        }

        $body = file_get_contents('php://input');
        $data = json_decode($body, true) ?: $_POST;
        $week = trim((string)($data['week'] ?? ''));

        if (!$week) {
            http_response_code(400);
            return 'week required';
        }

        $pdo = DB::pdo();

        try {
            Tx::with(function($pdo) use ($week, &$added, &$skipped) {
                $q = $pdo->query("SELECT name,sex,age,tag FROM users WHERE is_active=1");
                $ins = $pdo->prepare("INSERT INTO entries(week,name,monday,tuesday,wednesday,thursday,friday,saturday,sunday,sex,age,tag)
                                      VALUES(:w,:n,NULL,NULL,NULL,NULL,NULL,NULL,NULL,:sex,:age,:tag)
                                      ON CONFLICT(week,name) DO NOTHING");
                $added = 0; $skipped = 0;
                foreach ($q as $u) {
                    $ins->execute([':w'=>$week, ':n'=>$u['name'], ':sex'=>$u['sex']?:null, ':age'=>$u['age']?:null, ':tag'=>$u['tag']?:null]);
                    $added += ($ins->rowCount() > 0) ? 1 : 0;
                    $skipped += ($ins->rowCount() === 0) ? 1 : 0;
                }
            });
            return json_encode(['ok'=>1, 'added'=>$added ?? 0, 'skipped'=>$skipped ?? 0]);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['error'=>$e->getMessage()]);
        }
    }
}
