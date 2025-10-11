<?php
declare(strict_types=1);

require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/settings.php';

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
    // Prefer verbose columns if present, else short forms
    $verbose = ['monday','tuesday','wednesday','thursday','friday','saturday'];
    if (_aw_has_col($pdo,'entries','monday')) return $verbose;
    // Fallback to short names used in some tests
    return ['mon','tue','wed','thu','fri','sat'];
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
    try {
        $hasAiAwards = _aw_has_col($pdo, 'ai_awards', 'milestone_value');
        if ($hasAiAwards) {
            $imageStmt = $pdo->prepare("
                SELECT milestone_value, image_path 
                FROM ai_awards 
                WHERE user_id = :uid AND kind = 'lifetime_steps' AND milestone_value IN (100000, 250000, 500000, 750000, 1000000)
            ");
            $imageStmt->execute([':uid' => $userId]);
            while ($row = $imageStmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['image_path'])) {
                    $imagePaths[(int)$row['milestone_value']] = find_award_image($userId, (int)$row['milestone_value']);
                }
            }
        }
    } catch (Throwable $e) {
        // ignore: treat as no pre-existing images
    }
    
    foreach ($thresholds as $threshold) {
        $key = "lifetime_{$threshold}";
        $earned = $totalSteps >= $threshold;
        
        // Get image path from ai_awards table or use fallback
        if (!empty($imagePaths[$threshold])) {
            $imageUrl = $imagePaths[$threshold];
        } else {
            // Try to find an existing award image on disk before falling back to the generic no-photo
            $imageUrl = find_award_image($userId, $threshold);
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
    
    // Build query supporting both schemas
    $days = _aw_day_cols($pdo);
    $selectDays = implode(', ', array_map(fn($d)=>"e.$d AS $d", $days));
    $join = _aw_week_join($pdo);
    $starts = $join['starts'];
    $byUser = _aw_user_binding($pdo, $userId);
    if ($byUser['byUserId']) {
        $sql = "SELECT $selectDays, $starts AS starts_on FROM entries e JOIN weeks w ON {$join['join']} WHERE e.user_id = :uid ORDER BY starts_on ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
    } else {
        if ($userName === '') return null;
        $sql = "SELECT $selectDays, $starts AS starts_on FROM entries e JOIN weeks w ON {$join['join']} WHERE e.name = :name ORDER BY starts_on ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':name' => $userName]);
    }
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($entries)) {
        return null;
    }
    
    // Build cumulative daily steps
    $dailySteps = [];
    foreach ($entries as $entry) {
        $weekStart = $entry['starts_on'];
        
        // Skip entries without a valid week start date
        if (empty($weekStart)) {
            continue;
        }
        
        // Normalize day keys regardless of schema
        $daySteps = [];
        foreach (['monday'=>'mon','tuesday'=>'tue','wednesday'=>'wed','thursday'=>'thu','friday'=>'fri','saturday'=>'sat'] as $long=>$short) {
            $daySteps[$long] = $entries[0] !== null // silence static analyzers
                ? ($entry[$long] ?? ($entry[$short] ?? 0))
                : 0;
        }
        
        $expanded = expand_week_to_daily_dates($weekStart, $daySteps);
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
 * Find the most recent award image for a user and threshold.
 * Award images are named like: lifetime-steps-100000-20251010.svg or .webp
 * This function considers both .webp and .svg candidates and returns the newest file
 * by modification time so regenerated webp files win over older svg files.
 * 
 * @param int $userId User ID
 * @param int $threshold Step threshold
 * @return string Relative path to image or fallback
 */
function find_award_image(int $userId, int $threshold): string {
    $awardsDir = __DIR__ . '/../../site/assets/awards/' . $userId;
    
    // Check if directory exists
    if (!is_dir($awardsDir)) {
        return 'assets/admin/no-photo.svg';
    }
    
    $patterns = [
        "lifetime-steps-{$threshold}-*.webp",
        "lifetime-steps-{$threshold}-*.svg"
    ];
    
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
