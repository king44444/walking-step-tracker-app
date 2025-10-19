<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/award_labels.php';

/**
 * AI Award Image Generation helpers
 * - Respects settings: ai.enabled, ai.award.enabled
 * - Provider abstraction with local fallback (SVG + optional GD WebP)
 * - Writes files to site/assets/awards/{user_id}/
 * - Returns paths suitable for site pages: assets/awards/{user_id}/...
 */

/**
 * Whether image generation is allowed by settings.
 */
function ai_image_can_generate(): bool {
  // Read fresh from DB to avoid stale in-process caches
  $pdo = settings_pdo();
  settings_ensure_schema($pdo);
  $st = $pdo->prepare('SELECT key, value FROM settings WHERE key IN ("ai.enabled","ai.award.enabled")');
  $st->execute();
  $flags = ['ai.enabled'=>'1','ai.award.enabled'=>'1'];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = (string)($r['key'] ?? ''); $v = (string)($r['value'] ?? '');
    if ($k !== '') $flags[$k] = $v;
  }
  if (($flags['ai.enabled'] ?? '1') !== '1') return false;
  if (($flags['ai.award.enabled'] ?? '1') !== '1') return false;
  // Provider presence is optional because we have a local fallback.
  return true;
}

/**
 * Normalize award kind aliases to canonical slugs.
 */
function ai_image_normalize_kind(string $kind): string {
  $k = strtolower(trim($kind));
  if ($k === 'attendance' || $k === 'lifetime_attendance') {
    return 'attendance_days';
  }
  return $k;
}

/**
 * Compute display context (labels, units, banner text) for an award.
 */
function ai_image_unit_context(string $kind, int $milestone): array {
  $normalized = ai_image_normalize_kind($kind);
  $isAttendance = ($normalized === 'attendance_days');

  $sectionLabel = $isAttendance ? 'Lifetime Attendance' : 'Lifetime Steps';
  $unitTitle = $isAttendance ? 'Days' : 'Steps';
  $unitUpper = strtoupper($unitTitle);
  $unitLower = strtolower($unitTitle);
  $milestoneNum = number_format(max(0, $milestone));

  return [
    'kind' => $normalized,
    'isAttendance' => $isAttendance,
    'sectionLabel' => $sectionLabel,
    'unitTitle' => $unitTitle,
    'unitTitleLower' => $unitLower,
    'unitUpper' => $unitUpper,
    'milestoneNum' => $milestoneNum,
    'milestoneText' => trim($milestoneNum . ' ' . $unitTitle),
    'bannerText' => "LIFETIME {$milestoneNum} {$unitUpper}",
  ];
}

/**
 * Generate or reuse an award image.
 *
 * Input (opts):
 * - user_id (int) Required: used for storage path
 * - user_name (string) Required: used in prompt/text
 * - award_kind (string) Required: e.g., lifetime_steps
 * - milestone_value (int) Required
 * - user (array) Optional: enriched user profile used for personalization
 * - force (bool) Optional: when true, bypass 24h reuse
 *
 * Returns:
 * - ['ok'=>true, 'path'=>'assets/awards/{uid}/....webp', 'meta'=>['prompt'=>..., 'provider'=>..., 'model'=>..., 'cost_usd'=>null]]
 * - ['ok'=>true, 'skipped'=>true, 'reason'=>'ai.disabled'|'award.disabled'|'not_configured']
 * - ['ok'=>false, 'error'=>'provider_failed']
 */
