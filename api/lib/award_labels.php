<?php
declare(strict_types=1);

/**
 * Maps award kind + milestone to a friendly label.
 * - Example: lifetime_steps 100000 => "100k Club"
 * - Fallback: ucfirst words for unknown kinds.
 */
function award_label(string $kind, int $milestone): string {
  $k = strtolower(trim($kind));
  if ($k === 'lifetime_steps') {
    // Common lifetime step milestones
    $map = [
      100000 => '100k Club',
      250000 => 'Quarter Million',
      500000 => 'Half Million',
      1000000 => 'Million Steps',
    ];
    if (isset($map[$milestone])) return $map[$milestone];
    // Generic for other values
    if ($milestone >= 1000) {
      $knum = number_format((int)round($milestone/1000)) . 'k';
      return $knum . ' Steps';
    }
    return number_format($milestone) . ' Steps';
  }
  if ($k === 'attendance_weeks') {
    $map = [
      25 => '25 Weeks Club',
      50 => '50 Weeks Club',
      100 => 'Century Weeks Club',
    ];
    if (isset($map[$milestone])) return $map[$milestone];
    return number_format($milestone) . ' Weeks';
  }
  // Default: title-cased kind
  $base = ucwords(str_replace('_', ' ', $k));
  return trim($base);
}

