<?php
/**
 * Simple test script for lifetime award generation
 * Run from command line: php test_lifetime_awards.php
 */

require_once __DIR__ . '/vendor/autoload.php';
\App\Core\Env::bootstrap(__DIR__);

require_once __DIR__ . '/api/lib/ai_images.php';
require_once __DIR__ . '/api/lib/settings.php';

echo "Testing Lifetime Award Image Generation\n";
echo "========================================\n\n";

// Test user with interests
$testUser = [
    'id' => 9999,
    'name' => 'Test Walker',
    'interests' => 'Balloon Art, Dogs, Faith'
];

echo "User interests: {$testUser['interests']}\n";
echo "Note: Each generation randomly picks ONE interest from the list\n\n";

// Test multiple generations of the same milestone to see random selection
echo "Testing random interest selection (generating same milestone 3 times):\n";
for ($i = 1; $i <= 3; $i++) {
    echo "\nGeneration #{$i} for 100,000 steps:\n";
    
    $result = ai_image_generate([
        'user_id' => $testUser['id'],
        'user_name' => $testUser['name'],
        'user' => $testUser,
        'award_kind' => 'lifetime_steps',
        'milestone_value' => 100000,
        'style' => 'badge',
        'force' => true
    ]);
    
    if ($result['ok'] ?? false) {
        echo "  ✓ Success: {$result['path']}\n";
        if (isset($result['meta']['prompt'])) {
            // Extract which interest was selected
            $prompt = $result['meta']['prompt'];
            if (preg_match('/interest: ([^.]+)\./', $prompt, $matches)) {
                echo "  Selected interest: {$matches[1]}\n";
            }
        }
    } else {
        echo "  ✗ Failed: " . ($result['error'] ?? 'unknown error') . "\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n\n";

// Test different milestones
$milestones = [200000, 500000];

foreach ($milestones as $milestone) {
    echo "Testing milestone: " . number_format($milestone) . " steps\n";
    
    $result = ai_image_generate([
        'user_id' => $testUser['id'],
        'user_name' => $testUser['name'],
        'user' => $testUser,
        'award_kind' => 'lifetime_steps',
        'milestone_value' => $milestone,
        'style' => 'badge',
        'force' => true
    ]);
    
    if ($result['ok'] ?? false) {
        echo "  ✓ Success: {$result['path']}\n";
        if (isset($result['meta']['prompt'])) {
            // Extract which interest was selected
            $prompt = $result['meta']['prompt'];
            if (preg_match('/interest: ([^.]+)\./', $prompt, $matches)) {
                echo "  Selected interest: {$matches[1]}\n";
            }
        }
    } else {
        echo "  ✗ Failed: " . ($result['error'] ?? 'unknown error') . "\n";
    }
    echo "\n";
}

// Test user without interests
$testUser2 = [
    'id' => 9998,
    'name' => 'Jane Doe',
    'interests' => null
];

echo "Testing user without interests (should use fallback theme)\n";
$result = ai_image_generate([
    'user_id' => $testUser2['id'],
    'user_name' => $testUser2['name'],
    'user' => $testUser2,
    'award_kind' => 'lifetime_steps',
    'milestone_value' => 150000,
    'style' => 'badge',
    'force' => true
]);

if ($result['ok'] ?? false) {
    echo "  ✓ Success: {$result['path']}\n";
} else {
    echo "  ✗ Failed: " . ($result['error'] ?? 'unknown error') . "\n";
}

echo "\n========================================\n";
echo "Test complete!\n";
echo "Check site/assets/awards/ for generated images\n";