function ai_image_generate(array $opts): array {
  $uid = (int)($opts['user_id'] ?? 0);
  $userName = trim((string)($opts['user_name'] ?? ''));
  $kindRaw = trim((string)($opts['award_kind'] ?? ''));
  $milestone = (int)($opts['milestone_value'] ?? 0);
  $force = (bool)($opts['force'] ?? false);

  if ($uid <= 0 || $userName === '' || $kindRaw === '' || $milestone <= 0) {
    return ['ok'=>false, 'error'=>'bad_input'];
  }

  $ctx = ai_image_unit_context($kindRaw, $milestone);
  $kind = $ctx['kind'];
  $sectionLabel = $ctx['sectionLabel'];
  $unitTitle = $ctx['unitTitle'];
  $unitTitleLower = $ctx['unitTitleLower'];
  $unitUpper = $ctx['unitUpper'];
  $milestoneNum = $ctx['milestoneNum'];
  $milestoneText = $ctx['milestoneText'];
  $bannerText = $ctx['bannerText'];

  if (!ai_image_can_generate()) {
    $ai = (string)setting_get('ai.enabled', '1');
    $aw = (string)setting_get('ai.award.enabled', '1');
    $reason = ($ai !== '1') ? 'ai.disabled' : (($aw !== '1') ? 'award.disabled' : 'not_configured');
    ai_image_log_event($uid, $userName, $kind, $milestone, 'skipped', $reason, 'fallback', null, null, null, null);
    return ['ok'=>true, 'skipped'=>true, 'reason'=>$reason];
  }

  $label = award_label($kind, $milestone);

  $templateVars = [
    'userName' => $userName,
    'awardKind' => $kind,
    'awardLabel' => $sectionLabel,
    'sectionLabel' => $sectionLabel,
    'milestone' => $milestoneText,
    'milestoneValue' => $milestone,
    'milestoneNumber' => $milestoneNum,
    'bannerText' => $bannerText,
    'unitTitle' => $unitTitle,
    'unitTitleLower' => $unitTitleLower,
    'unitUpper' => $unitUpper,
    'badgeLabel' => $label,
  ];

  // 24h idempotency: reuse the latest recent file if present unless force
  if (!$force) {
    $recent = ai_image_recent_existing($uid, $kind, $milestone, 24*3600);
    if ($recent !== null) {
      $meta = [
        'prompt' => null,
        'provider' => 'reuse',
        'model' => null,
        'cost_usd' => null,
        'vars' => $templateVars,
      ];
      ai_image_log_event($uid, $userName, $kind, $milestone, 'ok', 'reused_recent', 'reuse', null, null, $recent['abs'], $recent['rel']);
      return ['ok'=>true, 'path'=>$recent['url'], 'meta'=>$meta];
    }
  }

  $userData = $opts['user'] ?? null;
  if (!is_array($userData)) {
    $userData = ['name' => $userName];
  } else {
    if (!isset($userData['name']) || trim((string)$userData['name']) === '') {
      $userData['name'] = $userName;
    }
  }
  $prompt = build_award_prompt($kind, $userData, $label, $milestone, [
    'displayAwardLabel' => $sectionLabel,
    'milestoneText' => $milestoneText,
    'bannerText' => $bannerText,
    'unitTitle' => $unitTitle,
    'unitTitleLower' => $unitTitleLower,
    'unitUpper' => $unitUpper,
  ]);
  
  $provider = strtolower((string)setting_get('ai.image.provider', 'local'));
  $model = (string)setting_get('ai.image.model', '');

  $date = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd');
  $safeKind = ai_image_slug($kind);
  $fileBase = $safeKind . '-' . $milestone . '-' . $date;
  $dirAbs = dirname(__DIR__, 2) . '/site/assets/awards/' . $uid;
  $dirRel = 'assets/awards/' . $uid;
  if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }

  // Try provider if configured (currently stubbed for future expansion)
  $meta = [
    'prompt'=>$prompt,
    'provider'=>$provider,
    'model'=>$model ?: null,
    'cost_usd'=>null,
    'vars'=>$templateVars,
  ];

  try {
    if ($provider !== 'local' && $model !== '' && ai_image_has_provider()) {
      $prov = ai_image_provider_generate($prompt, ['model'=>$model,'timeout'=>18,'aspect_ratio'=>'1:1']);
      if (($prov['ok'] ?? false) === true && isset($prov['image_bytes'])) {
        $bytes = $prov['image_bytes'];
        $mime  = (string)($prov['mime'] ?? 'image/png');
        if (isset($prov['cost_usd'])) $meta['cost_usd'] = (float)$prov['cost_usd'];
        $ext = 'png';
        if (stripos($mime, 'jpeg') !== false) $ext = 'jpg';
        if (stripos($mime, 'webp') !== false) $ext = 'webp';
        // Prefer webp if possible
        if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
          $im = @imagecreatefromstring($bytes);
          if ($im !== false) {
            $outAbs = $dirAbs . '/' . $fileBase . '.webp';
            if (@imagewebp($im, $outAbs, 88)) {
              imagedestroy($im);
              $url = $dirRel . '/' . $fileBase . '.webp';
              ai_image_log_event($uid, $userName, $kind, $milestone, 'ok', 'provider', $provider, $model, $outAbs, $url, $meta['cost_usd']);
              return ['ok'=>true, 'path'=>$url, 'meta'=>$meta];
            }
            imagedestroy($im);
          }
        }
        // Fallback: save original bytes using detected extension
        $outAbs = $dirAbs . '/' . $fileBase . '.' . $ext;
        $ok = @file_put_contents($outAbs, $bytes) !== false;
        if ($ok) {
          $url = $dirRel . '/' . $fileBase . '.' . $ext;
          ai_image_log_event($uid, $userName, $kind, $milestone, 'ok', 'provider', $provider, $model, $outAbs, $url, $meta['cost_usd']);
          return ['ok'=>true, 'path'=>$url, 'meta'=>$meta];
        }
        // fallthrough to local
      } else {
        // provider failed; log and fall back
        ai_image_log_event($uid, $userName, $kind, $milestone, 'error', 'provider_failed', $provider, $model, null, null);
      }
    }
  } catch (Throwable $e) {
    // Never expose provider errors; log only and fall back
    ai_image_log_event($uid, $userName, $kind, $milestone, 'error', 'provider_exception', $provider, $model, null, null);
  }

  // Local fallback: write SVG + optional GD raster (WebP)
  $svg = ai_image_svg_badge($userName, $label, $milestone);
  $svgAbs = $dirAbs . '/' . $fileBase . '.svg';
  $okSvg = @file_put_contents($svgAbs, $svg) !== false;

  $url = $dirRel . '/' . $fileBase . '.svg';
  $outAbs = $svgAbs;

  // If GD is present, create a simple rasterized WebP/PNG badge (text + bg)
  if (function_exists('imagecreatetruecolor') && function_exists('imagewebp')) {
    $imgAbs = $dirAbs . '/' . $fileBase . '.webp';
    if (ai_image_write_gd_badge($imgAbs, $userName, $label)) {
      $url = $dirRel . '/' . $fileBase . '.webp';
      $outAbs = $imgAbs;
    }
  }

  ai_image_log_event($uid, $userName, $kind, $milestone, $okSvg ? 'ok' : 'error', $okSvg ? 'fallback' : 'write_failed', 'fallback', null, $outAbs, $url);
  if (!$okSvg) return ['ok'=>false, 'error'=>'provider_failed'];
  return ['ok'=>true, 'path'=>$url, 'meta'=>$meta];
}

