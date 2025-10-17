<?php
declare(strict_types=1);

require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/award_labels.php';

/**
 * Lightweight schema introspection helpers to support both legacy and new schemas.
 */
function _aw_has_col(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->query("PRAGMA table_info(".$table.")");
        $cols = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($cols as $c) { if (($c['name'] ?? '') === $col) return true; }
    } catch (Throwable $e) {}
    return false;
}

function _aw_day_cols(PDO $pdo): array {
    // Prefer verbose columns if present, else short forms; optionally include Sunday
    $verbose = ['monday','tuesday','wednesday','thursday','friday','saturday'];
    if (_aw_has_col($pdo, 'entries', 'sunday')) {
        $verbose[] = 'sunday';
    }
    if (_aw_has_col($pdo,'entries','monday')) {
        return $verbose;
    }
    // Fallback to short names used in some tests
    $short = ['mon','tue','wed','thu','fri','sat'];
    if (_aw_has_col($pdo, 'entries', 'sun')) {
        $short[] = 'sun';
    }
    return $short;
}

function _aw_user_binding(PDO $pdo, int $userId): array {
    // If entries has user_id, join by it; else join on name
    $byUserId = _aw_has_col($pdo,'entries','user_id');
    return [
        'byUserId' => $byUserId,
        'userId'   => $userId,
    ];
}

function _aw_week_join(PDO $pdo): array {
    // Determine how to join entries->weeks and which starts_on expression to use
    $hasWeekText   = _aw_has_col($pdo,'entries','week');
    $hasWeekId     = _aw_has_col($pdo,'entries','week_id');
    $weeksHasId    = _aw_has_col($pdo,'weeks','id');
    $weeksHasWeek  = _aw_has_col($pdo,'weeks','week');
    $weeksHasStart = _aw_has_col($pdo,'weeks','starts_on');

    if ($hasWeekId && $weeksHasId && $weeksHasStart) {
        return [
            'join' => 'e.week_id = w.id',
            'starts' => 'w.starts_on'
        ];
    }
    // Legacy textual join, prefer COALESCE to support either column in weeks
    $startsExpr = $weeksHasStart ? 'COALESCE(w.starts_on, w.week)' : 'w.week';
    if ($hasWeekText && ($weeksHasWeek || $weeksHasStart)) {
        return [
            'join' => 'e.week = COALESCE(w.week, w.starts_on)',
            'starts' => $startsExpr
        ];
    }
    // As a last resort, allow selecting without join (no starts_on available)
    return [ 'join' => '1=1', 'starts' => "''" ];
}

