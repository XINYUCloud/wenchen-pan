<?php
/** 会话（存 Redis，降级 MySQL）+ 注册/登录/验证/找回密码 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_AUTH')) { return; }
define('WC_CORE_AUTH', 1);

class WcKvSessionHandler implements SessionHandlerInterface
{
    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }
    public function read($id): string { return (string)WcKV::get('sess:' . $id, ''); }
    public function write($id, $data): bool
    {
        $ttl = !empty($_SESSION['remember']) ? 2592000 : 86400;
        WcKV::set('sess:' . $id, $data, $ttl);
        return true;
    }
    public function destroy($id): bool { WcKV::del('sess:' . $id); return true; }
    public function gc($max): int
    {
        if (WcKV::backend() === 'mysql') {
            wc_db_exec('DELETE FROM wc_kv WHERE k LIKE ? AND expire_at>0 AND expire_at<?', array('sess:%', time()));
        }
        return 0;
    }
}

function wc_session_start_kv()
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    ini_set('session.gc_maxlifetime', 86400);
    session_set_cookie_params(0, '/', '', COOKIE_SECURE, true);
    session_name(SESSION_NAME);
    session_set_save_handler(new WcKvSessionHandler(), true);
    @session_start();
}

function wc_login_time() { return isset($_SESSION['ltime']) ? (float)$_SESSION['ltime'] : 0; }

function wc_auth_uid()
{
    static $cache = array();
    $sid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
    if ($sid <= 0) { return 0; }
    $key = session_id() . ':' . $sid;
    if (isset($cache[$key])) { return $cache[$key]; }
    $kick = (float)WcKV::get('kick:' . $sid, '0');
    if ($kick > 0 && wc_login_time() < $kick) {
        wc_auth_flush();
        $cache[$key] = 0;
        return 0;
    }
    $cache[$key] = $sid;
    return $sid;
}

function wc_auth_user()
{
    static $cache = array();
    $uid = wc_auth_uid();
    if ($uid <= 0) { return null; }
    if (array_key_exists($uid, $cache)) { return $cache[$uid] ?: null; }
    $u = wc_db_row('SELECT * FROM wc_user WHERE id=?', array($uid));
    if (!$u || (int)$u['status'] === 2) {
        wc_auth_flush();
        $cache[$uid] = false;
        return null;
    }
    $cache[$uid] = $u;
    return $u;
}

function wc_auth_role()
{
    $u = wc_auth_user();
    return $u ? $u['role'] : '';
}

function wc_is_admin()
{
    $role = wc_auth_role();
    return $role === 'admin' || $role === 'super';
}

function wc_auth_flush()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    @session_destroy();
    wc_session_start_kv();
}

function wc_require_login($back = true)
{
    if (wc_auth_uid() > 0) { return true; }
    $to = 'login.php?back=' . urlencode($_SERVER['REQUEST_URI'] ?? '');
    if (wc_is_ajax()) { wc_json_err('请先登录', array('code' => 'nologin', 'url' => $to)); }
    wc_go($to);
}

function wc_require_verified()
{
    $u = wc_auth_user();
    if (!$u) { wc_require_login(); }
    if ((int)$u['verified'] === 0) {
        $msg = '你的邮箱尚未验证，无法上传或分享。请到 <a href="' . wc_url('user.php') . '">用户中心</a> 重新收取验证邮件。';
        if (wc_is_ajax()) { wc_json_err('邮箱未验证，请先完成验证', array('code' => 'unverified')); }
        wc_alert($msg, wc_url('user.php'));
    }
    return true;
}

function wc_do_login($u, $remember = false)
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['uname'] = $u['nick'] !== '' ? $u['nick'] : $u['email'];
    $_SESSION['role'] = $u['role'];
    $_SESSION['ltime'] = microtime(true);
    $_SESSION['remember'] = $remember ? 1 : 0;
    $p = session_get_cookie_params();
    $exp = $remember ? time() + 2592000 : 0;
    setcookie(session_name(), session_id(), $exp, $p['path'], '', (bool)$p['secure'], true);
    wc_db_exec('UPDATE wc_user SET last_ip=?, last_time=?, login_cnt=login_cnt+1 WHERE id=?', array(wc_ip(), time(), (int)$u['id']));
    wc_log_write('login', '登录成功', $u['email'], (int)$u['id'], $_SESSION['uname']);
}

/**
 * 邮箱密码登录
 * @return string|true true 成功，字符串为错误提示
 */