// -------------------- internals --------------------

function ai_image_has_provider(): bool {
  // Provider enabled only when API key and model exist
  try {
    $prov = strtolower((string)setting_get('ai.image.provider', 'local'));
    if ($prov === 'local') return false;
    $key = env('OPENROUTER_API_KEY', '');
    $model = (string)setting_get('ai.image.model', '');
    return ($key !== '' && $model !== '');
  } catch (Throwable $e) { return false; }
}

/**
 * Attempt provider image generation via OpenRouter if available.
 * Returns ['ok'=>true,'image_bytes'=>string] or ['ok'=>false].
 * Currently a stub (no stable image API guaranteed) — returns ok=false.
 */
function ai_image_provider_generate(string $prompt, array $params): array {
  // Uses OpenRouter chat completions with modalities ["image","text"].
  $model = (string)($params['model'] ?? setting_get('ai.image.model', ''));
  $aspect = (string)($params['aspect_ratio'] ?? '1:1');
  $timeout = (int)($params['timeout'] ?? 18);
  $apiKey = env('OPENROUTER_API_KEY', '');
  if ($apiKey === '' || $model === '') return ['ok'=>false, 'error'=>'not_configured'];

  $body = [
    'model' => $model,
    'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ],
    'modalities' => ['image','text'],
    'image_config' => [ 'aspect_ratio' => $aspect ],
  ];

  $headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
    'X-Title: King Walk Week',
  ];
  $referer = setting_get('site.url', env('SITE_URL', ''));
  if ($referer) {
    $headers[] = 'HTTP-Referer: ' . rtrim($referer, '/');
  }

  $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => max(5, min($timeout, 20)),
  ]);
  $res = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  // Debug: persist provider HTTP response and error info for diagnosis
  $debugDir = dirname(__DIR__,2) . '/data/logs/ai';
  if (!is_dir($debugDir)) { @mkdir($debugDir, 0775, true); }
  $resLen = is_string($res) ? strlen($res) : 0;
  $dbgMsg = sprintf("[%s] provider_debug model=%s http=%d err=%s res_len=%d\n", date('c'), $model, $http, $err ?: '', $resLen);
  @file_put_contents($debugDir . '/provider_debug.log', $dbgMsg, FILE_APPEND);
  if (is_string($res) && $resLen > 0) {
    @file_put_contents($debugDir . '/provider_debug.log', substr($res, 0, 20000) . "\n\n", FILE_APPEND);
  }

  if ($res === false || $http >= 400) {
    return ['ok'=>false, 'error'=>'http_' . $http, 'err'=>$err ?: ''];
  }
  $json = json_decode($res, true);
  if (!is_array($json) || !isset($json['choices'][0]['message'])) {
    @file_put_contents($debugDir . '/provider_debug.log', date('c') . " bad_response: " . substr((string)$res,0,2000) . "\n\n", FILE_APPEND);
    return ['ok'=>false, 'error'=>'bad_response'];
  }
  $message = $json['choices'][0]['message'];

  // Support multiple OpenRouter response shapes:
  // 1) structured images array: message.images[0].image_url.url
  // 2) textual content containing a data: URL or a JSON blob with image_data_url
  $dataUrl = null;
  if (isset($message['images'][0]['image_url']['url'])) {
    $dataUrl = (string)$message['images'][0]['image_url']['url'];
  } elseif (isset($message['content']) && is_string($message['content'])) {
    $content = $message['content'];
    @file_put_contents($debugDir . '/provider_debug.log', date('c') . " message_content: " . substr($content,0,2000) . "\n\n", FILE_APPEND);

    // Try to find a data: URL inline in the content
    if (preg_match('~data:[^\\s\\\'"\\)]+~', $content, $m)) {
      $dataUrl = $m[0];
    } else {
      // Try to decode JSON embedded in content (strip code fences/backticks)
      $trimmed = trim($content, "` \n\r\t");
      $maybe = json_decode($trimmed, true);
      if (is_array($maybe) && isset($maybe['image_data_url'])) {
        $dataUrl = (string)$maybe['image_data_url'];
      }
    }
  }

  if ($dataUrl === null) {
    @file_put_contents($debugDir . '/provider_debug.log', date('c') . " no_images: " . substr((string)$res,0,2000) . "\n\n", FILE_APPEND);
    return ['ok'=>false, 'error'=>'no_images'];
  }

  $parsed = ai_image_parse_data_url($dataUrl);
  if ($parsed === null) {
    @file_put_contents($debugDir . '/provider_debug.log', date('c') . " decode_failed: " . substr((string)$dataUrl,0,500) . "\n\n", FILE_APPEND);
    return ['ok'=>false, 'error'=>'decode_failed'];
  }
  $out = [ 'ok'=>true, 'image_bytes'=>$parsed['bytes'], 'mime'=>$parsed['mime'] ];
  // Optional cost metadata if present
  if (isset($json['usage']['total_cost'])) $out['cost_usd'] = (float)$json['usage']['total_cost'];
  return $out;
}

