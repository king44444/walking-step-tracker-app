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
  settings_seed_defaults($pdo);
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
 * Generate or reuse an award image.
 *
 * Input (opts):
 * - user_id (int) Required: used for storage path
 * - user_name (string) Required: used in prompt/text
 * - award_kind (string) Required: e.g., lifetime_steps
 * - milestone_value (int) Required
 * - style (string) Optional: badge|medal|ribbon (default badge)
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
  $kind = trim((string)($opts['award_kind'] ?? ''));
  $milestone = (int)($opts['milestone_value'] ?? 0);
  $style = (string)($opts['style'] ?? 'badge');
  $force = (bool)($opts['force'] ?? false);

  if (!ai_image_can_generate()) {
    $ai = (string)setting_get('ai.enabled', '1');
    $aw = (string)setting_get('ai.award.enabled', '1');
    $reason = ($ai !== '1') ? 'ai.disabled' : (($aw !== '1') ? 'award.disabled' : 'not_configured');
    ai_image_log_event($uid, $userName, $kind, $milestone, 'skipped', $reason, 'fallback', null, null, null, null);
    return ['ok'=>true, 'skipped'=>true, 'reason'=>$reason];
  }

  if ($uid <= 0 || $userName === '' || $kind === '' || $milestone <= 0) {
    return ['ok'=>false, 'error'=>'bad_input'];
  }

  // 24h idempotency: reuse the latest recent file if present unless force
  if (!$force) {
    $recent = ai_image_recent_existing($uid, $kind, $milestone, 24*3600);
    if ($recent !== null) {
      $meta = [
        'prompt' => null,
        'provider' => 'reuse',
        'model' => null,
        'cost_usd' => null,
      ];
      ai_image_log_event($uid, $userName, $kind, $milestone, 'ok', 'reused_recent', 'reuse', null, null, $recent['abs'], $recent['rel']);
      return ['ok'=>true, 'path'=>$recent['url'], 'meta'=>$meta];
    }
  }

  $label = award_label($kind, $milestone);
  $prompt = build_award_prompt($userName, $label, $milestone, $style);
  $provider = strtolower((string)setting_get('ai.image.provider', 'local'));
  $model = (string)setting_get('ai.image.model', '');

  $date = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd');
  $safeKind = ai_image_slug($kind);
  $fileBase = $safeKind . '-' . $milestone . '-' . $date;
  $dirAbs = dirname(__DIR__, 2) . '/site/assets/awards/' . $uid;
  $dirRel = 'assets/awards/' . $uid;
  if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }

  // Try provider if configured (currently stubbed for future expansion)
  $meta = ['prompt'=>$prompt, 'provider'=>$provider, 'model'=>$model ?: null, 'cost_usd'=>null];

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
  $svg = ai_image_svg_badge($userName, $label, $milestone, $style);
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

  $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
      'HTTP-Referer: https://mikebking.com',
      'X-Title: King Walk Week',
    ],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => max(5, min($timeout, 20)),
  ]);
  $res = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($res === false || $http >= 400) {
    return ['ok'=>false, 'error'=>'http_' . $http, 'err'=>$err ?: ''];
  }
  $json = json_decode($res, true);
  if (!is_array($json) || !isset($json['choices'][0]['message'])) {
    return ['ok'=>false, 'error'=>'bad_response'];
  }
  $message = $json['choices'][0]['message'];
  if (!isset($message['images'][0]['image_url']['url'])) {
    return ['ok'=>false, 'error'=>'no_images'];
  }
  $dataUrl = (string)$message['images'][0]['image_url']['url'];
  $parsed = ai_image_parse_data_url($dataUrl);
  if ($parsed === null) return ['ok'=>false, 'error'=>'decode_failed'];
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

function build_award_prompt(string $userName, string $awardLabel, int $milestone, string $style): string {
  $style = in_array($style, ['badge','medal','ribbon'], true) ? $style : 'badge';
  return sprintf(
    'Create a flat, minimalist %s icon for %s achieving %s (%s). Use a dark blue background, crisp edges, and readable text. No faces. Square 512x512.',
    $style,
    $userName,
    $awardLabel,
    number_format($milestone)
  );
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

function ai_image_svg_badge(string $userName, string $label, int $milestone, string $style): string {
  $title = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
  $user = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
  $miles = number_format($milestone);
  $sub = $user !== '' ? $user : 'Walk Week';
  // Simple, clean SVG with dark blue bg and white text
  return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#0b1020"/>
      <stop offset="100%" stop-color="#111936"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" fill="url(#g)"/>
  <circle cx="256" cy="180" r="100" fill="#1b2a7a" stroke="#2c3a7a" stroke-width="6"/>
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
