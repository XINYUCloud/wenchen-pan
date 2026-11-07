<?php
/** 登录 */
require_once __DIR__ . '/core/bootstrap.php';

if (auth_uid() > 0) { go('files.php'); }

$err = '';
$back = get('back');
if (!preg_match('#^/[^\r\n]*$#', (string)$back) || strpos($back, '//') === 0) { $back = url('files.php'); }
else { $back = rtrim(SITE_URL, '/') . $back; }

if (is_post() && post('do') === '1') {
    csrf_check();
    if (setting('captcha_login', '1') && !captcha_check(post('code'))) {
        $err = '图形验证码不正确。';
    } else {
        $r = try_login(post('email'), (string)post('pass'), post('remember') === '1');
        if ($r === true) {
            $u = auth_user();
            if ((int)$u['verified'] === 0) {
                go('user.php?tip=noverify');
            }
            go($back);
        }
        $err = $r;
    }
}

page_head('用户登录');
crumbs(array(array('首页', url()), '登录'));
box_start('用户登录');
if ($err !== '') { tip($err, 'err'); }
echo '<form method="post" action="' . e(url('login.php')) . '" class="frm">' . csrf_field()
    . '<input type="hidden" name="do" value="1"><input type="hidden" name="back" value="' . e(get('back')) . '">'
    . '<table class="frmTb">'
    . '<tr><th>邮箱</th><td><input type="text" name="email" size="30" maxlength="100" autofocus required></td></tr>'
    . '<tr><th>密码</th><td><input type="password" name="pass" size="24" maxlength="64" required></td></tr>';
if (setting('captcha_login', '1')) {
    echo '<tr><th>验证码</th><td><input type="text" name="code" size="8" required> <img src="' . e(url('captcha.php'))
        . '" width="120" height="40" class="capImg" alt="验证码" onclick="this.src=\'' . e(url('captcha.php')) . '?t=\'+Date.now()"></td></tr>';
}
echo '<tr><th>&nbsp;</th><td><label><input type="checkbox" name="remember" value="1"> 记住登录（30 天）</label></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="登录">　'
    . '<a href="' . e(url('register.php')) . '">免费注册</a>　<a href="' . e(url('forgot.php')) . '">忘记密码？</a></td></tr>'
    . '</table></form>'
    . '<div class="boxTips">连续输错 8 次将临时锁定该邮箱 15 分钟；如忘记内容可走找回密码。</div>';
box_end();
page_foot();