function ai_image_parse_data_url(string $url): ?array {
  if (strpos($url, 'data:') !== 0) return null;
  // Format: data:<mime>;base64,<base64>
  $semi = strpos($url, ';');
  $comma = strpos($url, ',');
  if ($semi === false || $comma === false) return null;
  $mime = substr($url, 5, $semi - 5);
  $b64 = substr($url, $comma + 1);
  $bytes = base64_decode($b64, true);
  if ($bytes === false) return null;
  return ['mime'=>$mime, 'bytes'=>$bytes];
}

function ai_image_slug(string $s): string {
  $s = strtolower($s);
  $s = preg_replace('~[^a-z0-9]+~', '-', $s);
  return trim($s, '-');
}

/**
 * Build enhanced prompt for AI award generation with optional kind-specific wording.
 * Randomly selects ONE interest from comma-separated list.
 */
function build_award_prompt(string $kind, array $user, string $awardLabel, int $milestone, array $extras = []): string {
  $ctx = ai_image_unit_context($kind, $milestone);
  $isAttendance = $ctx['isAttendance'];

  $displayAwardLabel = $extras['displayAwardLabel'] ?? $ctx['sectionLabel'];
  $milestoneText = $extras['milestoneText'] ?? ($ctx['milestoneNum'] . ' ' . $ctx['unitTitle']);
  $bannerText = $extras['bannerText'] ?? $ctx['bannerText'];
  $unitLabel = $extras['unitTitle'] ?? $ctx['unitTitle'];
  $unitLabelLower = $extras['unitTitleLower'] ?? strtolower($unitLabel);
  $unitLabelUpper = $extras['unitUpper'] ?? strtoupper($unitLabel);
  $milestoneFormatted = number_format($milestone);

  $userName = (string)($user['name'] ?? 'Walker');
  $interests = trim((string)($user['interests'] ?? ''));

  // Parse interests and randomly select one
  if ($interests !== '') {
    $interestList = array_map('trim', explode(',', $interests));
    $interestList = array_filter($interestList); // Remove empty entries
    if (count($interestList) > 0) {
      $interestText = $interestList[array_rand($interestList)];
    } else {
      $interestText = 'modern geometric design with symbols of perseverance';
    }
  } else {
    $interestText = 'modern geometric design with symbols of perseverance';
  }

  // Milestone-specific style hints
  $styleHint = match(true) {
    $milestone >= 500000 => 'cosmic energy, nebula background, mythic feel',
    $milestone >= 200000 => 'platinum glow, aurora sky, elegant symmetry',
    default => 'gold medal motif, radiant gradients, joyful sparks'
  };

  $achievementPhrase = $isAttendance
    ? sprintf('%s has reported %s lifetime attendance days (%s).', $userName, $milestoneFormatted, $displayAwardLabel)
    : sprintf('%s has reached %s lifetime steps (%s).', $userName, $milestoneFormatted, $displayAwardLabel);

  $achievementDescriptor = $isAttendance ? 'lifetime attendance' : 'lifetime walking';

  $promptsJson = setting_get('ai.image.prompts.lifetime', '');
  if ($promptsJson) {
    try {
      $prompts = json_decode($promptsJson, true);
      if (is_array($prompts)) {
        $enabledPrompts = array_filter($prompts, fn($p) => ($p['enabled'] ?? true));
        if (!empty($enabledPrompts)) {
          $selectedPrompt = $enabledPrompts[array_rand($enabledPrompts)];
          $text = $selectedPrompt['text'] ?? '';
          if ($text) {
            // Replace placeholders
            $text = str_replace('{userName}', $userName, $text);
            $text = str_replace('{milestone}', number_format($milestone), $text);
            $text = str_replace('{milestoneText}', $milestoneText, $text);
            $text = str_replace('{bannerText}', $bannerText, $text);
            $text = str_replace('{awardLabel}', $displayAwardLabel, $text);
            $text = str_replace('{awardBadgeLabel}', $awardLabel, $text);
            $text = str_replace('{milestoneLabel}', $awardLabel, $text);
            $text = str_replace('{interestText}', $interestText, $text);
            $text = str_replace('{styleHint}', $styleHint, $text);
            $text = str_replace('{unitLabel}', $unitLabel, $text);
            $text = str_replace('{unitLabelLower}', $unitLabelLower, $text);
            $text = str_replace('{unitLabelUpper}', $unitLabelUpper, $text);
            return $text;
          }
        }
      }
    } catch (Throwable $e) {
      // Fall through to fallback
    }
  }

  // Fallback to original hardcoded prompt
  $lines = [
    "Design a breathtaking digital award image celebrating a {$achievementDescriptor} achievement.",
    $achievementPhrase,
    "Create a highly detailed, imaginative emblem that visually represents their personality and interest: {$interestText}.",
    "Use luminous color, depth, and storytelling elements.",
    "Capture the feeling of epic accomplishment, motion, and personal triumph.",
    "Composition: centered emblem, cinematic lighting, subtle text '{$bannerText}'.",
    "No faces or photo realism. Square 1024x1024 ratio.",
    "Style: digital painting + vector hybrid, vivid and collectible. Style hint: {$styleHint}.",
  ];
  return implode("\n", $lines);
}

