<?php
declare(strict_types=1);

// Reusable loader for awards settings JSON
// Source of truth priority:
// 1) DB setting key 'awards.settings' (JSON string)
// 2) Built-in defaults below

require_once __DIR__ . '/settings.php';

function awards_settings_load(): array {
  // Try DB first
  try {
    $raw = setting_get('awards.settings', '');
    if (is_string($raw) && trim($raw) !== '') {
      $j = json_decode($raw, true);
      if (is_array($j)) {
        // Ensure required keys exist; if not, extend
        if (!isset($j['milestone_colors']) || !is_array($j['milestone_colors'])) {
          $j['milestone_colors'] = [];
        }
        if (!isset($j['chip_text_color']) || !is_string($j['chip_text_color'])) {
          $j['chip_text_color'] = '#FFFFFF';
        }
        if (!isset($j['chip_border_opacity']) || !is_numeric($j['chip_border_opacity'])) {
          $j['chip_border_opacity'] = 0.2;
        }
        return $j;
      }
    }
  } catch (\Throwable $e) {
    // fall through to defaults
  }

  // Defaults (muted palette suggestions for milestone labels)
  return [
    'milestone_colors' => [
      '1k'   => '#5B9DFF',
      '2.5k' => '#4DD0E1',
      '5k'   => '#64B5F6',
      '10k'  => '#81C784',
      '15k'  => '#AED581',
      '20k'  => '#FFB74D',
      '25k'  => '#FFD54F',
      '30k'  => '#BA68C8',
      '35k'  => '#F06292',
      '40k'  => '#9575CD',
      '50k'  => '#4DB6AC',
      '60k'  => '#90A4AE',
    ],
    'chip_text_color' => '#FFFFFF',
    'chip_border_opacity' => 0.2,
  ];
}

