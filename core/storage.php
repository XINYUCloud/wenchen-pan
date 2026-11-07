<?php
/** 存储层：落盘路径、配额、秒传去重、缩略图、Range 流式下载、打包、回收站 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_STORAGE')) { return; }
define('WC_CORE_STORAGE', 1);

function wc_dir_ensure($path)
{
    if (!is_dir($path)) { @mkdir($path, 0750, true); }
    return is_dir($path);
}

function wc_storage_path($rel = '')
{
    return DATA_DIR . '/storage' . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}

function wc_thumb_path($rel = '')
{
    return DATA_DIR . '/thumbs' . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}

function wc_tmp_path($rel = '')
{
    return DATA_DIR . '/tmp' . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}

/** 生成随机存放相对路径：u/1/202611/ab12cd34.jpg */
function wc_new_rel_path($uid, $ext)
{
    $ym = date('Ym');
    $name = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
    return 'u/' . (int)$uid . '/' . $ym . '/' . $name;
}

function wc_abs_of($rel)
{
    return wc_storage_path($rel);
}

/** 记录对应的绝对路径，含防穿越校验 */
function wc_file_abs($row)
{
    $rel = (string)$row['path'];
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) { return ''; }
    $abs = wc_storage_path($rel);
    return is_file($abs) ? $abs : '';
}

function wc_mime_of($ext)
{
    static $m = array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
        'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
        'flv' => 'video/x-flv', 'wmv' => 'video/x-ms-wmv', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
        'flac' => 'audio/flac', 'aac' => 'audio/aac', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
        'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8', 'log' => 'text/plain; charset=utf-8', 'json' => 'application/json',
        'xml' => 'application/xml', 'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed', '7z' => 'application/x-7z-compressed',
        'gz' => 'application/gzip', 'tar' => 'application/x-tar', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'epub' => 'application/epub+zip', 'php' => 'text/plain; charset=utf-8', 'js' => 'text/plain; charset=utf-8',
        'css' => 'text/plain; charset=utf-8', 'sql' => 'text/plain; charset=utf-8',
    );
    return isset($m[$ext]) ? $m[$ext] : 'application/octet-stream';
}

/** 用 finfo 校验真实类型，拒绝伪装成图片的脚本 */
function wc_sniff_type($abs)
{
    if (!function_exists('finfo_open')) { return ''; }
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    if (!$fi) { return ''; }
    $t = @finfo_file($fi, $abs);
    finfo_close($fi);
    return $t ? $t : '';
}

function wc_danger_type($mime)
{
    $bad = array('text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/x-sh',
        'application/x-dosexec', 'application/x-executable', 'application/x-msdownload', 'application/x-sharedlib');
    return in_array($mime, $bad, true);
}

/* ---------- 配额 ---------- */
function wc_user_used($uid)
{
    return (int)wc_db_one('SELECT COALESCE(SUM(size),0) FROM wc_file WHERE uid=?', array((int)$uid));
}

function wc_user_sync_used($uid)
{
    $real = wc_user_used($uid);
    wc_db_exec('UPDATE wc_user SET used=? WHERE id=?', array($real, (int)$uid));
    return $real;
}

/** @return true|string */
function wc_quota_check($u, $bytes)
{
    $used = wc_user_used((int)$u['id']);
    $quota = (int)$u['quota'];
    if ($quota > 0 && $used + $bytes > $quota) {
        return '空间不足：已用 ' . wc_format_size($used) . ' / ' . wc_format_size($quota) . '，本文件需 ' . wc_format_size($bytes);
    }
    $daily = wc_parse_size(wc_setting('daily_limit', '0'));
    if ($daily > 0) {
        $today = (int)wc_db_one('SELECT COALESCE(SUM(size),0) FROM wc_file WHERE uid=? AND add_time>=?', array((int)$u['id'], strtotime(date('Y-m-d'))));
        if ($today + $bytes > $daily) { return '今日上传量已达上限 ' . wc_format_size($daily); }
    }
    return true;
}

/* ---------- 入库 ---------- */
/**
 * 把临时文件（tmp 内的绝对路径）正式入库
 * @return array|string  成功返回 file 记录数组，失败返回错误说明
 */
