<?php
/**
 * 安全加固层：安全响应头、CSRF 双层 token、会话绑定、敏感操作重认证、
 * 签名直链、图片参数直链、密钥轮换、后台 IP 白名单、目录暴露自检
 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_SECURITY')) { return; }
define('WC_CORE_SECURITY', 1);

/* ================= 安全响应头 ================= */
function sec_csp_mode() { return wc_setting('sec_csp', 'basic'); }

function sec_headers()
{
    if (headers_sent()) { return; }
    $frame = wc_setting('sec_frame', 'SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: ' . $frame);
    header('Referrer-Policy: ' . wc_setting('sec_referrer', 'strict-origin-when-cross-origin'));
    if (function_exists('header_remove')) {
        header_remove('X-Powered-By');
        if (wc_setting('sec_hide_phpver', '1')) { header_remove('Server'); }
    }
    if (wc_setting('sec_xss_protection', '1')) { header('X-XSS-Protection: 1; mode=block'); }
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), interest-cohort=()');
    if (COOKIE_SECURE && wc_setting('sec_hsts', '1')) {
        header('Strict-Transport-Security: max-age=' . (int)wc_setting('sec_hsts_maxage', 31536000) . '; includeSubDomains'
            . (wc_setting('sec_hsts_preload', '0') ? '; preload' : ''));
    }
    $csp = sec_csp_mode();
    if ($csp === 'off') { return; }
    $cdn = wc_setting('ui_cdn', 'local');
    $imgSrc = "self data: blob:";
    $scriptSrc = "'self' 'unsafe-inline'";
    $styleSrc = "'self' 'unsafe-inline'";
    if ($cdn !== 'local') {
        $host = parse_url(wc_setting('cdn_base', 'https://cdn.jsdelivr.net'), PHP_URL_HOST);
        if ($host) {
            $imgSrc .= ' https://' . $host;
            $scriptSrc .= ' https://' . $host;
            $styleSrc .= ' https://' . $host;
        }
    }
    if (wc_setting('qr_enable', '1') && wc_setting('qr_api', '') !== '') {
        $qh = parse_url(wc_setting('qr_api'), PHP_URL_HOST);
        if ($qh) { $imgSrc .= ' https://' . $qh; }
    }
    if ($csp === 'strict') {
        $scriptSrc = "'self'";
        $styleSrc = "'self' 'unsafe-inline'";
    }
    $parts = array(
        "default-src 'self'", "script-src " . $scriptSrc, "style-src " . $styleSrc,
        "img-src " . $imgSrc, "font-src 'self' data:", "connect-src 'self'",
        "media-src 'self'", "object-src 'none'", "frame-ancestors " . ($frame === 'DENY' ? "'none'" : "'self'"),
        "base-uri 'self'", "form-action 'self'",
    );
    if ($csp === 'strict') { $parts[] = "upgrade-insecure-requests"; }
    header('Content-Security-Policy: ' . implode('; ', $parts));
}

/* ================= CSRF：全局 + 一次性双轨 ================= */
function wc_csrf_token()
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = sec_rand(16); }
    return $_SESSION['csrf'];
}

/** 一次性表单 token（30 分钟有效，用过即废） */
function wc_csrf_once($tag = 'form')
{
    $t = $tag . ':' . sec_rand(20);
    WcKV::set('csrf:' . session_id() . ':' . $t, '1', 1800);
    return $t;
}

function wc_csrf_field($tag = 'form')
{
    return '<input type="hidden" name="_token" value="' . wc_e(wc_csrf_once($tag)) . '">';
}

/** 兼容旧式全局 token 的字段（用于会被多次提交的表单，如上传器） */
function wc_csrf_field_global()
{
    return '<input type="hidden" name="_token" value="' . wc_e(wc_csrf_token()) . '">';
}

/**
 * @param bool $soft  true 时只返回布尔，不中断请求
 * @param bool $once  是否要求一次性 token（默认接受两种）
 */
