<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AttendanceDays extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $this->upgradeSettings($pdo);
        $this->upgradeAiAwards($pdo);
        $this->upgradeAwardCache($pdo);
    }

    public function down(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $this->downgradeSettings($pdo);
        $this->downgradeAiAwards($pdo);
        $this->downgradeAwardCache($pdo);
    }

    private function upgradeSettings(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'settings')) {
            return;
        }

        $oldKey = 'milestones.attendance_weeks';
        $newKey = 'milestones.attendance_days';

        $old = $this->fetchSetting($pdo, $oldKey);
        $new = $this->fetchSetting($pdo, $newKey);
        $value = null;

        if ($old !== null && $new === null) {
            $stmt = $pdo->prepare("UPDATE settings SET key = :newKey, updated_at = COALESCE(updated_at, datetime('now')) WHERE key = :oldKey");
            $stmt?->execute([':newKey' => $newKey, ':oldKey' => $oldKey]);
            $value = (string)($old['value'] ?? '');
        } elseif ($new !== null) {
            $value = (string)($new['value'] ?? '');
            if ($old !== null) {
                $pdo->prepare("DELETE FROM settings WHERE key = :oldKey")?->execute([':oldKey' => $oldKey]);
            }
        }

        if ($value === null) {
            return;
        }

        $converted = $this->weeksToDaysList($value);
        if ($converted !== null) {
            $stmt = $pdo->prepare("UPDATE settings SET value = :val, updated_at = datetime('now') WHERE key = :key");
            $stmt?->execute([':val' => $converted, ':key' => $newKey]);
        }
    }

    private function downgradeSettings(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'settings')) {
            return;
        }

        $oldKey = 'milestones.attendance_days';
        $newKey = 'milestones.attendance_weeks';

        $old = $this->fetchSetting($pdo, $oldKey);
        $new = $this->fetchSetting($pdo, $newKey);
        $value = null;

        if ($old !== null && $new === null) {
            $stmt = $pdo->prepare("UPDATE settings SET key = :newKey, updated_at = COALESCE(updated_at, datetime('now')) WHERE key = :oldKey");
            $stmt?->execute([':newKey' => $newKey, ':oldKey' => $oldKey]);
            $value = (string)($old['value'] ?? '');
        } elseif ($new !== null) {
            $value = (string)($new['value'] ?? '');
            if ($old !== null) {
                $pdo->prepare("DELETE FROM settings WHERE key = :oldKey")?->execute([':oldKey' => $oldKey]);
            }
        }

        if ($value === null) {
            return;
        }

        $converted = $this->daysToWeeksList($value);
        if ($converted !== null) {
            $stmt = $pdo->prepare("UPDATE settings SET value = :val, updated_at = datetime('now') WHERE key = :key");
            $stmt?->execute([':val' => $converted, ':key' => $newKey]);
        }
    }

    private function upgradeAiAwards(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'ai_awards')) {
            return;
        }

        $pdo->exec("
            UPDATE ai_awards
            SET kind = 'attendance_days',
                milestone_value = milestone_value * 7
            WHERE kind = 'attendance_weeks'
        ");
    }

    private function downgradeAiAwards(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'ai_awards')) {
            return;
        }

        $pdo->exec("
            UPDATE ai_awards
            SET kind = 'attendance_weeks',
                milestone_value = CAST(milestone_value / 7 AS INTEGER)
            WHERE kind = 'attendance_days' AND (milestone_value % 7) = 0
        ");
    }

    private function upgradeAwardCache(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'user_awards_cache')) {
            return;
        }

        $pdo->exec("
            UPDATE user_awards_cache
            SET award_key = 'attendance_days_' || (threshold * 7),
                threshold = threshold * 7
            WHERE award_key LIKE 'attendance_weeks_%'
        ");
    }

    private function downgradeAwardCache(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'user_awards_cache')) {
            return;
        }

        $pdo->exec("
            UPDATE user_awards_cache
            SET award_key = 'attendance_weeks_' || (threshold / 7),
                threshold = CAST(threshold / 7 AS INTEGER)
            WHERE award_key LIKE 'attendance_days_%' AND (threshold % 7) = 0
        ");
    }

    private function fetchSetting(PDO $pdo, string $key): ?array
    {
        $stmt = $pdo->prepare("SELECT key, value, updated_at FROM settings WHERE key = :key LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function weeksToDaysList(string $raw): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn($p) => $p !== '');
        if (empty($parts)) {
            return null;
        }

        $days = [];
        foreach ($parts as $part) {
            if (!ctype_digit($part)) {
                return null;
            }
            $value = (int)$part;
            if ($value <= 0 || $value > 366) {
                return null;
            }
            $days[$value * 7] = $value * 7;
        }

        ksort($days, SORT_NUMERIC);
        return implode(',', array_values($days));
    }

    private function daysToWeeksList(string $raw): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn($p) => $p !== '');
        if (empty($parts)) {
            return null;
        }

        $weeks = [];
        foreach ($parts as $part) {
            if (!ctype_digit($part)) {
                return null;
            }
            $value = (int)$part;
            if ($value <= 0 || ($value % 7) !== 0) {
                return null;
            }
            $weeks[$value / 7] = (int)($value / 7);
        }

        ksort($weeks, SORT_NUMERIC);
        return implode(',', array_values($weeks));
    }

    private function tableExists(PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->execute([':name' => $name]);
        return $stmt->fetchColumn() !== false;
    }
}