function wc_file_commit($tmpAbs, $uid, $folder, $name, $size = 0, $sha1 = '')
{
    if (!is_file($tmpAbs)) { return '临时文件不存在，请重新上传'; }
    $name = wc_clean_name($name, '未命名文件');
    $ext = wc_ext_of($name);
    $why = '';
    if ($ext !== '' && !wc_ext_allowed($ext, $why)) { return $why; }
    $maxSingle = wc_parse_size(wc_setting('max_size', '200 MB'));
    if ($maxSingle > 0 && $size > $maxSingle) { return '单文件不得超过 ' . wc_format_size($maxSingle); }

    $mime = wc_sniff_type($tmpAbs);
    if (wc_danger_type($mime)) { return '检测到可执行脚本类型（' . $mime . '），禁止入库'; }
    $deep = sec_deep_validate($tmpAbs, $ext);
    if ($deep !== true) { return $deep; }

    if ($sha1 === '') { $s = hash_file('sha1', $tmpAbs); $sha1 = $s ? $s : ''; }
    $kind = wc_file_kind($ext);

    // 秒传：同 sha1 同大小直接复用物理文件
    $rel = '';
    if (wc_setting('dedupe', '1') && $sha1 !== '') {
        $hit = wc_db_row('SELECT path FROM wc_file WHERE sha1=? AND size=? AND path<>"" ORDER BY id ASC LIMIT 1', array($sha1, $size));
        if ($hit) { $rel = $hit['path']; }
    }
    if ($rel === '') {
        $rel = wc_new_rel_path($uid, $ext);
        wc_dir_ensure(dirname(wc_storage_path($rel)));
        if (!@rename($tmpAbs, wc_storage_path($rel))) {
            if (!@copy($tmpAbs, wc_storage_path($rel))) { return '写入存储目录失败，请检查 data/storage 目录权限'; }
            @unlink($tmpAbs);
        }
        @chmod(wc_storage_path($rel), 0640);
    } else {
        @unlink($tmpAbs);
    }

    $abs = wc_storage_path($rel);
    clearstatcache(true, $abs);
    $size = $size > 0 ? $size : (int)filesize($abs);
    $id = wc_db_insert('wc_file', array(
        'uid' => (int)$uid, 'folder' => (int)$folder, 'name' => $name, 'ext' => $ext, 'kind' => $kind,
        'size' => $size, 'sha1' => $sha1, 'path' => $rel, 'add_time' => time(),
    ));
    wc_db_exec('UPDATE wc_user SET used=used+? WHERE id=?', array($size, (int)$uid));
    wc_stat_touch('upload_cnt');
    wc_stat_touch('upload_bytes', $size);
    wc_thumb_make($id);
    wc_quota_warn((int)$uid);
    wc_log_write('upload', '上传文件', $name . ' (' . wc_format_size($size) . ')', $uid);
    return wc_db_row('SELECT * FROM wc_file WHERE id=?', array($id));
}

/** 空间使用超过 90% 时发一次站内信（每天最多一次） */
function wc_quota_warn($uid)
{
    $u = wc_db_row('SELECT quota,used,email,nick FROM wc_user WHERE id=?', array($uid));
    if (!$u || (int)$u['quota'] <= 0) { return; }
    $pct = round((int)$u['used'] / (int)$u['quota'] * 100, 1);
    if ($pct < 90) { return; }
    $key = 'quota:' . $uid . ':' . date('Ymd');
    if (!WcKV::setnx($key, '1', 86400)) { return; }
    wc_notice_add($uid, '空间使用提醒', '你的网盘空间已使用 ' . $pct . '%（' . wc_format_size($u['used']) . ' / ' . wc_format_size($u['quota']) . '）。'
        . '可以继续删除不需要的文件（含回收站彻底删除）来释放空间，或联系管理员调整配额。', 'quota');
}