function wc_csrf_check($soft = false, $once = false)
{
    $t = isset($_POST['_token']) ? (string)$_POST['_token'] : '';
    if ($t === '' && isset($_SERVER['HTTP_X_CSRF'])) { $t = (string)$_SERVER['HTTP_X_CSRF']; }
    $ok = false;
    $strict = (int)wc_setting('sec_csrf_once', '1');
    if ($t !== '') {
        if (strpos($t, ':') !== false) {
            $key = 'csrf:' . session_id() . ':' . $t;
            if (WcKV::get($key, '') === '1') { WcKV::del($key); $ok = true; }
        } elseif (!$strict && !$once && hash_equals(wc_csrf_token(), $t)) {
            $ok = true;
        } elseif (!$once && hash_equals(wc_csrf_token(), $t)) {
            // AJAX 头与旧页面仍接受全局 token（上传器需要连续提交）
            $ok = true;
        }
    }
    if ($ok) { return true; }
    if ($soft) { return false; }
    if (wc_is_ajax()) { wc_json_err('页面已过期，请刷新后重试', array('code' => 'csrf')); }
    wc_alert('页面已过期或提交来源不正，请刷新后重新操作。', 'javascript:history.back()');
}

function sec_rand($len = 16)
{
    $pool = 'abcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    $max = strlen($pool) - 1;
    for ($i = 0; $i < $len; $i++) { $s .= $pool[random_int(0, $max)]; }
    return $s;
}

