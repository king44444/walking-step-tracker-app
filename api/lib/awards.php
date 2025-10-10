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
    
    // Get user's total steps
    $totalStmt = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(mon,0) + COALESCE(tue,0) + COALESCE(wed,0) + 
                            COALESCE(thu,0) + COALESCE(fri,0) + COALESCE(sat,0)), 0) AS total
        FROM entries
        WHERE user_id = :uid
    ");
    $totalStmt->execute([':uid' => $userId]);
    $totalSteps = (int)$totalStmt->fetchColumn();
    
    foreach ($thresholds as $threshold) {
        $key = "lifetime_{$threshold}";
        $earned = $totalSteps >= $threshold;
        
        $award = [
            'key' => $key,
            'threshold' => $threshold,
            'earned' => $earned,
            'awarded_at' => null,
            'image_url' => "/site/assets/awards/{$userId}/lifetime-{$threshold}.svg",
            'thumb_url' => "/site/assets/awards/thumbs/{$userId}/lifetime-{$threshold}.svg",
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
    // Get all entries for user with week start dates, ordered chronologically
    $stmt = $pdo->prepare("
        SELECT e.mon, e.tue, e.wed, e.thu, e.fri, e.sat, w.starts_on
        FROM entries e
        JOIN weeks w ON e.week_id = w.id
        WHERE e.user_id = :uid
        ORDER BY w.starts_on ASC
    ");
    $stmt->execute([':uid' => $userId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($entries)) {
        return null;
    }
    
    // Build cumulative daily steps
    $dailySteps = [];
    foreach ($entries as $entry) {
        $weekStart = $entry['starts_on'];
        $daySteps = [
            'monday' => $entry['mon'],
            'tuesday' => $entry['tue'],
            'wednesday' => $entry['wed'],
            'thursday' => $entry['thu'],
            'friday' => $entry['fri'],
            'saturday' => $entry['sat']
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
 * Format a threshold number into a readable title.
 * 
 * @param int $threshold Step count
 * @return string Formatted title like "100,000 Steps"
 */
function format_threshold(int $threshold): string {
    return number_format($threshold) . ' Steps';
}