/** 秒传命中：按 sha1+size 直接建立引用 */
function wc_file_dedup_commit($uid, $folder, $name, $sha1, $size)
{
    if (strlen($sha1) !== 40) { return null; }
    $hit = wc_db_row('SELECT * FROM wc_file WHERE sha1=? AND size=? ORDER BY id ASC LIMIT 1', array($sha1, (int)$size));
    if (!$hit) { return null; }
    $err = wc_quota_check(wc_auth_user(), $size);
    if ($err !== true) { return null; }
    $id = wc_db_insert('wc_file', array(
        'uid' => (int)$uid, 'folder' => (int)$folder, 'name' => wc_clean_name($name), 'ext' => $hit['ext'],
        'kind' => $hit['kind'], 'size' => (int)$size, 'sha1' => $sha1, 'path' => $hit['path'],
        'thumb' => '', 'add_time' => time(),
    ));
    wc_db_exec('UPDATE wc_user SET used=used+? WHERE id=?', array((int)$size, (int)$uid));
    wc_stat_touch('upload_cnt');
    wc_stat_touch('upload_bytes', $size);
    wc_log_write('upload', '秒传命中', wc_clean_name($name), $uid);
    return wc_db_row('SELECT * FROM wc_file WHERE id=?', array($id));
}

/* ---------- 缩略图 ---------- */
function wc_thumb_make($fileId)
{
    if (!wc_setting('thumb_enable', '1')) { return false; }
    $f = wc_db_row('SELECT * FROM wc_file WHERE id=?', array((int)$fileId));
    if (!$f) { return false; }
    $abs = wc_file_abs($f);
    if ($abs === '') { return false; }
    $ok = false;
    if ($f['kind'] === 'image' && function_exists('imagecreatetruecolor')) {
        $ok = wc_thumb_from_image($abs, $f);
    } elseif ($f['kind'] === 'video') {
        $ok = wc_thumb_from_video($abs, $f);
    }
    if ($ok) { wc_db_exec('UPDATE wc_file SET thumb=? WHERE id=?', array('u/' . (int)$f['uid'] . '/' . (int)$f['id'] . '.jpg', (int)$f['id'])); }
    return $ok;
}

/* ---------- 删除 / 回收站 ---------- */
function wc_file_soft_delete($ids, $uid = null)
{
    $n = 0;
    foreach (wc_intarr($ids) as $id) {
        $f = wc_db_row('SELECT * FROM wc_file WHERE id=?', array($id));
        if (!$f) { continue; }
        if ($uid !== null && (int)$f['uid'] !== (int)$uid && $uid !== 'admin') { continue; }
        if ((int)$f['del_time'] === 0) {
            wc_db_exec('UPDATE wc_file SET del_time=? WHERE id=?', array(time(), $id));
            wc_db_exec('UPDATE wc_share SET status=0 WHERE file_id=?', array($id));
            $n++;
        }
    }
    return $n;
}

function wc_file_restore($ids, $uid)
{
    $n = 0;
    foreach (wc_intarr($ids) as $id) {
        $f = wc_db_row('SELECT * FROM wc_file WHERE id=? AND uid=?', array($id, (int)$uid));
        if ($f && (int)$f['del_time'] > 0) {
            wc_db_exec('UPDATE wc_file SET del_time=0 WHERE id=?', array($id));
            $n++;
        }
    }
    return $n;
}

/** 物理删除，引用计数>0 时保留实体文件 */
function wc_file_purge($id)
{
    $f = wc_db_row('SELECT * FROM wc_file WHERE id=?', array((int)$id));
    if (!$f) { return false; }
    $ref = (int)wc_db_one('SELECT COUNT(*) FROM wc_file WHERE sha1=? AND path=? AND id<>?', array($f['sha1'], $f['path'], (int)$f['id']));
    if ($f['path'] !== '' && strpos($f['path'], '..') === false) {
        $abs = wc_storage_path($f['path']);
        if ($ref === 0 && is_file($abs)) { @unlink($abs); }
        $t = wc_thumb_path('u/' . (int)$f['uid'] . '/' . (int)$f['id'] . '.jpg');
        if (is_file($t)) { @unlink($t); }
    }
    wc_db_exec('DELETE FROM wc_file WHERE id=?', array((int)$id));
    wc_db_exec('UPDATE wc_user SET used=GREATEST(CAST(used AS SIGNED)-?,0) WHERE id=?', array((int)$f['size'], (int)$f['uid']));
    wc_db_exec('DELETE FROM wc_share WHERE file_id=?', array((int)$id));
    return true;
}