function wc_try_login($email, $pass, $remember = false)
{
    $email = strtolower(trim((string)$email));
    if (!wc_rate_limit('login_ip:' . wc_ip(), wc_setting_int('limit_login_ip', 30), 60)) {
        return '操作过于频繁，请 1 分钟后再试';
    }
    $fails = (int)WcKV::get('loginfail:' . $email, '0');
    if ($fails >= wc_setting_int('limit_login_fail', 8)) {
        return '该账号失败次数过多，已被临时锁定，请 ' . ceil(wc_rate_left('loginfail:' . $email, 900) / 60) . ' 分钟后再试或找回密码';
    }
    $u = wc_db_row('SELECT * FROM wc_user WHERE email=?', array($email));
    if (!$u || !password_verify($pass, $u['pass'])) {
        WcKV::incr('loginfail:' . $email, 900);
        return '邮箱或密码不正确';
    }
    if ((int)$u['status'] === 2) {
        return '账号已被封禁' . ($u['ban_reason'] !== '' ? '，原因：' . $u['ban_reason'] : '');
    }
    if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) {
        wc_db_exec('UPDATE wc_user SET pass=? WHERE id=?', array(password_hash($pass, PASSWORD_DEFAULT), (int)$u['id']));
    }
    WcKV::del('loginfail:' . $email);
    wc_do_login($u, $remember);
    return true;
}

function wc_try_logout()
{
    if (wc_auth_uid() > 0) { wc_log_write('login', '退出登录', '', wc_auth_uid(), $_SESSION['uname'] ?? ''); }
    wc_auth_flush();
}

/** 强制所有设备下线（时间戳精确到微秒，避免同秒内重新登录被误踢） */
function wc_kick_all($uid)
{
    WcKV::set('kick:' . (int)$uid, sprintf('%.6f', microtime(true)), 86400 * 30);
}

/* ---------- 一次性 token（验证邮箱 / 重置密码） ---------- */
function wc_token_make($prefix, $uid, $ttl = 172800)
{
    $token = $prefix . substr(sha1($uid . '|' . microtime(true) . wc_rand_str(12)), 0, 40);
    WcKV::set('tok:' . $token, (string)$uid, $ttl);
    return $token;
}

function wc_token_check($token, $prefix)
{
    $token = trim((string)$token);
    if ($token === '' || strpos($token, $prefix) !== 0) { return 0; }
    $uid = (int)WcKV::get('tok:' . $token, '0');
    return $uid > 0 ? $uid : 0;
}

function wc_token_use($token)
{
    $uid = (int)WcKV::get('tok:' . $token, '0');
    WcKV::del('tok:' . $token);
    return $uid;
}

/** 注册：写库 + 发验证邮件 */
function wc_user_register($email, $pass, $nick)
{
    $email = strtolower(trim((string)$email));
    if (!wc_is_email($email)) { return '邮箱格式不正确或该邮箱域名不在允许范围'; }
    if (strlen($pass) < 6 || strlen($pass) > 64) { return '密码长度需 6-64 位'; }
    if (!preg_match('/[a-zA-Z]/', $pass) || !preg_match('/[0-9]/', $pass)) { return '密码需同时包含字母和数字'; }
    if (wc_db_row('SELECT id FROM wc_user WHERE email=?', array($email))) { return '该邮箱已注册，请直接登录或找回密码'; }
    if (!wc_rate_limit('reg_ip:' . wc_ip(), wc_setting_int('limit_reg_ip', 5), 3600)) { return '本机注册次数过多，请 1 小时后再试'; }
    if (!wc_setting('reg_open', '1')) { return '站点已关闭注册，请稍后再试'; }
    $nick = wc_clean_name($nick, substr($email, 0, strpos($email, '@')));
    $auto = (int)wc_setting('reg_auto_verify', '0');
    $uid = wc_db_insert('wc_user', array(
        'email' => $email, 'pass' => password_hash($pass, PASSWORD_DEFAULT), 'nick' => $nick,
        'quota' => wc_parse_size(wc_setting('default_quota', '1 GB')), 'verified' => $auto ? 1 : 0,
        'status' => $auto ? 1 : 0, 'role' => 'user', 'reg_ip' => wc_ip(), 'reg_time' => time(),
    ));
    wc_stat_touch('new_user');
    if ($auto) {
        wc_log_write('user', '注册账号', $email, $uid, $nick);
        return array('uid' => $uid, 'verify' => false);
    }
    wc_user_send_verify($uid, $email);
    wc_log_write('user', '注册账号', $email, $uid, $nick);
    return array('uid' => $uid, 'verify' => true);
}

function wc_user_send_verify($uid, $email = '')
{
    $u = $email !== '' ? array('id' => $uid, 'email' => $email, 'nick' => '') : wc_db_row('SELECT * FROM wc_user WHERE id=?', array((int)$uid));
    if (!$u) { return false; }
    if (!wc_rate_limit('mailvfy:' . $u['email'], 3, 600)) { return false; }
    $token = wc_token_make('v', (int)$u['id'], 172800);
    return wc_mail_tpl($u['email'], $u['nick'], 'verify', array(
        '{name}' => $u['nick'] !== '' ? $u['nick'] : $u['email'],
        '{url}'  => wc_url('verify.php?t=' . $token),
        '{time}' => '48',
    ));
}

