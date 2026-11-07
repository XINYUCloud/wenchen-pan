<?php
/** 图形验证码：有 GD 输出 PNG，无 GD 时退回 SVG，保证任何环境都能注册 */
require_once __DIR__ . '/core/bootstrap.php';

$word = captcha_word();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (function_exists('imagecreatetruecolor')) {
    $w = 120; $h = 40;
    $base = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($base, 244, 247, 251);
    imagefilledrectangle($base, 0, 0, $w, $h, $bg);
    for ($i = 0; $i < 5; $i++) {
        $c = imagecolorallocatealpha($base, random_int(150, 220), random_int(150, 220), random_int(150, 220), 70);
        imageline($base, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
    }
    for ($i = 0; $i < 60; $i++) {
        $c = imagecolorallocatealpha($base, random_int(120, 200), random_int(120, 200), random_int(120, 200), 80);
        imagesetpixel($base, random_int(0, $w), random_int(0, $h), $c);
    }
    $chars = str_split($word);
    foreach ($chars as $i => $ch) {
        $cw = 24; $cx = 12 + $i * 26;
        $fg = imagecolorallocate($base, random_int(20, 90), random_int(40, 110), random_int(90, 170));
        $tmp = imagecreatetruecolor($cw, $h);
        imagefill($tmp, 0, 0, $bg);
        imagestring($tmp, 5, 4, 12, $ch, $fg);
        imagecopyresampled($base, $tmp, $cx, random_int(-4, 4), 0, 0, (int)($cw * 1.4), (int)($h * 1.2), $cw, $h);
        imagedestroy($tmp);
    }
    $border = imagecolorallocate($base, 150, 170, 195);
    imagerectangle($base, 0, 0, $w - 1, $h - 1, $border);
    header('Content-Type: image/png');
    imagepng($base);
    imagedestroy($base);
    exit;
}

// 无 GD：SVG
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40">'
    . '<rect width="120" height="40" fill="#f4f7fb" stroke="#96aac3"/>';
foreach (str_split($word) as $i => $ch) {
    $x = 14 + $i * 26; $rot = random_int(-18, 18);
    $col = sprintf('#%02x%02x%02x', random_int(20, 90), random_int(40, 110), random_int(90, 170));
    $svg .= '<text x="' . $x . '" y="28" font-family="Tahoma" font-size="22" font-weight="bold" fill="' . $col
        . '" transform="rotate(' . $rot . ' ' . $x . ' 24)">' . $ch . '</text>';
}
$svg .= '</svg>';
header('Content-Type: image/svg+xml');
echo $svg;