function wc_folder_purge($fid, $uid)
{
    $ids = wc_db_col('SELECT id FROM wc_file WHERE uid=? AND folder=?', array((int)$uid, (int)$fid));
    foreach ($ids as $id) { wc_file_purge($id); }
    wc_db_exec('DELETE FROM wc_folder WHERE id=? AND uid=?', array((int)$fid, (int)$uid));
    return count($ids);
}

/** 回收站过期清理，返回清理条数 */
function wc_recycle_clean($dry = false)
{
    $days = (int)wc_setting('recycle_days', '7');
    $cut = time() - $days * 86400;
    $rows = wc_db_rows('SELECT id,path,size,uid FROM wc_file WHERE del_time>0 AND del_time<?', array($cut));
    if ($dry) { return $rows; }
    foreach ($rows as $r) { wc_file_purge($r['id']); }
    return count($rows);
}

/* ---------- 目录 ---------- */
function wc_folder_breadcrumb($fid)
{
    $out = array();
    $guard = 0;
    while ((int)$fid > 0 && $guard++ < 30) {
        $f = wc_db_row('SELECT * FROM wc_folder WHERE id=?', array($fid));
        if (!$f) { break; }
        array_unshift($out, $f);
        $fid = (int)$f['parent'];
    }
    return $out;
}

function wc_folder_check_owner($fid, $uid)
{
    if ((int)$fid === 0) { return true; }
    return (bool)wc_db_row('SELECT id FROM wc_folder WHERE id=? AND uid=?', array((int)$fid, (int)$uid));
}

function wc_folder_depth($fid)
{
    $d = 0; $guard = 0;
    while ((int)$fid > 0 && $guard++ < 30) {
        $f = wc_db_row('SELECT parent FROM wc_folder WHERE id=?', array($fid));
        if (!$f) { break; }
        $fid = (int)$f['parent'];
        $d++;
    }
    return $d;
}

/** 递归收集文件夹（含子孙） */
function wc_folder_tree($uid, $fid, $depth = 0, $maxDepth = 8)
{
    $list = array();
    if ($depth >= $maxDepth) { return $list; }
    $kids = wc_db_rows('SELECT * FROM wc_folder WHERE uid=? AND parent=? ORDER BY name', array((int)$uid, (int)$fid));
    foreach ($kids as $k) {
        $k['depth'] = $depth;
        $k['cnt'] = (int)wc_db_one('SELECT COUNT(*) FROM wc_file WHERE uid=? AND folder=? AND del_time=0', array((int)$uid, (int)$k['id']));
        $k['bytes'] = (int)wc_db_one('SELECT COALESCE(SUM(size),0) FROM wc_file WHERE uid=? AND folder=? AND del_time=0', array((int)$uid, (int)$k['id']));
        $list[] = $k;
        $list = array_merge($list, wc_folder_tree($uid, (int)$k['id'], $depth + 1, $maxDepth));
    }
    return $list;
}

/* ---------- 流式输出 ---------- */
/**
 * 输出文件流，支持 Range / 断点续传
 * @return int 实际发送字节数
 */