/* ================= 会话绑定与重认证 ================= */
function sec_bind_value()
{
    $mode = wc_setting('sec_session_bind', 'loose');
    if ($mode === 'off') { return ''; }
    $ua = (string)(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    $fam = preg_replace('/[0-9.]+/', '', substr($ua, 0, 60));
    $ip = wc_ip();
    if ($mode === 'strict') { $seed = $ua . '|' . $ip; }
    else { $seed = $fam . '|' . implode('.', array_slice(explode('.', $ip), 0, 3)); }
    return substr(md5($seed), 0, 12);
}

/** 会话指纹校验：不匹配则销毁会话，回到登录页 */
function sec_bind_check()
{
    if (wc_auth_uid() <= 0) { return true; }
    $want = sec_bind_value();
    if ($want === '') { return true; }
    $has = isset($_SESSION['bind']) ? (string)$_SESSION['bind'] : '';
    if ($has === '') { $_SESSION['bind'] = $want; return true; }
    if (!hash_equals($has, $want)) {
        $u = wc_auth_user();
        wc_log_write('login', '会话指纹不匹配，已强制下线', '期望 ' . $has . ' 实际 ' . $want, (int)($u['id'] ?? 0), ($u['email'] ?? ''));
        wc_try_logout();
        return false;
    }
    return true;
}

/** 敏感操作：15 分钟内验证过密码才放行 */
function sec_reauth_ok()
{
    return isset($_SESSION['reauth']) && $_SESSION['reauth'] > time() - 900;
}

/**
 * 要求重认证。$param 为提交的密码字段名，未通过时：
 * AJAX 返回 JSON 要求，页面返回密码确认表单
 * @return bool
 */
function sec_require_reauth($title = '该操作需要再次验证密码')
{
    if (sec_reauth_ok()) { return true; }
    $pw = isset($_POST['reauth_pass']) ? (string)$_POST['reauth_pass'] : '';
    $u = wc_auth_user();
    if ($pw !== '' && $u && password_verify($pw, $u['pass'])) {
        $_SESSION['reauth'] = time();
        return true;
    }
    if (wc_is_ajax()) {
        wc_json_err('需要重新输入密码确认身份', array('code' => 'reauth', 'title' => $title));
    }
    if ($pw !== '') { $err = '密码不正确'; } else { $err = ''; }
    $back = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>身份确认</title>'
        . '<link rel="stylesheet" href="' . wc_e(wc_ui_css_url()) . '"></head><body class="' . wc_e(wc_ui_body_class()) . '">'
        . '<div class="secReauth"><h3>' . wc_e($title) . '</h3>'
        . ($err !== '' ? '<div class="secErr">' . wc_e($err) . '</div>' : '')
        . '<p>请输入当前账号（' . wc_e($u['email']) . '）的登录密码：</p>'
        . '<form method="post" action="' . wc_e($back) . '">' . wc_csrf_field()
        . '<input type="password" name="reauth_pass" size="28" autofocus required> '
        . '<input type="submit" class="btn" value="确认">'
        . '<input type="hidden" name="do" value="' . wc_e(wc_rqstr('do')) . '">'
        . '<input type="hidden" name="id" value="' . wc_e(wc_rqstr('id')) . '">'
        . '<input type="hidden" name="uid" value="' . wc_e(wc_rqstr('uid')) . '">'
        . '<input type="hidden" name="code" value="' . wc_e(wc_rqstr('code')) . '">'
        . '<input type="hidden" name="all" value="' . wc_e(wc_rqstr('all')) . '">'
        . '</form>'
        . '<p><a href="javascript:history.back()">返回</a></p></div></body></html>';
    exit;
}

/* ================= 签名直链 ================= */
function sec_sign_secret($purpose = 'link')
{
    $k = 'sign_secret_' . $purpose;
    $v = wc_setting($k, '');
    if ($v === '') {
        $v = bin2hex(random_bytes(16));
        wc_setting_set($k, $v);
    }
    return $v;
}

/** 生成带有效期的签名直链；$ttl=0 表示不过期（此时不签名） */
function wc_sign_link($code, $ttl = 0, $extra = '')
{
    if ($ttl <= 0) { return wc_share_direct_url($code); }
    $exp = time() + (int)$ttl;
    $sig = hash_hmac('sha256', 'd|' . $code . '|' . $exp . '|' . $extra, sec_sign_secret('link'));
    return wc_share_direct_url($code) . '?e=' . $exp . '&s=' . substr($sig, 0, 40) . ($extra !== '' ? '&x=' . rawurlencode($extra) : '');
}

function wc_sign_verify($code, $exp, $sig, $extra = '')
{
    $exp = (int)$exp;
    if ($exp <= 0 || $sig === '' || strlen($sig) !== 40) { return false; }
    if ($exp < time()) { return 'expired'; }
    $want = hash_hmac('sha256', 'd|' . $code . '|' . $exp . '|' . $extra, sec_sign_secret('link'));
    return hash_equals(substr($want, 0, 40), $sig) ? true : false;
}

/** 图片参数直链：尺寸与质量（GD 可用时服务端缩放） */
function wc_img_param_url($code, $w = 0, $h = 0, $q = 0, $crop = 0)
{
    $u = wc_share_direct_url($code);
    $qs = array();
    if ($w > 0) { $qs[] = 'w=' . (int)$w; }
    if ($h > 0) { $qs[] = 'h=' . (int)$h; }
    if ($q > 0) { $qs[] = 'q=' . (int)$q; }
    if ($crop) { $qs[] = 'crop=1'; }
    return $qs ? $u . '?' . implode('&', $qs) : $u;
}

/* ================= 密钥轮换 ================= */
function sec_rotate_secrets()
{
    $done = array();
    wc_setting_set('media_secret', bin2hex(random_bytes(12)));
    $done[] = '缩略图/头像签名密钥';
    wc_setting_set('sign_secret_link', bin2hex(random_bytes(16)));
    $done[] = '签名直链密钥';
    unset($_SESSION['csrf']);
    WcKV::flush_prefix('csrf:' . session_id() . ':');
    $done[] = 'CSRF 令牌（当前页面需刷新）';
    WcKV::del('set:all');
    return $done;
}

/* ================= 后台 IP 白名单 ================= */
function sec_admin_ip_allowed()
{
    $list = wc_setting_arr('admin_ip_allow', array());
    if (!$list) { return true; }
    $ip = wc_ip();
    foreach ($list as $r) {
        $r = trim((string)$r);
        if ($r === '') { continue; }
        if ($r === $ip) { return true; }
        if (substr($r, -1) === '*' && strpos($ip, rtrim($r, '*')) === 0) { return true; }
        if (strpos($r, '/') !== false && wc_ip_in_cidr($ip, $r)) { return true; }
    }
    return false;
}

/* ================= data 目录暴露自检 ================= */
/** @return array(string 状态, string 说明) */
function sec_probe_data_leak()
{
    $dir = DATA_DIR . '/storage';
    $files = glob($dir . '/u/*/*/*');
    if (!$files) { return array('skip', '存储目录里还没有文件，无法自检。'); }
    $f = $files[0];
    $rel = ltrim(str_replace('\\', '/', substr($f, strlen($dir))), '/');
    $url = rtrim(SITE_URL, '/') . '/data/storage/' . $rel;
    $ch = curl_init($url);
    if (!$ch) { return array('skip', '无法初始化探测请求'); }
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false));
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($ok === false) { return array('skip', '本机无法向站点发起探测请求（可能被防火墙拦截），请手工访问确认。'); }
    if ($code === 200) { return array('danger', '危险：' . $url . ' 可被直接下载，请立即为 data 目录配置禁止访问（Apache/Nginx 规则见 README）。'); }
    if ($code === 403 || $code === 404) { return array('ok', 'data/storage 直接访问返回 ' . $code . '，目录保护生效。'); }
    return array('warn', '探测返回 HTTP ' . $code . '，请人工确认 data 目录访问限制。');
}

