<?php
/** 缩略图：GD 处理图片，可选 ffmpeg 截取视频首帧；无 GD 时静默跳过 */
if (!defined('WC_PAN')) { exit('No direct access'); }

function thumb_max_size()
{
    return array(max(48, setting_int('thumb_w', 240)), max(48, setting_int('thumb_h', 240)));
}

function thumb_file($fileId)
{
    $f = db_row('SELECT uid,id FROM wc_file WHERE id=?', array((int)$fileId));
    if (!$f) { return ''; }
    return thumb_path('u/' . (int)$f['uid'] . '/' . (int)$f['id'] . '.jpg');
}

function thumb_from_image($abs, $f)
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
    $limit = (int)setting_int('thumb_side_max', 4000);
    if ($w > $limit || $h > $limit) { imagedestroy($src); return false; }
    list($tw, $th) = thumb_max_size();
    $scale = min($tw / $w, $th / $h);
    if ($scale > 1) { $scale = 1; }
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $out = thumb_path('u/' . (int)$f['uid']);
    dir_ensure($out);
    $file = $out . '/' . (int)$f['id'] . '.jpg';
    $ok = @imagejpeg($dst, $file, (int)setting_int('thumb_quality', 82));
    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

function thumb_from_video($abs, $f)
{
    $bin = trim(setting('ffmpeg_path', ''));
    if ($bin === '' || !is_executable_safe($bin)) { return false; }
    $out = thumb_path('u/' . (int)$f['uid']);
    dir_ensure($out);
    $file = $out . '/' . (int)$f['id'] . '.jpg';
    $cmd = escapeshellarg($bin) . ' -nostdin -loglevel error -y -ss ' . (int)setting_int('ffmpeg_seek', 1)
        . ' -i ' . escapeshellarg($abs) . ' -frames:v 1 -vf "scale=' . thumb_max_size()[0] . ':-1" '
        . escapeshellarg($file) . ' 2>&1';
    @exec($cmd, $o, $rc);
    if ($rc === 0 && is_file($file) && filesize($file) > 0) { return true; }
    if (is_file($file)) { @unlink($file); }
    return false;
}

function is_executable_safe($bin)
{
    if (!function_exists('exec')) { return false; }
    return $bin !== '' && (strpos($bin, "\0") === false);
}

/** 文本/文档类文件生成占位图不需要，前台用类型图标即可 */
function thumb_exists($f)
{
    if (empty($f['thumb'])) { return false; }
    return is_file(thumb_path($f['thumb']));
}