function wc_stream_file($abs, $name, $disposition = 'attachment', $mime = '')
{
    if (!is_file($abs)) { return -1; }
    $size = filesize($abs);
    $ext = wc_ext_of($name);
    if ($mime === '') { $mime = wc_mime_of($ext); }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($abs)) . ' GMT');
    if ($disposition === 'attachment') {
        header('Content-Disposition: attachment; filename="' . wc_ASCII_fallback($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    } else {
        header('Content-Disposition: inline; filename="' . wc_ASCII_fallback($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    }
    $start = 0; $end = $size - 1; $partial = false;
    if (isset($_SERVER['HTTP_RANGE']) && $_SERVER['HTTP_RANGE'] !== '' && $size > 0) {
        if (preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] !== '') { $start = (int)$m[1]; } elseif ($m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
            if ($m[2] !== '' && $m[1] !== '') { $end = (int)$m[2]; }
            $partial = true;
            if ($start > $end || $end >= $size || $start >= $size) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                return 0;
            }
        }
    }
    $len = $end - $start + 1;
    if ($partial) {
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . $len);
    $fp = fopen($abs, 'rb');
    if (!$fp) { return -1; }
    if ($start > 0) { fseek($fp, $start); }
    $chunk = 512 * 1024;
    $sleep = 0;
    $limitKb = (int)wc_setting('down_speed', 0);
    if ($limitKb > 0) { $chunk = min($chunk, $limitKb * 1024); $sleep = (int)(1000000 * ($chunk / max(1, $limitKb * 1024))); }
    $sent = 0;
    while (!feof($fp) && $sent < $len) {
        if (connection_aborted()) { break; }
        $buf = fread($fp, min($chunk, $len - $sent));
        if ($buf === false || $buf === '') { break; }
        echo $buf;
        $sent += strlen($buf);
        flush();
        if ($sleep > 0) { usleep(min(500000, $sleep)); }
    }
    fclose($fp);
    return $sent;
}

function wc_ASCII_fallback($name)
{
    $a = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$name);
    return $a === '' ? 'file' : substr($a, -80);
}

/* ---------- 分享 ---------- */
function wc_share_create($uid, $fileId = 0, $folderId = 0, $opt = array())
{
    $code = wc_new_share_code();
    $expire = 0;
    $days = isset($opt['days']) ? (int)$opt['days'] : 0;
    if ($days > 0) { $expire = time() + $days * 86400; }
    $title = '';
    if ($fileId > 0) {
        $f = wc_db_row('SELECT name FROM wc_file WHERE id=?', array((int)$fileId));
        $title = $f ? $f['name'] : '';
    } elseif ($folderId > 0) {
        $f = wc_db_row('SELECT name FROM wc_folder WHERE id=?', array((int)$folderId));
        $title = $f ? $f['name'] . '/（文件夹）' : '';
    }
    $pwd = '';
    if (!empty($opt['pwd'])) { $pwd = password_hash((string)$opt['pwd'], PASSWORD_DEFAULT); }
    $id = wc_db_insert('wc_share', array(
        'code' => $code, 'uid' => (int)$uid, 'file_id' => (int)$fileId, 'folder_id' => (int)$folderId,
        'title' => mb_substr($title, 0, 200), 'pwd' => $pwd, 'expire' => $expire,
        'max_down' => isset($opt['max_down']) ? (int)$opt['max_down'] : 0,
        'direct' => !empty($opt['direct']) ? 1 : 0,
        'pub' => !empty($opt['pub']) ? 1 : 0,
        'status' => 1, 'add_time' => time(),
    ));
    wc_stat_touch('share_cnt');
    return wc_db_row('SELECT * FROM wc_share WHERE id=?', array($id));
}

function wc_share_find($code)
{
    return wc_db_row('SELECT s.*, u.nick, u.email, u.status AS ustatus FROM wc_share s LEFT JOIN wc_user u ON u.id=s.uid WHERE s.code=?', array(trim((string)$code)));
}

/** 分享可用性检查 */
function wc_share_state($s, $deep = array())
{
    if (!$s) { return '链接不存在'; }
    if ((int)$s['status'] === 0) { return '链接已被所有者或管理员关闭'; }
    if ((int)$s['expire'] > 0 && $s['expire'] < time()) { return '链接已超过有效期，自动失效'; }
    if ((int)$s['max_down'] > 0 && (int)$s['down'] >= (int)$s['max_down']) { return '该链接下载次数已用完'; }
    if ((int)$s['ustatus'] === 2) { return '分享者账号已被封禁，链接停止访问'; }
    if ((int)$s['file_id'] > 0) {
        $f = wc_db_row('SELECT * FROM wc_file WHERE id=?', array((int)$s['file_id']));
        if (!$f) { return '文件已被彻底删除'; }
        if ((int)$f['del_time'] > 0) { return '文件已在回收站中，链接暂时无法访问'; }
    }
    return true;
}

