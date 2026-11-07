<?php
/**
 * 缩略图 / 头像输出（带签名，避免枚举）
 * img.php?f=文件id&t=签名       缩略图（缺失时图片直接输出原图，其它类型 404）
 * img.php?avatar=用户id&t=签名  头像
 */
require_once __DIR__ . '/core/bootstrap.php';

$fid = getint('f');
$aid = getint('avatar');

if ($aid > 0) {
    if (get('t') !== media_sign('a', $aid)) { http_response_code(403); exit('签名不正确'); }
    $u = db_row('SELECT id,avatar,nick,email FROM wc_user WHERE id=?', array($aid));
    if (!$u || $u['avatar'] === '') {
        http_response_code(404);
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        exit;
    }
    $abs = DATA_DIR . '/avatars/' . $u['avatar'];
    if (!is_file($abs)) { http_response_code(404); exit('头像文件缺失'); }
    header('Content-Type: ' . mime_of(ext_of($u['avatar'])));
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

if ($fid <= 0) { http_response_code(400); exit('缺少参数'); }
if (get('t') !== media_sign('f', $fid)) { http_response_code(403); exit('签名不正确'); }

$f = db_row('SELECT * FROM wc_file WHERE id=? AND del_time=0', array($fid));
if (!$f) { http_response_code(404); exit('文件不存在'); }

$abs = '';
$mime = 'image/jpeg';
if (!empty($f['thumb'])) {
    $abs = thumb_path($f['thumb']);
}
if ($abs === '' || !is_file($abs)) {
    // 没有缩略图时，图片类直接给原图（限大小），其它类型返回 404
    if ($f['kind'] !== 'image') { http_response_code(404); exit('无缩略图'); }
    if ((int)$f['size'] > parse_size(setting('thumb_direct_max', '8 MB'))) { http_response_code(404); exit('原图过大'); }
    $abs = file_abs($f);
    if ($abs === '') { http_response_code(404); exit('实体缺失'); }
    $mime = mime_of($f['ext']);
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=' . (int)setting('thumb_cache', 86400));
header('X-File-Id: ' . (int)$f['id']);
readfile($abs);
