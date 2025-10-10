<?php
declare(strict_types=1);

require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/settings.php';

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
    
    // Get user name from user ID
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = :uid");
    $userStmt->execute([':uid' => $userId]);
    $userName = $userStmt->fetchColumn();
    
    if (!$userName) {
        return []; // User not found
    }
    
    // Get user's total steps
    $totalStmt = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(monday,0) + COALESCE(tuesday,0) + COALESCE(wednesday,0) + 
                            COALESCE(thursday,0) + COALESCE(friday,0) + COALESCE(saturday,0)), 0) AS total
        FROM entries
        WHERE name = :name
    ");
    $totalStmt->execute([':name' => $userName]);
    $totalSteps = (int)$totalStmt->fetchColumn();
    
    // Get image paths from ai_awards table
    $imageStmt = $pdo->prepare("
        SELECT milestone_value, image_path 
        FROM ai_awards 
        WHERE user_id = :uid AND kind = 'lifetime_steps' AND milestone_value IN (100000, 250000, 500000, 750000, 1000000)
    ");
    $imageStmt->execute([':uid' => $userId]);
    $imagePaths = [];
    while ($row = $imageStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['image_path'])) {
            // Prefer the most recent award file on disk for this milestone (handles .webp vs .svg)
            // Use find_award_image() to locate the newest matching file in the awards directory.
            $imagePaths[(int)$row['milestone_value']] = find_award_image($userId, (int)$row['milestone_value']);
        }
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
    // Get user name
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = :uid");
    $userStmt->execute([':uid' => $userId]);
    $userName = $userStmt->fetchColumn();
    
    if (!$userName) {
        return null;
    }
    
    // Get all entries for user with week start dates, ordered chronologically
    $stmt = $pdo->prepare("
        SELECT e.monday, e.tuesday, e.wednesday, e.thursday, e.friday, e.saturday, w.starts_on
        FROM entries e
        JOIN weeks w ON e.week = w.week
        WHERE e.name = :name
        ORDER BY w.starts_on ASC
    ");
    $stmt->execute([':name' => $userName]);
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
        
        $daySteps = [
            'monday' => $entry['monday'],
            'tuesday' => $entry['tuesday'],
            'wednesday' => $entry['wednesday'],
            'thursday' => $entry['thursday'],
            'friday' => $entry['friday'],
            'saturday' => $entry['saturday']
        ];
        
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