function wc_share_log($code, $result, $bytes = 0)
{
    try {
        wc_db_insert('wc_share_log', array('code' => substr((string)$code, 0, 12), 'ip' => wc_ip(), 'ua' => wc_ua(), 'result' => mb_substr((string)$result, 0, 30), 'bytes' => (int)$bytes, 'add_time' => time()));
    } catch (Exception $e) {}
}

/* ---------- URL 转存 ---------- */
/** @return array|string 成功返回临时文件绝对路径+大小+名字，失败返回错误 */
function wc_remote_fetch($url)
{
    if (!wc_setting('url_fetch', '1')) { return '站点未开启 URL 转存功能'; }
    $u = filter_var(trim((string)$url), FILTER_VALIDATE_URL);
    if (!$u || !preg_match('#^https?://#i', $u)) { return '地址格式不正确，仅支持 http/https'; }
    $host = parse_url($u, PHP_URL_HOST);
    $ipr = @gethostbyname($host);
    if (filter_var($ipr, FILTER_VALIDATE_IP)) {
        if (!filter_var($ipr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return '出于安全考虑，不允许转存内网地址';
        }
    } else { return '域名解析失败'; }
    if (!function_exists('curl_init')) { return '服务器未安装 curl 扩展，无法转存'; }
    $max = wc_parse_size(wc_setting('max_size', '200 MB'));
    $name = basename(parse_url($u, PHP_URL_PATH) ?: 'download');
    $ch = curl_init($u);
    $tmp = wc_tmp_path('rf_' . uniqid());
    wc_dir_ensure(wc_tmp_path());
    $fp = fopen($tmp, 'wb');
    if (!$fp) { return '临时目录不可写'; }
    curl_setopt_array($ch, array(
        FILE => $fp, FOLLOWLOCATION => true, MAXREDIRS => 3, CONNECTTIMEOUT => 10,
        TIMEOUT => wc_setting_int('fetch_timeout', 120), LOW_SPEED_LIMIT => 1024, LOW_SPEED_TIME => 30,
        USERAGENT => wc_setting('fetch_ua', 'Mozilla/5.0 (compatible; WenchenPan/1.0)'),
        SSL_VERIFYPEER => false, SSL_VERIFYHOST => 0, HEADERFUNCTION => function ($h) use (&$name) {
            if (preg_match('/^\s*content-disposition:.*filename\*?=(?:UTF-8\'\')?"?([^";]+)/i', implode('', (array)$h), $m)) { $name = urldecode(trim($m[1], "\"")); }
            return strlen($h) > 2;
        },
    ));
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $dl = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    fclose($fp);
    if ($ok === false) { @unlink($tmp); return '抓取失败：' . $err; }
    if ($code < 200 || $code >= 400) { @unlink($tmp); return '远端返回 HTTP ' . $code; }
    if ($dl <= 0) { @unlink($tmp); return '抓取内容为空'; }
    if ($max > 0 && $dl > $max) { @unlink($tmp); return '文件超过单文件大小上限 ' . wc_format_size($max); }
    return array('tmp' => $tmp, 'size' => $dl, 'name' => wc_clean_name($name, 'network_file'));
}

/* ---------- 维护任务 ---------- */
/** 定时清理（可由后台按钮或低概率自动触发） */
function wc_cron_clean()
{
    $n = 0;
    foreach (wc_db_rows('SELECT id FROM wc_file WHERE del_time>0 AND del_time<?', array(time() - (int)wc_setting('recycle_days', '7') * 86400)) as $r) {
        wc_file_purge($r['id']);
        $n++;
    }
    $cut = time() - 86400;
    foreach (wc_db_rows('SELECT upload_id FROM wc_chunk WHERE add_time<? AND status=0', array($cut)) as $c) {
        wc_chunk_clean($c['upload_id']);
    }
    wc_db_exec('DELETE FROM wc_share_log WHERE add_time<?', array(time() - 86400 * max(1, wc_setting_int('log_keep_days', 30))));
    wc_db_exec('DELETE FROM wc_log WHERE add_time<?', array(time() - 86400 * max(1, wc_setting_int('log_keep_days', 30))));
    return $n;
}

function wc_maybe_cron()
{
    if (!WcKV::setnx('cron:last', '1', 1800)) { return; }
    wc_cron_clean();
}
