<?php
/**
 * 直链输出：/d/短码 或 d.php?c=短码
 * 图片/音视频默认 inline（可直接 <img>/<video> 引用），其余默认 attachment
 * 受 分享直链开关、访问密码、防盗链 Referer、限速、次数上限 约束
 */
require_once __DIR__ . '/core/bootstrap.php';

$code = get('c') !== '' ? get('c') : get('d');
$code = preg_replace('/[^A-Za-z0-9]/', '', (string)$code);
if ($code === '') { http_response_code(400); exit('缺少短码'); }

$s = share_find($code);
$state = share_state($s);
$isOwner = auth_uid() > 0 && (int)$s['uid'] === auth_uid();
if ($state !== true && !$isOwner) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<meta charset="utf-8"><div style="font:13px SimSun;padding:30px"><b>直链不可用</b><br>' . e($state) . '</div>';
    exit;
}

if (!$s['direct'] && !$isOwner) {
    http_response_code(403);
    exit('该分享未开启直链访问，请使用分享页地址。');
}
if (!setting('share_allow_direct', '1') && !$isOwner) {
    http_response_code(403);
    exit('站点已关闭直链访问。');
}

/* 访问密码 */
if ($s['pwd'] !== '') {
    $ok = KV::get('shareauth:' . $code, '') === session_id()
        || (get('p') !== '' && password_verify((string)get('p'), $s['pwd']));
    if (!$ok && !$isOwner) { http_response_code(403); exit('需要访问密码：请在分享页输入密码，或在地址后加 &p=密码'); }
}

/* 防盗链：share_hot_ref 为「list」型参数，按数组解析 */
$refs = array();
foreach (setting_arr('share_hot_ref', array()) as $one) {
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
if (!rate_limit('d:' . ip(), setting_int('limit_download_ip', 120), 60)) {
    http_response_code(429);
    exit('请求过于频繁，请稍后再试');
}

$f = db_row('SELECT * FROM wc_file WHERE id=?', array((int)$s['file_id']));
if (!$f || (int)$f['del_time'] > 0) { http_response_code(404); exit('文件不可访问'); }
$abs = file_abs($f);
if ($abs === '') { http_response_code(404); share_log($code, '直链-实体缺失'); exit('实体文件缺失'); }

$inline = in_array($f['kind'], array('image', 'video', 'audio'), true);
if (get('a') === 'attach') { $inline = false; }
if (get('a') === 'inline') { $inline = true; }
$disp = $inline ? 'inline' : 'attachment';

header('Cache-Control: public, max-age=' . (int)setting('direct_cache', 60));
$bytes = stream_file($abs, $f['name'], $disp);

if ($bytes > 0) {
    db_exec('UPDATE wc_file SET traffic=traffic+?' . ($inline ? ', view=view+1' : ', `down`=`down`+1') . ' WHERE id=?', array((int)$bytes, (int)$f['id']));
    if (!$inline) {
        db_exec('UPDATE wc_share SET `down`=`down`+1 WHERE code=?', array($code));
        stat_touch('down_cnt');
    }
    stat_touch('down_bytes', $bytes);
    share_log($code, $inline ? '直链预览' : '直链下载', $bytes);
} else {
    share_log($code, '直链中断', 0);
}
