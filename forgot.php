<?php
/** 找回密码：申请重置链接 → 邮件 → 设置新密码 */
require_once __DIR__ . '/core/bootstrap.php';

$token = wc_get('t') !== '' ? wc_get('t') : wc_post('t');
$err = '';
$done = '';

if (wc_is_post() && wc_post('do') === 'apply') {
    wc_csrf_check();
    if (wc_setting('captcha_login', '1') || wc_setting('captcha_reg', '1')) {
        if (!wc_captcha_check(wc_post('code'))) { $err = '图形验证码不正确。'; }
    }
    if ($err === '') {
        if (!wc_rate_limit('forgot_ip:' . wc_ip(), 10, 600)) { $err = '本机请求过于频繁，请 10 分钟后再试。'; }
    }
    if ($err === '') {
        $r = wc_user_send_reset(wc_post('email'));
        if ($r === true) { $done = wc_post('email'); } else { $err = (string)$r; }
    }
}

if (wc_is_post() && wc_post('do') === 'reset') {
    wc_csrf_check();
    if ((string)wc_post('pass') !== (string)wc_post('pass2')) {
        $err = '两次输入的新密码不一致。';
    } else {
        $r = wc_user_reset_pass($token, (string)wc_post('pass'));
        if ($r === true) { wc_alert('密码已重设成功，请用新密码登录。', wc_url('login.php'), '重置完成'); }
        $err = (string)$r;
    }
}

if ($done !== '') {
    wc_page_head('找回密码 - 请查收邮件');
    wc_box_start('重置邮件已发出');
    wc_tip('如果该邮箱已注册，系统已发送重置链接，请在 2 小时内点击邮件中的地址完成重设。', 'ok');
    echo '<p>没有收到请检查垃圾邮件，或 <a href="' . wc_e(wc_url('forgot.php')) . '">重新提交</a>。</p>';
    wc_box_end();
    wc_page_foot();
    exit;
}

wc_page_head('找回密码');
wc_crumbs(array(array('首页', wc_url()), '找回密码'));

if ($token !== '') {
    $uid = wc_token_check($token, 'p');
    wc_box_start('设置新密码');
    if ($uid <= 0) {
        wc_tip('重置链接无效或已过期，请重新申请。', 'err');
        echo '<p><a href="' . wc_e(wc_url('forgot.php')) . '">重新申请重置</a></p>';
    } else {
        if ($err !== '') { wc_tip($err, 'err'); }
        $u = wc_db_row('SELECT email,nick FROM wc_user WHERE id=?', array($uid));
        echo '<form method="post" action="' . wc_e(wc_url('forgot.php')) . '" class="frm">' . wc_csrf_field()
            . '<input type="hidden" name="do" value="reset"><input type="hidden" name="t" value="' . wc_e($token) . '">'
            . '<table class="frmTb">'
            . '<tr><th>账号</th><td>' . wc_e($u['email']) . ($u['nick'] !== '' ? '（' . wc_e($u['nick']) . '）' : '') . '</td></tr>'
            . '<tr><th>新密码</th><td><input type="password" name="pass" size="24" maxlength="64" required>'
            . '<div class="note">6-64 位，需同时包含字母与数字；重设后所有已登录设备将被强制下线。</div></td></tr>'
            . '<tr><th>确认密码</th><td><input type="password" name="pass2" size="24" maxlength="64" required></td></tr>'
            . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="提交新密码"></td></tr></table></form>';
    }
    wc_box_end();
    wc_page_foot();
    exit;
}

wc_box_start('通过邮箱找回密码');
if ($err !== '') { wc_tip($err, 'err'); }
echo '<form method="post" action="' . wc_e(wc_url('forgot.php')) . '" class="frm">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="apply">'
    . '<table class="frmTb">'
    . '<tr><th>注册邮箱</th><td><input type="text" name="email" size="32" maxlength="100" required autofocus></td></tr>'
    . '<tr><th>验证码</th><td><input type="text" name="code" size="8" maxlength="6"> <img src="' . wc_e(wc_url('captcha.php'))
    . '" width="120" height="40" class="capImg" alt="验证码" onclick="this.src=\'' . wc_e(wc_url('captcha.php')) . '?t=\'+Date.now()"></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="发送重置邮件">　<a href="' . wc_e(wc_url('login.php')) . '">返回登录</a></td></tr>'
    . '</table></form>'
    . '<div class="boxTips">出于账号隐私保护，无论该邮箱是否注册过，页面都会提示已发送。</div>';
wc_box_end();
wc_page_foot();