/**
 * Reuse recent image within window for same user/kind/milestone.
 * Returns array with ['abs'=>..., 'rel'=>..., 'url'=>...] or null.
 */
function ai_image_recent_existing(int $uid, string $kind, int $milestone, int $windowSec): ?array {
  $dirAbs = dirname(__DIR__, 2) . '/site/assets/awards/' . $uid;
  $dirRel = 'assets/awards/' . $uid;
  if (!is_dir($dirAbs)) return null;
  $safeKind = ai_image_slug($kind);
  $files = @scandir($dirAbs) ?: [];
  $latest = null; $latestMTime = 0; $latestUrl = null; $latestRel = null;
  foreach ($files as $f) {
    if ($f === '.' || $f === '..') continue;
    if (preg_match('~^' . preg_quote($safeKind, '~') . '-' . preg_quote((string)$milestone, '~') . '-\d{8}\.(?:webp|svg|png|jpg)$~i', $f)) {
      $path = $dirAbs . '/' . $f;
      $mt = @filemtime($path) ?: 0;
      if ($mt > $latestMTime) { $latestMTime = $mt; $latest = $path; $latestRel = 'awards/' . $uid . '/' . $f; $latestUrl = $dirRel . '/' . $f; }
    }
  }
  if ($latest && (time() - $latestMTime) < $windowSec) {
    return ['abs'=>$latest, 'rel'=>$latestRel, 'url'=>$latestUrl];
  }
  return null;
}

