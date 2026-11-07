<?php
/**
 * 直链输出：/d/短码 或 d.php?c=短码
 * 图片/音视频默认 inline（可直接 <img>/<video> 引用），其余默认 attachment
 * 受 分享直链开关、访问密码、防盗链 Referer、限速、次数上限、时效签名 约束
 * 图片支持 ?w=&h=&q=&crop= 服务端缩放；视频支持 ?poster=1 输出封面
 */
define('WC_REQ_KIND', 'file');
require_once __DIR__ . '/core/bootstrap.php';

$code = wc_get('c') !== '' ? wc_get('c') : wc_get('d');
$code = preg_replace('/[^A-Za-z0-9]/', '', (string)$code);
if ($code === '') { http_response_code(400); exit('缺少短码'); }

$s = wc_share_find($code);
$state = wc_share_state($s);
$isOwner = wc_auth_uid() > 0 && (int)$s['uid'] === wc_auth_uid();
if ($state !== true && !$isOwner) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<meta charset="utf-8"><div style="font:13px SimSun;padding:30px"><b>直链不可用</b><br>' . wc_e($state) . '</div>';
    exit;
}

if (!$s['direct'] && !$isOwner) {
    http_response_code(403);
    exit('该分享未开启直链访问，请使用分享页地址。');
}
if (!wc_setting('share_allow_direct', '1') && !$isOwner) {
    http_response_code(403);
    exit('站点已关闭直链访问。');
}

/* 访问密码 */
if ($s['pwd'] !== '') {
    $ok = WcKV::get('shareauth:' . $code, '') === session_id()
        || (wc_get('p') !== '' && password_verify((string)wc_get('p'), $s['pwd']));
    if (!$ok && !$isOwner) { http_response_code(403); exit('需要访问密码：请在分享页输入密码，或在地址后加 &p=密码'); }
}

/* 时效签名直链：带 e/s 参数时校验；开启强制签名时无签名一律拒绝 */
$exp = wc_getint('e');
$sig = preg_replace('/[^a-f0-9]/', '', (string)wc_get('s'));
if ($exp > 0 || $sig !== '') {
    $v = wc_sign_verify($code, $exp, $sig, (string)wc_get('x'));
    if ($v === 'expired') { http_response_code(410); header('Content-Type: text/plain; charset=utf-8'); exit('410 签名直链已过期'); }
    if ($v !== true && !$isOwner) { http_response_code(403); exit('403 签名无效'); }
} elseif (wc_setting('share_sign_required', '0') === '1' && !$isOwner) {
    http_response_code(403);
    exit('403 本站直链需带时效签名，请从分享页复制签名链接');
}

/* 防盗链：share_hot_ref 为「list」型参数，按数组解析 */
$refs = array();
foreach (wc_setting_arr('share_hot_ref', array()) as $one) {
    $one = trim(strtolower((string)$one));
    if ($one !== '' && $one !== '[]') { $refs[] = $one; }
}
if ($refs && !$isOwner) {
    $ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $host = $ref !== '' ? strtolower((string)(parse_url($ref, PHP_URL_HOST) ?: '')) : '';
    $pass = $host === '';
    foreach ($refs as $r) {
        if ($host !== '' && (substr($host, -strlen($r)) === $r || $host === $r)) { $pass = true; break; }
    }
    if (!$pass) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('403 禁止直接引用（防盗链）'); }
}

/* 限流 */
if (!wc_rate_limit('d:' . wc_ip(), wc_setting_int('limit_download_ip', 120), 60)) {
    http_response_code(429);
    exit('请求过于频繁，请稍后再试');
}

$f = wc_db_row('SELECT * FROM wc_file WHERE id=?', array((int)$s['file_id']));
if (!$f || (int)$f['del_time'] > 0) { http_response_code(404); exit('文件不可访问'); }
$abs = wc_file_abs($f);
if ($abs === '') { http_response_code(404); wc_share_log($code, '直链-实体缺失'); exit('实体文件缺失'); }

$inline = in_array($f['kind'], array('image', 'video', 'audio'), true);
if (wc_get('a') === 'attach') { $inline = false; }
if (wc_get('a') === 'inline') { $inline = true; }
$disp = $inline ? 'inline' : 'attachment';

/* 视频封面直链 */
if (wc_get('poster') === '1') {
    $p = wc_video_poster_abs($f);
    if ($p === '') { http_response_code(404); exit('该视频暂无封面（需配置 ffmpeg 或上传时生成）'); }
    header('Cache-Control: public, max-age=' . (int)wc_setting('direct_cache', 60));
    $bytes = wc_stream_file($p, $f['name'] . '.jpg', 'inline', 'image/jpeg');
    wc_share_log($code, '封面直链', $bytes);
    exit;
}

/* 图片参数直链：w/h/q/crop */
$w = wc_getint('w'); $h = wc_getint('h'); $q = wc_getint('q'); $crop = wc_getint('crop');
if ($f['kind'] === 'image' && ($w > 0 || $h > 0)) {
    list($abs, $mime) = wc_image_variant($abs, $f, $w, $h, $q, $crop);
    header('Cache-Control: public, max-age=' . (int)wc_setting('direct_cache', 60));
    $bytes = wc_stream_file($abs, $f['name'], 'inline', $mime);
    wc_share_log($code, '缩放直链 ' . $w . 'x' . $h, $bytes);
    exit;
}

header('Cache-Control: public, max-age=' . (int)wc_setting('direct_cache', 60));
$bytes = wc_stream_file($abs, $f['name'], $disp);

if ($bytes > 0) {
    wc_db_exec('UPDATE wc_file SET traffic=traffic+?' . ($inline ? ', view=view+1' : ', `down`=`down`+1') . ' WHERE id=?', array((int)$bytes, (int)$f['id']));
    if (!$inline) {
        wc_db_exec('UPDATE wc_share SET `down`=`down`+1 WHERE code=?', array($code));
        wc_stat_touch('down_cnt');
    }
    wc_stat_touch('down_bytes', $bytes);
    wc_share_log($code, $inline ? '直链预览' : '直链下载', $bytes);
} else {
    wc_share_log($code, '直链中断', 0);
}