/* ================= 上传深度校验 ================= */
/**
 * 扩展名与真实内容一致性校验 + SVG/HTML 消毒
 * @return true|string 通过返回 true，否则返回拒绝原因
 */
function sec_deep_validate($abs, $ext)
{
    $ext = strtolower((string)$ext);
    $imgExt = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'tif', 'tiff');
    if (in_array($ext, $imgExt, true)) {
        $info = @getimagesize($abs);
        if ($info === false) { return '文件扩展名是图片，但内容不是有效图片（getimagesize 校验失败）'; }
        $real = image_type_to_extension($info[2], false);
        $real = strtolower($real === 'jpeg' ? 'jpg' : $real);
        $map = array('jpg' => array('jpg', 'jpeg'), 'jpeg' => array('jpg', 'jpeg'), 'tif' => array('tif', 'tiff'), 'tiff' => array('tif', 'tiff'));
        $allow = isset($map[$ext]) ? $map[$ext] : array($ext);
        if (!in_array($real, $allow, true)) {
            return '图片真实类型（' . $real . '）与扩展名（' . $ext . '）不一致，疑似伪装文件';
        }
        if ($info[0] > (int)wc_setting_int('upload_max_side', 20000) || $info[1] > (int)wc_setting_int('upload_max_side', 20000)) {
            return '图片尺寸超过上限';
        }
        return true;
    }
    if ($ext === 'svg') {
        $raw = (string)file_get_contents($abs, false, null, 0, 2 * 1048576);
        if (stripos($raw, '<svg') === false) { return 'SVG 内容无效'; }
        $clean = sec_svg_sanitize($raw);
        if ($clean === null) { return 'SVG 含无法安全清除的脚本内容，已拒绝'; }
        file_put_contents($abs, $clean);
        return true;
    }
    if (in_array($ext, array('html', 'htm', 'xhtml'), true)) {
        $raw = (string)file_get_contents($abs, false, null, 0, 2 * 1048576);
        $clean = sec_html_sanitize($raw);
        file_put_contents($abs, $clean);
        return true;
    }
    return true;
}

