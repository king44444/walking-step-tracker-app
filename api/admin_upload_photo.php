<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/db.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(400); exit('bad_method'); }

$id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
if ($id <= 0 || !isset($_FILES['photo'])) { http_response_code(400); exit('bad_input'); }

$f = $_FILES['photo'];
if ($f['error'] !== UPLOAD_ERR_OK) { http_response_code(400); exit('upload_error'); }
if ($f['size'] > 4 * 1024 * 1024) { http_response_code(413); exit('too_big'); }
if (!is_uploaded_file($f['tmp_name'])) { http_response_code(400); exit('bad_input'); }

$mime = mime_content_type($f['tmp_name']);
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { http_response_code(400); exit('bad_type'); }
$ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');

$siteBase = dirname(__DIR__) . '/site';
$assetsBase = $siteBase . '/assets';
$dir = $assetsBase . '/users/' . $id;
if (!is_dir($dir) && !mkdir($dir, 0755, true)) { http_response_code(500); exit('mkdir_fail'); }

// remove any existing selfie.* to avoid stale extension mismatch
foreach (glob($dir . '/selfie.*') as $old) { if (is_file($old)) @unlink($old); }

$dest = $dir . '/selfie.' . $ext;

$img = @imagecreatefromstring(file_get_contents($f['tmp_name']));
if (!$img) { http_response_code(400); exit('decode_fail'); }

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

if (!$ok) { http_response_code(500); exit('save_fail'); }

// store relative path under site/assets
$rel = 'assets/users/' . $id . '/selfie.' . $ext;
$st = $pdo->prepare('UPDATE users SET photo_path = ?, photo_consent = 1 WHERE id = ?');
$st->execute([$rel, $id]);

http_response_code(200);
echo 'ok';
