<?php
declare(strict_types=1);

// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;
$pdo = DB::pdo();

function api_error(int $code, string $msg) {
  if (!empty($_POST['redirect'])) {
    header('Location: ../admin/photos.php?err=' . urlencode($msg));
    exit;
  }
  http_response_code($code);
  exit($msg);
}

require_once __DIR__ . '/../app/Security/Csrf.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!\App\Security\Csrf::validate((string)$csrf)) { api_error(403, 'invalid_csrf'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error(400, 'bad_method'); }

$id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
if ($id <= 0 || !isset($_FILES['photo'])) { api_error(400, 'bad_input'); }

$f = $_FILES['photo'];
if ($f['error'] !== UPLOAD_ERR_OK) { api_error(400, 'upload_error'); }
if ($f['size'] > 4 * 1024 * 1024) { api_error(413, 'too_big'); }
if (!is_uploaded_file($f['tmp_name'])) { api_error(400, 'bad_input'); }

$mime = mime_content_type($f['tmp_name']);
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { api_error(400, 'bad_type'); }
$ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');

$siteBase = dirname(__DIR__) . '/site';
$assetsBase = $siteBase . '/assets';
$dir = $assetsBase . '/users/' . $id;
if (!is_dir($dir) && !mkdir($dir, 0755, true)) { api_error(500, 'mkdir_fail'); }

// remove any existing selfie.* to avoid stale extension mismatch
foreach (glob($dir . '/selfie.*') as $old) { if (is_file($old)) @unlink($old); }

$dest = $dir . '/selfie.' . $ext;

$img = @imagecreatefromstring(file_get_contents($f['tmp_name']));
if (!$img) { api_error(400, 'decode_fail'); }

// normalize to max 1024px on longest side
$w = imagesx($img); $h = imagesy($img);
$scale = min(1024 / max($w, $h), 1.0);
$nw = (int)floor($w * $scale); $nh = (int)floor($h * $scale);
$can = imagecreatetruecolor($nw, $nh);

// preserve transparency for PNG/WebP
if (in_array($ext, ['png','webp'], true)) {
  imagealphablending($can, false);
  imagesavealpha($can, true);
  $transparent = imagecolorallocatealpha($can, 0, 0, 0, 127);
  imagefilledrectangle($can, 0, 0, $nw, $nh, $transparent);
}

imagecopyresampled($can, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

$ok = false;
if ($ext === 'png') {
  $ok = imagepng($can, $dest);
} elseif ($ext === 'webp') {
  // quality 85
  $ok = function_exists('imagewebp') ? imagewebp($can, $dest, 85) : false;
} else {
  $ok = imagejpeg($can, $dest, 85);
}

imagedestroy($img);
imagedestroy($can);

if (!$ok) { api_error(500, 'save_fail'); }

// store relative path under site/assets
$rel = 'assets/users/' . $id . '/selfie.' . $ext;
$st = $pdo->prepare('UPDATE users SET photo_path = ?, photo_consent = 1 WHERE id = ?');
$st->execute([$rel, $id]);

if (!empty($_POST['redirect'])) {
  header('Location: ../admin/photos.php?ok=1');
  exit;
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode(['ok' => true]);
