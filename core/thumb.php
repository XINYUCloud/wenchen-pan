<?php
/** 缩略图：GD 处理图片，可选 ffmpeg 截取视频首帧；无 GD 时静默跳过 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_THUMB')) { return; }
define('WC_CORE_THUMB', 1);

function wc_thumb_max_size()
{
    return array(max(48, wc_setting_int('thumb_w', 240)), max(48, wc_setting_int('thumb_h', 240)));
}

function wc_thumb_file($fileId)
{
    $f = wc_db_row('SELECT uid,id FROM wc_file WHERE id=?', array((int)$fileId));
    if (!$f) { return ''; }
    return wc_thumb_path('u/' . (int)$f['uid'] . '/' . (int)$f['id'] . '.jpg');
}

function wc_thumb_from_image($abs, $f)
{
    if (!function_exists('imagecreatetruecolor')) { return false; }
    $ext = strtolower((string)$f['ext']);
    $src = null;
    switch ($ext) {
        case 'jpg': case 'jpeg': $src = @imagecreatefromjpeg($abs); break;
        case 'png':  $src = @imagecreatefrompng($abs); break;
        case 'gif':  $src = @imagecreatefromgif($abs); break;
        case 'webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : null; break;
        case 'bmp':  $src = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($abs) : null; break;
        case 'ico':  $src = function_exists('imagecreatefromico') ? @imagecreatefromico($abs) : null; break;
        case 'tif': case 'tiff': $src = function_exists('imagecreatefromtiff') ? @imagecreatefromtiff($abs) : null; break;
        default: return false;
    }
    if (!$src) { return false; }
    $w = imagesx($src); $h = imagesy($src);
    if ($w < 1 || $h < 1) { imagedestroy($src); return false; }
    $limit = (int)wc_setting_int('thumb_side_max', 4000);
    if ($w > $limit || $h > $limit) { imagedestroy($src); return false; }
    list($tw, $th) = wc_thumb_max_size();
    $scale = min($tw / $w, $th / $h);
    if ($scale > 1) { $scale = 1; }
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $out = wc_thumb_path('u/' . (int)$f['uid']);
    wc_dir_ensure($out);
    $file = $out . '/' . (int)$f['id'] . '.jpg';
    $ok = @imagejpeg($dst, $file, (int)wc_setting_int('thumb_quality', 82));
    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

function wc_thumb_from_video($abs, $f)
{
    $bin = trim(wc_setting('ffmpeg_path', ''));
    if ($bin === '' || !wc_is_executable_safe($bin)) { return false; }
    $out = wc_thumb_path('u/' . (int)$f['uid']);
    wc_dir_ensure($out);
    $file = $out . '/' . (int)$f['id'] . '.jpg';
    $cmd = escapeshellarg($bin) . ' -nostdin -loglevel error -y -ss ' . (int)wc_setting_int('ffmpeg_seek', 1)
        . ' -i ' . escapeshellarg($abs) . ' -frames:v 1 -vf "scale=' . wc_thumb_max_size()[0] . ':-1" '
        . escapeshellarg($file) . ' 2>&1';
    @exec($cmd, $o, $rc);
    if ($rc === 0 && is_file($file) && filesize($file) > 0) { return true; }
    if (is_file($file)) { @unlink($file); }
    return false;
}

function wc_is_executable_safe($bin)
{
    if (!function_exists('exec')) { return false; }
    return $bin !== '' && (strpos($bin, "\0") === false);
}

/** 文本/文档类文件生成占位图不需要，前台用类型图标即可 */
function wc_thumb_exists($f)
{
    if (empty($f['thumb'])) { return false; }
    return is_file(wc_thumb_path($f['thumb']));
}

/**
 * 图片参数直链：按 w/h/q/crop 生成并缓存缩放图（GD 可用时）
 * @return array(abs, mime) 失败时返回原图
 */
function wc_image_variant($abs, $f, $w = 0, $h = 0, $q = 0, $crop = 0)
{
    $orig = array($abs, wc_mime_of($f['ext']));
    if (!function_exists('imagecreatetruecolor')) { return $orig; }
    if (!in_array($f['ext'], array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'), true)) { return $orig; }
    $w = max(0, min(4000, (int)$w));
    $h = max(0, min(4000, (int)$h));
    $q = max(1, min(95, (int)$q ?: 82));
    if ($w === 0 && $h === 0) { return $orig; }
    $key = 'u/' . (int)$f['uid'] . '/' . (int)$f['id'] . '_w' . $w . 'h' . $h . 'q' . $q . 'c' . (int)$crop . '.jpg';
    $out = wc_thumb_path($key);
    if (is_file($out) && filemtime($out) >= filemtime($abs)) { return array($out, 'image/jpeg'); }
    $src = null;
    switch ($f['ext']) {
        case 'png': $src = @imagecreatefrompng($abs); break;
        case 'gif': $src = @imagecreatefromgif($abs); break;
        case 'webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : null; break;
        case 'bmp': $src = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($abs) : null; break;
        default: $src = @imagecreatefromjpeg($abs);
    }
    if (!$src) { return $orig; }
    $sw = imagesx($src); $sh = imagesy($src);
    if ($crop && $w > 0 && $h > 0) {
        $scale = max($w / $sw, $h / $sh);
        $nw = (int)round($sw * $scale); $nh = (int)round($sh * $scale);
        $dst = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $white);
        imagecopyresampled($dst, $src, (int)(($w - $nw) / 2), (int)(($h - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
    } else {
        $scale = min($w > 0 ? $w / $sw : PHP_INT_MAX, $h > 0 ? $h / $sh : PHP_INT_MAX, 1);
        $nw = max(1, (int)round($sw * $scale)); $nh = max(1, (int)round($sh * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
    }
    wc_dir_ensure(dirname($out));
    $ok = @imagejpeg($dst, $out, $q);
    imagedestroy($src); imagedestroy($dst);
    return $ok ? array($out, 'image/jpeg') : $orig;
}

/** 视频封面（poster）直链：取已生成的缩略图，没有则返回空 */
function wc_video_poster_abs($f)
{
    if ($f['kind'] !== 'video') { return ''; }
    $p = wc_thumb_path('u/' . (int)$f['uid'] . '/' . (int)$f['id'] . '.jpg');
    return is_file($p) ? $p : '';
}
