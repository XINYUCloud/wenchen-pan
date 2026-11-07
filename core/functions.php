<?php
/** 公共函数库 */
if (!defined('WC_PAN')) { exit('No direct access'); }

/* ---------- 输入 ---------- */
function get($k, $d = '') { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
function post($k, $d = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function getint($k, $d = 0) { return isset($_GET[$k]) && $_GET[$k] !== '' ? (int)$_GET[$k] : $d; }
function postint($k, $d = 0) { return isset($_POST[$k]) && $_POST[$k] !== '' ? (int)$_POST[$k] : $d; }
/** 先取 POST 再取 GET 的整数（表单与链接通用） */
function rqint($k, $d = 0)
{
    if (isset($_POST[$k]) && $_POST[$k] !== '') { return (int)$_POST[$k]; }
    if (isset($_GET[$k]) && $_GET[$k] !== '') { return (int)$_GET[$k]; }
    return $d;
}
/** 先取 POST 再取 GET 的字符串 */
function rqstr($k, $d = '')
{
    if (isset($_POST[$k]) && trim((string)$_POST[$k]) !== '') { return trim((string)$_POST[$k]); }
    if (isset($_GET[$k]) && trim((string)$_GET[$k]) !== '') { return trim((string)$_GET[$k]); }
    return $d;
}
function intarr($v) { $out = array(); foreach ((array)$v as $i) { $i = (int)$i; if ($i > 0) { $out[] = $i; } } return $out; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nl2p($s) { return '<p>' . str_replace(array("\r\n", "\n"), "</p><p>", e($s)) . '</p>'; }
function is_post() { return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'; }
function is_ajax() { return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'; }

function ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $trust = setting('trust_proxy', '0');
    if ($trust && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $c = trim($parts[0]);
        if (filter_var($c, FILTER_VALIDATE_IP)) { $ip = $c; }
    }
    return substr($ip, 0, 45);
}

function ua($len = 250)
{
    return mb_substr(substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 400), 0, $len);
}

function url($path = '')
{
    return SITE_URL . ltrim($path, '/');
}

function go($path)
{
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)));
    exit;
}

/* ---------- 站点设置 ---------- */
function settings_all()
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $key = 'set:all';
    $raw = KV::get($key, '');
    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr)) { $cache = $arr; return $cache; }
    }
    $cache = array();
    foreach (db_rows('SELECT k,v FROM wc_setting') as $r) { $cache[$r['k']] = $r['v']; }
    KV::set($key, json_encode($cache), 120);
    return $cache;
}

function setting($k, $d = '')
{
    if (defined('WC_PAN')) {
        $s = settings_all();
        return isset($s[$k]) && $s[$k] !== '' ? $s[$k] : $d;
    }
    return $d;
}

function setting_int($k, $d = 0) { $v = setting($k, ''); return $v === '' ? $d : (int)$v; }
function setting_arr($k, $d = array()) { $v = setting($k, ''); if ($v === '') { return $d; } $a = json_decode($v, true); return is_array($a) ? $a : $d; }

function setting_set($k, $v)
{
    db_exec('REPLACE INTO wc_setting (k,v) VALUES (?,?)', array($k, (string)$v));
    KV::del('set:all');
}

function settings_set($map)
{
    foreach ($map as $k => $v) { db_exec('REPLACE INTO wc_setting (k,v) VALUES (?,?)', array($k, (string)$v)); }
    KV::del('set:all');
}

/* ---------- 格式化 ---------- */
function format_size($bytes)
{
    $bytes = (float)$bytes;
    $unit = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return ($i == 0 ? $bytes : number_format($bytes, $bytes < 10 ? 2 : 1)) . ' ' . $unit[$i];
}

