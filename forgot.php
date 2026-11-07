<?php
/** 找回密码：申请重置链接 → 邮件 → 设置新密码 */
require_once __DIR__ . '/core/bootstrap.php';

$token = get('t') !== '' ? get('t') : post('t');
$err = '';
$done = '';

if (is_post() && post('do') === 'apply') {
    csrf_check();
    if (setting('captcha_login', '1') || setting('captcha_reg', '1')) {
        if (!captcha_check(post('code'))) { $err = '图形验证码不正确。'; }
    }
    if ($err === '') {
        if (!rate_limit('forgot_ip:' . ip(), 10, 600)) { $err = '本机请求过于频繁，请 10 分钟后再试。'; }
    }
    if ($err === '') {
        $r = user_send_reset(post('email'));
        if ($r === true) { $done = post('email'); } else { $err = (string)$r; }
    }
}

if (is_post() && post('do') === 'reset') {
    csrf_check();
    if ((string)post('pass') !== (string)post('pass2')) {
        $err = '两次输入的新密码不一致。';
    } else {
        $r = user_reset_pass($token, (string)post('pass'));
        if ($r === true) { alert('密码已重设成功，请用新密码登录。', url('login.php'), '重置完成'); }
        $err = (string)$r;
    }
}

if ($done !== '') {
    page_head('找回密码 - 请查收邮件');
    box_start('重置邮件已发出');
    tip('如果该邮箱已注册，系统已发送重置链接，请在 2 小时内点击邮件中的地址完成重设。', 'ok');
    echo '<p>没有收到请检查垃圾邮件，或 <a href="' . e(url('forgot.php')) . '">重新提交</a>。</p>';
    box_end();
    page_foot();
    exit;
}

page_head('找回密码');
crumbs(array(array('首页', url()), '找回密码'));

if ($token !== '') {
    $uid = token_check($token, 'p');
    box_start('设置新密码');
    if ($uid <= 0) {
        tip('重置链接无效或已过期，请重新申请。', 'err');
        echo '<p><a href="' . e(url('forgot.php')) . '">重新申请重置</a></p>';
    } else {
        if ($err !== '') { tip($err, 'err'); }
        $u = db_row('SELECT email,nick FROM wc_user WHERE id=?', array($uid));
        echo '<form method="post" action="' . e(url('forgot.php')) . '" class="frm">' . csrf_field()
            . '<input type="hidden" name="do" value="reset"><input type="hidden" name="t" value="' . e($token) . '">'
            . '<table class="frmTb">'
            . '<tr><th>账号</th><td>' . e($u['email']) . ($u['nick'] !== '' ? '（' . e($u['nick']) . '）' : '') . '</td></tr>'
            . '<tr><th>新密码</th><td><input type="password" name="pass" size="24" maxlength="64" required>'
            . '<div class="note">6-64 位，需同时包含字母与数字；重设后所有已登录设备将被强制下线。</div></td></tr>'
            . '<tr><th>确认密码</th><td><input type="password" name="pass2" size="24" maxlength="64" required></td></tr>'
            . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="提交新密码"></td></tr></table></form>';
    }
    box_end();
    page_foot();
    exit;
}

box_start('通过邮箱找回密码');
if ($err !== '') { tip($err, 'err'); }
echo '<form method="post" action="' . e(url('forgot.php')) . '" class="frm">' . csrf_field()
    . '<input type="hidden" name="do" value="apply">'
    . '<table class="frmTb">'
    . '<tr><th>注册邮箱</th><td><input type="text" name="email" size="32" maxlength="100" required autofocus></td></tr>'
    . '<tr><th>验证码</th><td><input type="text" name="code" size="8" maxlength="6"> <img src="' . e(url('captcha.php'))
    . '" width="120" height="40" class="capImg" alt="验证码" onclick="this.src=\'' . e(url('captcha.php')) . '?t=\'+Date.now()"></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="发送重置邮件">　<a href="' . e(url('login.php')) . '">返回登录</a></td></tr>'
    . '</table></form>'
    . '<div class="boxTips">出于账号隐私保护，无论该邮箱是否注册过，页面都会提示已发送。</div>';
box_end();
page_foot();