function wc_user_verify($token)
{
    $uid = wc_token_check($token, 'v');
    if ($uid <= 0) { return '验证链接已失效，请重新登录或再次发送验证邮件'; }
    $u = wc_db_row('SELECT * FROM wc_user WHERE id=?', array($uid));
    if (!$u) { return '账号不存在'; }
    if ((int)$u['verified'] === 1) { wc_token_use($token); return true; }
    wc_db_exec('UPDATE wc_user SET verified=1, status=1 WHERE id=?', array($uid));
    wc_token_use($token);
    wc_notice_add($uid, '邮箱验证完成', '你的邮箱 ' . $u['email'] . ' 已验证成功，现在可以上传文件并生成外链了。', 'system');
    wc_log_write('user', '完成邮箱验证', $u['email'], $uid, $u['nick']);
    return true;
}

function wc_user_send_reset($email)
{
    $email = strtolower(trim((string)$email));
    if (!wc_rate_limit('mailrst:' . wc_ip(), 5, 600)) { return '请求过于频繁，请稍后再试'; }
    $u = wc_db_row('SELECT * FROM wc_user WHERE email=?', array($email));
    // 出于隐私考虑，账号不存在也返回成功
    if (!$u) { return true; }
    $token = wc_token_make('p', (int)$u['id'], 7200);
    wc_mail_tpl($u['email'], $u['nick'], 'reset', array(
        '{name}' => $u['nick'] !== '' ? $u['nick'] : $u['email'],
        '{url}'  => wc_url('forgot.php?t=' . $token),
        '{time}' => '2',
    ));
    return true;
}

function wc_user_reset_pass($token, $newpass)
{
    $uid = wc_token_check($token, 'p');
    if ($uid <= 0) { return '重置链接已失效，请重新申请'; }
    if (strlen($newpass) < 6 || strlen($newpass) > 64) { return '密码长度需 6-64 位'; }
    if (!preg_match('/[a-zA-Z]/', $newpass) || !preg_match('/[0-9]/', $newpass)) { return '密码需同时包含字母和数字'; }
    wc_db_exec('UPDATE wc_user SET pass=? WHERE id=?', array(password_hash($newpass, PASSWORD_DEFAULT), $uid));
    wc_token_use($token);
    wc_kick_all($uid);
    wc_log_write('user', '重置密码', '', $uid, '');
    return true;
}

function wc_user_change_pass($uid, $old, $new)
{
    $u = wc_db_row('SELECT * FROM wc_user WHERE id=?', array((int)$uid));
    if (!password_verify($old, $u['pass'])) { return '原密码不正确'; }
    if (strlen($new) < 6 || strlen($new) > 64) { return '新密码长度需 6-64 位'; }
    if ($new === $old) { return '新密码不能与原密码相同'; }
    wc_db_exec('UPDATE wc_user SET pass=? WHERE id=?', array(password_hash($new, PASSWORD_DEFAULT), (int)$uid));
    wc_log_write('user', '修改密码', '', (int)$uid, $u['nick']);
    return true;
}

/** 换绑邮箱：先发新邮箱验证，确认后生效 */
function wc_user_change_email($uid, $newmail)
{
    $newmail = strtolower(trim((string)$newmail));
    if (!wc_is_email($newmail)) { return '新邮箱格式不正确'; }
    if (wc_db_row('SELECT id FROM wc_user WHERE email=? AND id<>?', array($newmail, (int)$uid))) { return '该邮箱已被其他账号使用'; }
    $token = wc_token_make('e', (int)$uid, 7200);
    WcKV::set('newmail:' . $token, $newmail, 7200);
    $u = wc_db_row('SELECT nick FROM wc_user WHERE id=?', array((int)$uid));
    $ok = wc_mail_tpl($newmail, $u['nick'], 'change_mail', array(
        '{name}' => $u['nick'] !== '' ? $u['nick'] : $newmail,
        '{url}'  => wc_url('user.php?change=1&t=' . $token),
        '{time}' => '2',
    ));
    return $ok ? true : '发信失败，请检查后台邮件配置';
}

function wc_user_apply_email_change($token)
{
    $uid = wc_token_check($token, 'e');
    if ($uid <= 0) { return '确认链接已失效'; }
    $newmail = (string)WcKV::get('newmail:' . $token, '');
    if ($newmail === '') { return '确认信息已过期，请重新提交'; }
    wc_db_exec('UPDATE wc_user SET email=?, verified=0 WHERE id=?', array($newmail, $uid));
    WcKV::del('newmail:' . $token);
    wc_token_use($token);
    wc_user_send_verify($uid, $newmail);
    wc_log_write('user', '换绑邮箱为 ' . $newmail, '', $uid, '');
    return true;
}

/* ---------- 图形验证码 ---------- */
function wc_captcha_word()
{
    $w = strtoupper(wc_rand_code(4));
    WcKV::set('cap:' . session_id(), $w, 600);
    return $w;
}

function wc_captcha_check($input, $force = false)
{
    if (!$force && !wc_setting('captcha_reg', '1') && !wc_setting('captcha_login', '0')) { return true; }
    $w = (string)WcKV::get('cap:' . session_id(), '');
    WcKV::del('cap:' . session_id());
    if ($w === '') { return false; }
    return strtoupper(trim((string)$input)) === $w;
}