/** SVG 消毒：去掉脚本、事件、外部引用与 foreignObject */
function sec_svg_sanitize($raw)
{
    $bad = array('<script', '<foreignobject', '<iframe', '<embed', '<object', 'javascript:', 'data:text/html',
        'onload=', 'onclick=', 'onerror=', 'onmouseover=', 'onfocus=', 'onbegin=', 'onend=');
    foreach ($bad as $b) {
        if (stripos($raw, $b) !== false) { return null; }
    }
    // 外部 href / xlink:href 一律去掉（防 SSRF 与跟踪像素）
    $raw = preg_replace('/\s(xlink:)?href\s*=\s*("|\')(?!#|data:image)[^"\']*\2/i', '', $raw);
    $raw = preg_replace('/\sstyle\s*=\s*("|\')[^"\']*url\([^"\']*\1/i', '', $raw);
    return $raw;
}

/** HTML 消毒：去脚本/框架/事件/危险协议 */
function sec_html_sanitize($raw)
{
    $raw = preg_replace('#<\s*(script|iframe|frame|object|embed|form|input|button|meta|link|base)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $raw);
    $raw = preg_replace('#<\s*(script|iframe|frame|object|embed|form|input|button|meta|link|base)[^>]*/?>#is', '', $raw);
    $raw = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $raw);
    $raw = preg_replace('#(javascript|vbscript|data)\s*:#i', '', $raw);
    return $raw;
}

/* ================= 危险自检（安装与后台共用） ================= */
function sec_env_checks()
{
    $out = array();
    $out[] = array('config/config.php 权限', function_exists('fileperms') ? substr(sprintf('%o', @fileperms(WC_ROOT . '/config/config.php')), -4) : '-',
        (file_exists(WC_ROOT . '/config/config.php') && (fileperms(WC_ROOT . '/config/config.php') & 0077) !== 0) ? 'warn' : 'ok',
        '建议 640 或 600，避免同机其他用户读取数据库密码');
    $out[] = array('data 目录 Web 可访问自检', '', 'skip', '需存储内有文件才能探测');
    $r = sec_probe_data_leak();
    $out[count($out) - 1] = array('data 目录 Web 可访问自检', $r[0], $r[0] === 'ok' ? 'ok' : ($r[0] === 'danger' ? 'err' : 'warn'), $r[1]);
    $out[] = array('危险扩展名黑名单', implode(' ', array_slice(wc_setting_arr('block_ext', array()), 0, 6)) . ' …',
        count(wc_setting_arr('block_ext', array())) >= 10 ? 'ok' : 'warn', '缺失 php/exe/sh 等会形成远程执行风险');
    $out[] = array('上传目录 PHP 解析', function_exists('exec') ? 'exec 可用' : 'exec 已禁用', 'info', '宝塔需在站点设置里关闭 data 目录的 PHP 解析');
    $out[] = array('会话存放', WcKV::backend() === 'redis' ? 'Redis' : 'MySQL wc_kv', 'ok', '不写文件 session，避免同机串号');
    $out[] = array('CSP 模式', sec_csp_mode(), sec_csp_mode() === 'off' ? 'warn' : 'ok', 'off 时浏览器不做内容来源限制');
    $out[] = array('CSRF 一次性 token', wc_setting('sec_csrf_once', '1') ? '已启用' : '仅全局 token',
        wc_setting('sec_csrf_once', '1') ? 'ok' : 'warn', '启用后表单 token 用过即废');
    $out[] = array('后台 IP 白名单', count(wc_setting_arr('admin_ip_allow', array())) ? '已限制' : '未限制',
        count(wc_setting_arr('admin_ip_allow', array())) ? 'ok' : 'info', '未限制时任何 IP 都可尝试后台登录');
    $out[] = array('防爬虫引擎', guard_enabled() ? '已启用（' . wc_setting('scan_level', 'mid') . '）' : '关闭',
        guard_enabled() ? 'ok' : 'info', '拦截采集脚本、批量爬取与伪装蜘蛛');
    return $out;
}