function ai_image_svg_badge(string $userName, string $label, int $milestone): string {
  $title = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
  $user = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
  $miles = number_format($milestone);
  $sub = $user !== '' ? $user : 'Walk Week';
  
  // Milestone-specific color schemes for lifetime awards
  if ($milestone >= 500000) {
    // Cosmic: deep purple to blue
    $gradStart = '#1a0a3e';
    $gradEnd = '#2a1a5e';
    $circleColor = '#4a2a9e';
    $circleStroke = '#6a4abe';
  } elseif ($milestone >= 200000) {
    // Platinum: silver-blue to teal
    $gradStart = '#0a1a2a';
    $gradEnd = '#1a2a4a';
    $circleColor = '#2a4a7a';
    $circleStroke = '#4a6a9a';
  } elseif ($milestone >= 100000) {
    // Gold: warm gold to blue
    $gradStart = '#1a1408';
    $gradEnd = '#2a2418';
    $circleColor = '#4a4428';
    $circleStroke = '#6a6448';
  } else {
    // Default: dark blue
    $gradStart = '#0b1020';
    $gradEnd = '#111936';
    $circleColor = '#1b2a7a';
    $circleStroke = '#2c3a7a';
  }
  
  return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$gradStart}"/>
      <stop offset="100%" stop-color="{$gradEnd}"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" fill="url(#g)"/>
  <circle cx="256" cy="180" r="100" fill="{$circleColor}" stroke="{$circleStroke}" stroke-width="6"/>
  <text x="256" y="180" font-family="system-ui, -apple-system, Segoe UI, Roboto, Arial" font-size="28" text-anchor="middle" fill="#e6ecff">{$title}</text>
  <text x="256" y="220" font-family="system-ui, -apple-system, Segoe UI, Roboto, Arial" font-size="16" text-anchor="middle" fill="#9aa9e6">{$miles}</text>
  <rect x="96" y="300" width="320" height="60" rx="12" fill="#17214f" stroke="#2c3a7a" stroke-width="4"/>
  <text x="256" y="337" font-family="system-ui, -apple-system, Segoe UI, Roboto, Arial" font-size="20" font-weight="700" text-anchor="middle" fill="#e6ecff">{$sub}</text>
  <text x="256" y="365" font-family="system-ui, -apple-system, Segoe UI, Roboto, Arial" font-size="12" text-anchor="middle" fill="#9aa9e6">Walk Week Award</text>
</svg>
SVG;
}