function parse_size($text)
{
    $text = strtoupper(trim((string)$text));
    if ($text === '') { return 0; }
    $map = array('K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776);
    $tail = substr($text, -1);
    if (isset($map[$tail])) { return (int)((float)substr($text, 0, -1) * $map[$tail]); }
    if (substr($text, -2) === 'KB' || substr($text, -2) === 'MB' || substr($text, -2) === 'GB') {
        return (int)((float)substr($text, 0, -2) * $map[substr($text, -2, 1)]);
    }
    return (int)$text;
}

function cut_str($s, $len = 40, $end = '…')
{
    $s = (string)$s;
    if (function_exists('mb_strimwidth')) { return mb_strimwidth($s, 0, $len * 2, $end, 'UTF-8'); }
    return strlen($s) > $len * 2 ? substr($s, 0, $len * 2) . $end : $s;
}

function human_time($ts)
{
    $ts = (int)$ts;
    if ($ts <= 0) { return '-'; }
    $d = time() - $ts;
    if ($d < 60) { return '刚刚'; }
    if ($d < 3600) { return floor($d / 60) . ' 分钟前'; }
    if ($d < 86400) { return floor($d / 3600) . ' 小时前'; }
    if ($d < 2592000) { return floor($d / 86400) . ' 天前'; }
    return date('Y-m-d', $ts);
}

/* ---------- 随机串 ---------- */
function rand_str($len = 8)
{
    $s = '';
    $pool = 'abcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($pool) - 1;
    for ($i = 0; $i < $len; $i++) { $s .= $pool[random_int(0, $max)]; }
    return $s;
}

function rand_code($len = 6)
{
    $s = '';
    $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($pool) - 1;
    for ($i = 0; $i < $len; $i++) { $s .= $pool[random_int(0, $max)]; }
    return $s;
}

/** 生成未占用的分享短码 */
function new_share_code($len = 6)
{
    for ($try = 0; $try < 8; $try++) {
        $code = rand_str($len);
        if (!db_row('SELECT id FROM wc_share WHERE code=?', array($code))) { return $code; }
        $len++;
    }
    return substr(sha1(uniqid('', true)), 0, 10);
}

/* ---------- 文件类型 ---------- */
function kind_map()
{
    return array(
        'image'   => array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tif', 'tiff', 'heic'),
        'video'   => array('mp4', 'webm', 'ogv', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', 'rm', 'rmvb', 'mpg', 'mpeg', 'ts'),
        'audio'   => array('mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'oga', 'wma', 'mid', 'amr'),
        'doc'     => array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'rtf', 'csv', 'log', 'wps', 'et', 'dps', 'epub'),
        'archive' => array('zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'iso', 'cab'),
        'code'    => array('php', 'js', 'css', 'html', 'htm', 'json', 'xml', 'py', 'java', 'c', 'cpp', 'go', 'sql', 'sh', 'ini', 'yml'),
    );
}

function ext_of($name)
{
    $name = str_replace(array('\\', "\0"), array('/', ''), (string)$name);
    $p = pathinfo($name);
    return isset($p['extension']) ? strtolower(preg_replace('/[^a-z0-9]/i', '', $p['extension'])) : '';
}

function file_kind($ext)
{
    foreach (kind_map() as $kind => $list) {
        if (in_array($ext, $list, true)) { return $kind; }
    }
    return 'other';
}

function kind_name($kind)
{
    $n = array('image' => '图片', 'video' => '视频', 'audio' => '音频', 'doc' => '文档', 'archive' => '压缩包', 'code' => '代码', 'other' => '其他');
    return isset($n[$kind]) ? $n[$kind] : $kind;
}

/** 图标字符，2005 风格用文字代替图片图标 */
function kind_icon($kind, $ext = '')
{
    $m = array('image' => '图', 'video' => '影', 'audio' => '音', 'doc' => '文', 'archive' => '压', 'code' => '码', 'other' => '文');
    return isset($m[$kind]) ? $m[$kind] : '文';
}

/** 上传允许的扩展名（后台可配），并拦截绝对禁止的类型 */
function ext_allowed($ext, &$why = '')
{
    $ext = strtolower((string)$ext);
    $black = array('php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'asp', 'aspx', 'jsp', 'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll', 'htaccess', 'sh', 'bash');
    $force_block = setting_arr('block_ext', $black);
    if (in_array($ext, $force_block, true)) { $why = '该扩展名属于禁止上传类型'; return false; }
    $allow = setting_arr('allow_ext', array());
    if ($allow && !in_array($ext, $allow, true)) { $why = '不允许上传 .' . $ext . ' 类型'; return false; }
    $types = setting_arr('allow_kinds', array('image', 'video', 'audio', 'doc', 'archive', 'code', 'other'));
    if (!in_array(file_kind($ext), $types, true)) { $why = '不允许上传该大类文件'; return false; }
    return true;
}

/* ---------- 提示页 / 输出 ---------- */
function alert($msg, $url = '', $title = '系统提示', $die = true)
{
    $t = $die ? 3 : 0;
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . e($title) . '</title>'
        . '<link rel="stylesheet" href="' . url('assets/style.css') . '"></head><body>'
        . '<div class="tipbox"><div class="tithd">' . e($title) . '</div><div class="titbd">'
        . '<div class="tiMsg">' . $msg . '</div>'
        . ($url !== '' ? '<div class="tiUrl">系统 ' . $t . ' 秒后自动跳转，如未跳转请 <a href="' . e($url) . '">点击这里</a>。</div>' : '')
        . '</div><div class="tiFot">'
        . ($url !== '' ? '<a class="mybtn" href="' . e($url) . '">确定</a> ' : '')
        . '<a class="mybtn" href="javascript:history.back()">返回上一页</a></div></div></body>';
    if ($url !== '') {
        echo '<script>setTimeout(function(){location.href=' . json_encode($url) . '},3000);</script>';
    }
    echo '</html>';
    if ($die) { exit; }
}

function json_out($arr, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok($data = array()) { json_out(array_merge(array('ok' => 1), is_array($data) ? $data : array('data' => $data))); }
function json_err($msg, $extra = array()) { json_out(array_merge(array('ok' => 0, 'msg' => $msg), $extra)); }

/* ---------- 安全 ---------- */
/** 一次性提示（跨跳转显示） */
function flash($msg, $type = 'ok')
{
    KV::set('flash:' . session_id(), json_encode(array($type, (string)$msg), JSON_UNESCAPED_UNICODE), 180);
}

function flash_get()
{
    $raw = (string)KV::get('flash:' . session_id(), '');
    if ($raw === '') { return null; }
    KV::del('flash:' . session_id());
    $v = json_decode($raw, true);
    return is_array($v) ? $v : null;
}

function flash_show()
{
    $f = flash_get();
    if ($f) { tip($f[1], $f[0]); }
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = rand_str(16); }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_check($soft = false)
{
    $t = isset($_POST['_token']) ? $_POST['_token'] : (isset($_SERVER['HTTP_X_CSRF']) ? $_SERVER['HTTP_X_CSRF'] : '');
    if ($t !== '' && hash_equals($_SESSION['csrf'] ?? '', $t)) { return true; }
    if ($soft) { return false; }
    if (is_ajax()) { json_err('页面已过期，请刷新后重试', array('code' => 'csrf')); }
    alert('页面已过期或提交来源不正，请刷新后重新操作。', 'javascript:history.back()');
}

/** 限流：$key 唯一标识，$max 次 / $ttl 秒 */
function rate_limit($key, $max, $ttl)
{
    if ($max <= 0) { return true; }
    $n = KV::incr('limit:' . $key, $ttl);
    return $n <= $max;
}

function rate_left($key, $ttl)
{
    $t = KV::ttl('limit:' . $key);
    return $t > 0 ? $t : $ttl;
}

function is_email($email)
{
    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
    if (strlen($email) > 100) { return false; }
    $black = setting_arr('mail_black_domain', array());
    $d = substr($email, strpos($email, '@') + 1);
    if (in_array($d, $black, true)) { return false; }
    return true;
}

/** 邮箱是否允许注册（QQ/163 之类可强制校验） */
function mail_open_reg($email)
{
    if (!setting('reg_mail_check', '1')) { return true; }
    return strpos($email, '@') !== false;
}

function clean_name($name, $default = '未命名')
{
    $name = str_replace(array('/', '\\', "\0", "\n", "\r"), ' ', (string)$name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name, " .");
    if ($name === '') { $name = $default; }
    return mb_substr($name, 0, 200);
}

/* ---------- 日志 / 统计 / 站内信 ---------- */
function log_write($type, $action, $detail = '', $uid = 0, $uname = '')
{
    try {
        db_insert('wc_log', array(
            'type' => substr($type, 0, 20), 'uid' => (int)$uid, 'uname' => mb_substr((string)$uname, 0, 60),
            'action' => mb_substr((string)$action, 0, 90), 'detail' => mb_substr((string)$detail, 0, 1000),
            'ip' => ip(), 'add_time' => time(),
        ));
    } catch (Exception $e) {
        @file_put_contents(DATA_DIR . '/logs/app.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

function stat_touch($field, $bytes = 0)
{
    $today = date('Y-m-d');
    db_exec('INSERT INTO wc_stat (d, upload_cnt, upload_bytes, down_cnt, down_bytes, new_user, share_cnt) VALUES (?,0,0,0,0,0,0) ON DUPLICATE KEY UPDATE d=d', array($today));
    $allow = array('upload_cnt', 'upload_bytes', 'down_cnt', 'down_bytes', 'new_user', 'share_cnt');
    if (!in_array($field, $allow, true)) { return; }
    $delta = $field === 'upload_cnt' || $field === 'down_cnt' ? 1 : max(0, (int)$bytes);
    db_exec("UPDATE wc_stat SET `$field`=`$field`+$delta WHERE d=?", array($today));
}

function notice_add($uid, $title, $content = '', $type = 'system')
{
    if ((int)$uid <= 0) { return 0; }
    return db_insert('wc_notice', array('uid' => (int)$uid, 'title' => mb_substr((string)$title, 0, 120), 'content' => (string)$content, 'type' => $type, 'is_read' => 0, 'add_time' => time()));
}

function notice_unread($uid)
{
    return (int)db_one('SELECT COUNT(*) FROM wc_notice WHERE uid=? AND is_read=0', array((int)$uid));
}

/* ---------- 分页 ---------- */
function paginate($total, $page, $size, $url_tpl)
{
    $pages = (int)ceil($total / max(1, $size));
    if ($pages <= 1) { return ''; }
    $page = max(1, min($page, $pages));
    $html = '<div class="pagebar">共 ' . (int)$total . ' 条 ' . $page . '/' . $pages . ' 页 ';
    $from = max(1, $page - 3); $to = min($pages, $page + 3);
    if ($page > 1) { $html .= '<a href="' . e(str_replace('{p}', $page - 1, $url_tpl)) . '">上一页</a> '; }
    if ($from > 1) { $html .= '<a href="' . e(str_replace('{p}', 1, $url_tpl)) . '">1</a> … '; }
    for ($i = $from; $i <= $to; $i++) {
        $html .= $i === $page ? '<b>[' . $i . ']</b> ' : '<a href="' . e(str_replace('{p}', $i, $url_tpl)) . '">' . $i . '</a> ';
    }
    if ($to < $pages) { $html .= '… <a href="' . e(str_replace('{p}', $pages, $url_tpl)) . '">' . $pages . '</a> '; }
    if ($page < $pages) { $html .= '<a href="' . e(str_replace('{p}', $page + 1, $url_tpl)) . '">下一页</a>'; }
    return $html . '</div>';
}

/* ---------- 外链地址 ---------- */
/** 生成 api.php 动作地址；$needKey 为 true 时附带 CSRF token（GET 类修改动作必须） */
function api_url($do, $params = array(), $needKey = true)
{
    $q = array_merge(array('do' => $do), $params);
    if ($needKey) { $q['k'] = csrf_token(); }
    return url('api.php?' . http_build_query($q));
}

/** 缩略图/头像访问签名，避免通过自增 id 枚举他人文件 */
function media_secret()
{
    $sec = setting('media_secret', '');
    if ($sec === '') {
        $sec = bin2hex(random_bytes(12));
        setting_set('media_secret', $sec);
    }
    return $sec;
}

function media_sign($kind, $id)
{
    return substr(md5($kind . '|' . (int)$id . '|' . media_secret()), 0, 10);
}

function thumb_url($file)
{
    if (empty($file['thumb'])) { return ''; }
    $id = (int)$file['id'];
    return url('img.php?f=' . $id . '&t=' . media_sign('f', $id));
}

function avatar_url($uid)
{
    return url('img.php?avatar=' . (int)$uid . '&t=' . media_sign('a', (int)$uid));
}

function share_page_url($code) { return url('s/' . rawurlencode($code)); }
function share_direct_url($code) { return url('d/' . rawurlencode($code)); }

/** IP 黑名单（支持完整 IP 与 1.2.3.* 前缀） */
function ip_blocked($ip = null)
{
    $ip = $ip === null ? ip() : $ip;
    $list = setting_arr('ip_black', array());
    if (!$list) { return false; }
    foreach ($list as $r) {
        $r = trim((string)$r);
        if ($r === '') { continue; }
        if ($r === $ip) { return true; }
        if (substr($r, -1) === '*' && strpos($ip, rtrim($r, '*')) === 0) { return true; }
        if (strpos($r, '/') !== false && ip_in_cidr($ip, $r)) { return true; }
    }
    return false;
}

function ip_in_cidr($ip, $cidr)
{
    list($subnet, $bits) = explode('/', $cidr, 2);
    $ip2 = ip2long($ip); $sn = ip2long($subnet);
    if ($ip2 === false || $sn === false) { return false; }
    $mask = -1 << (32 - (int)$bits);
    return ($ip2 & $mask) === ($sn & $mask);
}

function base_href()
{
    $x = explode('?', isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
    $p = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/';
    return $p;
}

/* ---------- Gravatar 头像 ---------- */
/** 国内镜像是否在线（探测结果缓存；不在线时自动降级官方源） */
function gravatar_mirror_alive($force = false)
{
    $mirror = rtrim(trim(setting('gravatar_mirror', 'https://gravatar.fohok.xin')), '/');
    if ($mirror === '') { return false; }
    $key = 'grav:mirror:' . substr(md5($mirror), 0, 10);
    if (!$force) {
        $cached = KV::get($key, '');
        if ($cached === '1' || $cached === '0') { return $cached === '1'; }
    }
    $ok = http_probe($mirror . '/avatar/' . md5('probe-' . date('Ymd')) . '?s=32&d=404');
    KV::set($key, $ok ? '1' : '0', $ok ? max(60, setting_int('gravatar_cache', 600)) : 30);
    return $ok;
}

/** 只要能拿到 HTTP 响应就算在线（404 也算，因为 d=404 是预期返回） */
function http_probe($url)
{
    $timeout = max(1, setting_int('gravatar_timeout', 3));
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout + 2, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
        ));
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code > 0;
    }
    $ctx = stream_context_create(array(
        'http' => array('method' => 'HEAD', 'timeout' => $timeout, 'ignore_errors' => true),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
    ));
    $h = @get_headers($url, false, $ctx);
    return is_array($h) && isset($h[0]);
}

function gravatar_hash($email)
{
    return md5(strtolower(trim((string)$email)));
}

/** 当前应使用的 Gravatar 地址（d=404：无头像时返回 404，由前端降级到字母占位） */
function gravatar_url($email, $size = 96)
{
    if (!setting('gravatar_enable', '1')) { return ''; }
    $base = rtrim(trim(setting('gravatar_official', 'https://www.gravatar.com')), '/');
    if (gravatar_mirror_alive()) {
        $base = rtrim(trim(setting('gravatar_mirror', 'https://gravatar.fohok.xin')), '/');
    }
    return $base . '/avatar/' . gravatar_hash($email) . '?s=' . (int)$size . '&d=404';
}

/** 前端 onerror 用的备用地址（始终官方源） */
function gravatar_backup_url($email, $size = 96)
{
    $base = rtrim(trim(setting('gravatar_official', 'https://www.gravatar.com')), '/');
    return $base . '/avatar/' . gravatar_hash($email) . '?s=' . (int)$size . '&d=404';
}

function gravatar_backend_name()
{
    if (!setting('gravatar_enable', '1')) { return '已关闭'; }
    return gravatar_mirror_alive() ? '国内镜像' : '官方源（镜像探测失败已降级）';
}

/* ---------- SEO ---------- */
function seo_title($page = '')
{
    $site = setting('site_name', SITE_NAME);
    if ($page === '' || $page === $site) {
        $home = trim(setting('seo_title', ''));
        return $home !== '' ? $home : $site;
    }
    $fmt = trim(setting('seo_title_format', ''));
    if ($fmt === '') { return $page . ' - ' . $site; }
    return strtr($fmt, array('{page}' => $page, '{site}' => $site));
}

function seo_robots($isSharePage = false)
{
    if ($isSharePage) {
        return setting('seo_index_share', '0') ? 'index,follow' : 'noindex,nofollow';
    }
    $v = strtolower(trim(setting('seo_robots_meta', 'index,follow')));
    return $v === '' ? 'index,follow' : $v;
}

/** 页面描述：优先自定义，其次站点公告条 */
function seo_description()
{
    $d = trim(setting('seo_description', ''));
    if ($d !== '') { return $d; }
    $d = trim(setting('site_notice', ''));
    return $d !== '' ? $d : setting('site_name', SITE_NAME) . ' 提供图片、视频、文档等多格式文件的上传与外链分享服务。';
}

function seo_stats_code()
{
    if (!setting('seo_stats_enable', '1')) { return ''; }
    return trim((string)setting('seo_stats_code', ''));
}
