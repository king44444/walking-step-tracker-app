<?php
declare(strict_types=1);

require_once __DIR__ . '/dates.php';

/**
 * Get lifetime awards for a user with computed earned dates.
 * Uses cache table user_awards_cache for performance (write-through).
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return array List of award objects with earned status and dates
 */
function get_lifetime_awards(PDO $pdo, int $userId): array {
    $thresholds = [100000, 250000, 500000, 750000, 1000000];
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
    
    foreach ($thresholds as $threshold) {
        $key = "lifetime_{$threshold}";
        $earned = $totalSteps >= $threshold;
        
        // Find the most recent award image for this threshold
        $imageUrl = find_award_image($userId, $threshold);
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
 * Award images are named like: lifetime-steps-100000-20251010.svg
 * 
 * @param int $userId User ID
 * @param int $threshold Step threshold
 * @return string Relative path to image or fallback
 */
function find_award_image(int $userId, int $threshold): string {
    $awardsDir = __DIR__ . '/../../site/assets/awards/' . $userId;
    $pattern = "lifetime-steps-{$threshold}-*.svg";
    
    // Check if directory exists
    if (!is_dir($awardsDir)) {
        return 'assets/admin/no-photo.svg';
    }
    
    // Find all matching files
    $files = glob($awardsDir . '/' . $pattern);
    
    if (empty($files)) {
        // Try webp format
        $pattern = "lifetime-steps-{$threshold}-*.webp";
        $files = glob($awardsDir . '/' . $pattern);
    }
    
    if (empty($files)) {
        return 'assets/admin/no-photo.svg';
    }
    
    // Sort by filename (date suffix) descending to get most recent
    rsort($files);
    
    // Return relative path from site directory
    $filename = basename($files[0]);
    return "assets/awards/{$userId}/{$filename}";
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