function _aw_fetch_entries(PDO $pdo, int $userId, string $userName, array $dayCols): array {
    if (empty($dayCols)) {
        return [];
    }
    $selectDays = implode(', ', array_map(static fn($d) => "e.$d AS $d", $dayCols));
    $join = _aw_week_join($pdo);
    $starts = $join['starts'];
    $selectList = $selectDays !== '' ? $selectDays . ', ' : '';
    $selectList .= $starts . ' AS starts_on';

    $byUser = _aw_user_binding($pdo, $userId);
    if ($byUser['byUserId']) {
        $sql = "SELECT $selectList FROM entries e JOIN weeks w ON {$join['join']} WHERE e.user_id = :uid ORDER BY starts_on ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
    } else {
        if ($userName === '') {
            return [];
        }
        $sql = "SELECT $selectList FROM entries e JOIN weeks w ON {$join['join']} WHERE e.name = :name ORDER BY starts_on ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':name' => $userName]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function _aw_extract_day_steps(array $entry, bool $includeSunday): array {
    $daySteps = [];
    foreach (['monday','tuesday','wednesday','thursday','friday','saturday'] as $day) {
        $short = substr($day, 0, 3);
        $value = $entry[$day] ?? $entry[$short] ?? 0;
        $daySteps[$day] = is_numeric($value) ? (int)$value : (int)($value ?? 0);
    }
    if ($includeSunday) {
        $value = $entry['sunday'] ?? $entry['sun'] ?? 0;
        $daySteps['sunday'] = is_numeric($value) ? (int)$value : (int)($value ?? 0);
    }
    return $daySteps;
}

function _aw_kind_slug(string $kind): string {
    if (function_exists('ai_image_slug')) {
        return ai_image_slug($kind);
    }
    $s = strtolower($kind);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    return trim((string)$s, '-');
}

function _aw_normalize_award_path(string $storedPath): ?string {
    $relative = ltrim($storedPath, '/');
    $relative = preg_replace('#^site/#', '', $relative);
    if (strpos($relative, 'assets/') !== 0) {
        $relative = 'assets/' . $relative;
    }
    $absolute = dirname(__DIR__, 2) . '/site/' . $relative;
    if (is_file($absolute)) {
        return $relative;
    }
    return null;
}

function _aw_fetch_award_images(PDO $pdo, int $userId, string $kind, array $thresholds): array {
    $paths = [];
    if (empty($thresholds) || !_aw_has_col($pdo, 'ai_awards', 'milestone_value')) {
        return $paths;
    }
    $placeholders = implode(',', array_fill(0, count($thresholds), '?'));
    $sql = "SELECT milestone_value, image_path FROM ai_awards WHERE user_id = ? AND kind = ? AND milestone_value IN ($placeholders)";
    $params = array_merge([$userId, $kind], array_map('intval', $thresholds));
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $milestone = (int)($row['milestone_value'] ?? 0);
            if ($milestone <= 0) {
                continue;
            }
            $storedPath = (string)($row['image_path'] ?? '');
            if ($storedPath !== '') {
                $normalized = _aw_normalize_award_path($storedPath);
                if ($normalized !== null) {
                    $paths[$milestone] = $normalized;
                    continue;
                }
            }
            $paths[$milestone] = find_award_image($userId, $milestone, $kind);
        }
    } catch (Throwable $e) {
        // ignore lookup failures and fall back to filesystem scan
    }
    return $paths;
}

/**
 * Parse comma-separated milestone string into sorted unique int array.
 *
 * @param string $s Comma-separated integers e.g. "100000,250000"
 * @return int[] Sorted unique positive integers
 */
function parse_milestones_string(string $s): array {
    $parts = array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== '');
    $nums = [];
    foreach ($parts as $p) {
        $n = (int)$p;
        if ($n > 0) $nums[] = $n;
    }
    $nums = array_values(array_unique($nums));
    sort($nums, SORT_NUMERIC);
    return $nums;
}

/**
 * Get lifetime awards for a user with computed earned dates.
 * Uses cache table user_awards_cache for performance (write-through).
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return array List of award objects with earned status and dates
 */