/**
 * Simple GD render to WebP: 512x512, dark bg, center text.
 */
function ai_image_write_gd_badge(string $outAbs, string $userName, string $label): bool {
  try {
    $w = 512; $h = 512; $img = imagecreatetruecolor($w, $h);
    if (!$img) return false;
    // Background
    $bg = imagecolorallocate($img, 11, 16, 32); // #0b1020
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    // Accent circle
    $accent = imagecolorallocate($img, 27, 42, 122); // #1b2a7a
    imagefilledellipse($img, (int)($w/2), 180, 200, 200, $accent);
    // Text colors
    $white = imagecolorallocate($img, 230, 236, 255);
    $muted = imagecolorallocate($img, 154, 169, 230);

    // Draw text (use built-in font)
    $title = $label;
    $sub = $userName !== '' ? $userName : 'Walk Week';
    $f = 5; // built-in font size
    $titleW = imagefontwidth($f) * strlen($title);
    $xTitle = (int)(($w - $titleW) / 2);
    imagestring($img, $f, max(4, $xTitle), 170, $title, $white);
    $subW = imagefontwidth(4) * strlen($sub);
    $xSub = (int)(($w - $subW) / 2);
    imagestring($img, 4, max(4, $xSub), 340, $sub, $muted);

    $ok = imagewebp($img, $outAbs, 88);
    imagedestroy($img);
    return (bool)$ok;
  } catch (Throwable $e) {
    return false;
  }
}

function ai_image_log_event(int $uid, string $userName, string $kind, int $milestone, string $status, string $reason, ?string $provider, ?string $model, ?string $absPath, ?string $urlPath, $cost=null): void {
  $dir = dirname(__DIR__, 2) . '/data/logs/ai';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $log = $dir . '/award_images.log';
  $ts = date('c');
  $prov = $provider ?: 'fallback';
  $msg = sprintf('[%s] user=%d:%s kind=%s milestone=%d provider=%s path=%s cost=%s status=%s reason=%s',
    $ts, $uid, $userName, $kind, $milestone, $prov, ($urlPath ?? ''), ($cost===null?'null':(string)$cost), $status, $reason);
  @file_put_contents($log, $msg . "\n", FILE_APPEND);
}