function get_lifetime_awards(PDO $pdo, int $userId): array {
    $thresholds = parse_milestones_string(setting_get('milestones.lifetime_steps', '100000,250000,500000,750000,1000000'));
    $awards = [];
    
    // Resolve user name when needed
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = :uid");
    $userStmt->execute([':uid' => $userId]);
    $userName = (string)($userStmt->fetchColumn() ?: '');
    
    // Sum user's total steps across all entries; support both schemas
    $days = _aw_day_cols($pdo);
    $sumExpr = implode(' + ', array_map(fn($d)=>"COALESCE(e.$d,0)", $days));
    $byUser = _aw_user_binding($pdo, $userId);
    if ($byUser['byUserId']) {
        $sql = "SELECT COALESCE(SUM($sumExpr),0) AS total FROM entries e WHERE e.user_id = :uid";
        $totalStmt = $pdo->prepare($sql);
        $totalStmt->execute([':uid' => $userId]);
    } else {
        if ($userName === '') return [];
        $sql = "SELECT COALESCE(SUM($sumExpr),0) AS total FROM entries e WHERE e.name = :name";
        $totalStmt = $pdo->prepare($sql);
        $totalStmt->execute([':name' => $userName]);
    }
    $totalSteps = (int)$totalStmt->fetchColumn();
    
    // Get image paths from ai_awards table (optional; table may not exist in tests)
    $imagePaths = [];
    $imagePaths = _aw_fetch_award_images($pdo, $userId, 'lifetime_steps', $thresholds);
    
    foreach ($thresholds as $threshold) {
        $key = "lifetime_{$threshold}";
        $earned = $totalSteps >= $threshold;
        
        // Get image path from ai_awards table or use fallback
        if (!empty($imagePaths[$threshold])) {
            $imageUrl = $imagePaths[$threshold];
        } else {
            // Try to find an existing award image on disk before falling back to the generic no-photo
            $imageUrl = find_award_image($userId, $threshold, 'lifetime_steps');
        }
        $thumbUrl = $imageUrl; // Use same image for thumb (no separate thumbs directory)
        
        $award = [
            'key' => $key,
            'threshold' => $threshold,
            'earned' => $earned,
            'awarded_at' => null,
            'image_url' => $imageUrl,
            'thumb_url' => $thumbUrl,
            'title' => format_threshold($threshold)
        ];
        
        if ($earned) {
            // Check cache first
            $cacheStmt = $pdo->prepare("
                SELECT awarded_at FROM user_awards_cache 
                WHERE user_id = :uid AND award_key = :key
            ");
            $cacheStmt->execute([':uid' => $userId, ':key' => $key]);
            $cachedDate = $cacheStmt->fetchColumn();
            
            if ($cachedDate !== false) {
                $award['awarded_at'] = $cachedDate;
            } else {
                // Compute and cache
                $awardedDate = compute_awarded_date($pdo, $userId, $threshold);
                if ($awardedDate !== null) {
                    $award['awarded_at'] = $awardedDate;
                    
                    // Write to cache
                    try {
                        $insertStmt = $pdo->prepare("
                            INSERT OR REPLACE INTO user_awards_cache (user_id, award_key, threshold, awarded_at)
                            VALUES (:uid, :key, :threshold, :date)
                        ");
                        $insertStmt->execute([
                            ':uid' => $userId,
                            ':key' => $key,
                            ':threshold' => $threshold,
                            ':date' => $awardedDate
                        ]);
                    } catch (Exception $e) {
                        error_log("Failed to cache award date: " . $e->getMessage());
                    }
                }
            }
        }
        
        $awards[] = $award;
    }
    
    return $awards;
}

/**
 * Get attendance-day awards for a user with earned status and awarded dates.
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param array<int,int>|null $customThresholds Optional thresholds override (for tests)
 * @return array<int,array<string,mixed>> Award data keyed sequentially
 */
function get_attendance_days_awards(PDO $pdo, int $userId, ?array $customThresholds = null): array {
    if ($customThresholds !== null) {
        $tmp = [];
        foreach ($customThresholds as $value) {
            $n = (int)$value;
            if ($n > 0) {
                $tmp[$n] = $n;
            }
        }
        $thresholds = array_values($tmp);
        sort($thresholds, SORT_NUMERIC);
    } else {
        $thresholds = parse_milestones_string(setting_get('milestones.attendance_days', '175,350,700'));
    }
    if (empty($thresholds)) {
        return [];
    }

    $awards = [];
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = :uid");
    $userStmt->execute([':uid' => $userId]);
    $userName = (string)($userStmt->fetchColumn() ?: '');

    $dayCols = _aw_day_cols($pdo);
    $includeSunday = in_array('sunday', $dayCols, true) || in_array('sun', $dayCols, true);
    $entries = _aw_fetch_entries($pdo, $userId, $userName, $dayCols);

    // Collect daily steps keyed by date (keep max >0 value for duplicates)
    $dailySteps = [];
    foreach ($entries as $entry) {
        $weekStart = (string)($entry['starts_on'] ?? '');
        if ($weekStart === '') {
            continue;
        }
        $daySteps = _aw_extract_day_steps($entry, $includeSunday);
        $expanded = expand_week_to_daily_dates($weekStart, $daySteps, $includeSunday);
        foreach ($expanded as $date => $steps) {
            $intSteps = (int)$steps;
            if (!array_key_exists($date, $dailySteps) || $intSteps > $dailySteps[$date]) {
                $dailySteps[$date] = $intSteps;
            }
        }
    }

    ksort($dailySteps);
    $awardDates = [];
    $totalCheckinDays = 0;
    $thresholdIndex = 0;
    $thresholdCount = count($thresholds);

    foreach ($dailySteps as $date => $steps) {
        if ($steps <= 0) {
            continue;
        }
        $totalCheckinDays++;
        while ($thresholdIndex < $thresholdCount && $totalCheckinDays >= $thresholds[$thresholdIndex]) {
            $target = $thresholds[$thresholdIndex];
            if (!isset($awardDates[$target])) {
                $awardDates[$target] = $date;
            }
            $thresholdIndex++;
        }
    }

    $totalEarnedDays = $totalCheckinDays;

    $imagePaths = _aw_fetch_award_images($pdo, $userId, 'attendance_days', $thresholds);
    $legacyPaths = [];
    $legacyThresholds = [];
    foreach ($thresholds as $t) {
        if ($t > 0 && $t % 7 === 0) {
            $legacyThresholds[] = (int)($t / 7);
        }
    }
    if (!empty($legacyThresholds)) {
        $legacy = _aw_fetch_award_images($pdo, $userId, 'attendance_weeks', $legacyThresholds);
        foreach ($legacy as $weeks => $path) {
            $legacyPaths[(int)$weeks * 7] = $path;
        }
    }

    $hasCache = _aw_has_col($pdo, 'user_awards_cache', 'award_key');
    $cacheSelect = $hasCache ? $pdo->prepare("SELECT awarded_at FROM user_awards_cache WHERE user_id = :uid AND award_key = :key") : null;
    $cacheUpsert = $hasCache ? $pdo->prepare("
        INSERT OR REPLACE INTO user_awards_cache (user_id, award_key, threshold, awarded_at)
        VALUES (:uid, :key, :threshold, :date)
    ") : null;

    foreach ($thresholds as $threshold) {
        $key = "attendance_days_{$threshold}";
        $earned = $totalEarnedDays >= $threshold;

        $imageUrl = $imagePaths[$threshold]
            ?? $legacyPaths[$threshold]
            ?? find_award_image($userId, $threshold, 'attendance_days');

        $awardedAt = null;
        if ($earned) {
            $cachedDate = null;
            if ($cacheSelect) {
                $cacheSelect->execute([':uid' => $userId, ':key' => $key]);
                $cachedDate = $cacheSelect->fetchColumn();
                $cacheSelect->closeCursor();
            }
            if ($cachedDate !== false && $cachedDate !== null) {
                $awardedAt = (string)$cachedDate;
            } else {
                $awardedAt = $awardDates[$threshold] ?? null;
                if ($awardedAt !== null && $cacheUpsert) {
                    try {
                        $cacheUpsert->execute([
                            ':uid' => $userId,
                            ':key' => $key,
                            ':threshold' => $threshold,
                            ':date' => $awardedAt,
                        ]);
                    } catch (Throwable $e) {
                        error_log("Failed to cache attendance award date: " . $e->getMessage());
                    }
                }
            }
        }

        $awards[] = [
            'key' => $key,
            'threshold' => $threshold,
            'earned' => $earned,
            'awarded_at' => $earned ? $awardedAt : null,
            'image_url' => $imageUrl,
            'thumb_url' => $imageUrl,
            'title' => award_label('attendance_days', $threshold),
        ];
    }

    return $awards;
}

/**
 * Compute the date when a user first reached a step threshold.
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $threshold Step threshold
 * @return string|null ISO date YYYY-MM-DD or null if not reached
 */
function compute_awarded_date(PDO $pdo, int $userId, int $threshold): ?string {
    // Resolve user name if needed
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = :uid");
    $userStmt->execute([':uid' => $userId]);
    $userName = (string)($userStmt->fetchColumn() ?: '');
    
    $dayCols = _aw_day_cols($pdo);
    $entries = _aw_fetch_entries($pdo, $userId, $userName, $dayCols);
    
    if (empty($entries)) {
        return null;
    }
    $includeSunday = in_array('sunday', $dayCols, true) || in_array('sun', $dayCols, true);
    
    // Build cumulative daily steps
    $dailySteps = [];
    foreach ($entries as $entry) {
        $weekStart = $entry['starts_on'];
        
        // Skip entries without a valid week start date
        if (empty($weekStart)) {
            continue;
        }
        
        $daySteps = _aw_extract_day_steps($entry, $includeSunday);
        $expanded = expand_week_to_daily_dates($weekStart, $daySteps, $includeSunday);
        foreach ($expanded as $date => $steps) {
            $dailySteps[$date] = $steps;
        }
    }
    
    // Sort by date and compute cumulative
    ksort($dailySteps);
    $cumulative = 0;
    foreach ($dailySteps as $date => $steps) {
        $cumulative += $steps;
        if ($cumulative >= $threshold) {
            return $date;
        }
    }
    
    return null;
}

/**
 * Find the most recent award image for a user/kind/threshold.
 * Award images are named like: lifetime-steps-100000-20251010.svg or .webp
 * This function considers both .webp and .svg candidates and returns the newest file
 * by modification time so regenerated webp files win over older svg files.
 * 
 * @param int $userId User ID
 * @param int $threshold Step threshold
 * @param string $kind Award kind slug (e.g., lifetime_steps, attendance_days)
 * @return string Relative path to image or fallback
 */
function find_award_image(int $userId, int $threshold, string $kind = 'lifetime_steps'): string {
    $awardsDir = __DIR__ . '/../../site/assets/awards/' . $userId;
    
    // Check if directory exists
    if (!is_dir($awardsDir)) {
        return 'assets/admin/no-photo.svg';
    }
    
    $searches = [
        ['slug' => _aw_kind_slug($kind), 'threshold' => $threshold],
    ];
    if ($kind === 'attendance_days' && $threshold > 0 && $threshold % 7 === 0) {
        $searches[] = [
            'slug' => _aw_kind_slug('attendance_weeks'),
            'threshold' => (int)($threshold / 7),
        ];
    }
    $patterns = [];
    foreach ($searches as $pair) {
        $slug = $pair['slug'];
        $val = $pair['threshold'];
        $patterns[] = "{$slug}-{$val}-*.webp";
        $patterns[] = "{$slug}-{$val}-*.svg";
    }
    
    $candidates = [];
    foreach ($patterns as $p) {
        $matches = glob($awardsDir . '/' . $p);
        if (!empty($matches)) {
            foreach ($matches as $f) {
                // Use file modification time as the tiebreaker; fall back to 0 if unavailable
                $mtime = file_exists($f) ? filemtime($f) : 0;
                $candidates[$f] = $mtime;
            }
        }
    }
    
    if (empty($candidates)) {
        return 'assets/admin/no-photo.svg';
    }
    
    // Sort candidates by modification time, newest first
    arsort($candidates);
    $bestFile = array_key_first($candidates);
    
    // Return relative path from site directory
    return 'assets/awards/' . $userId . '/' . basename($bestFile);
}

/**
 * Format a threshold number into a readable title.
 * 
 * @param int $threshold Step count
 * @return string Formatted title like "100,000 Steps"
 */
function format_threshold(int $threshold): string {
    return number_format($threshold) . ' Steps';
}
