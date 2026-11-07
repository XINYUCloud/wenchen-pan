<?php
/** 登录 */
require_once __DIR__ . '/core/bootstrap.php';

if (wc_auth_uid() > 0) { wc_go('files.php'); }

$err = '';
$back = wc_get('back');
if (!preg_match('#^/[^\r\n]*$#', (string)$back) || strpos($back, '//') === 0) { $back = wc_url('files.php'); }
else { $back = rtrim(SITE_URL, '/') . $back; }

if (wc_is_post() && wc_post('do') === '1') {
    wc_csrf_check();
    if (wc_setting('captcha_login', '1') && !wc_captcha_check(wc_post('code'))) {
        $err = '图形验证码不正确。';
    } else {
        $r = wc_try_login(wc_post('email'), (string)wc_post('pass'), wc_post('remember') === '1');
        if ($r === true) {
            $u = wc_auth_user();
            if ((int)$u['verified'] === 0) {
                wc_go('user.php?tip=noverify');
            }
            wc_go($back);
        }
        $err = $r;
    }
}

wc_page_head('用户登录');
wc_crumbs(array(array('首页', wc_url()), '登录'));
wc_box_start('用户登录');
if ($err !== '') { wc_tip($err, 'err'); }
echo '<form method="post" action="' . wc_e(wc_url('login.php')) . '" class="frm">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="1"><input type="hidden" name="back" value="' . wc_e(wc_get('back')) . '">'
    . '<table class="frmTb">'
    . '<tr><th>邮箱</th><td><input type="text" name="email" size="30" maxlength="100" autofocus required></td></tr>'
    . '<tr><th>密码</th><td><input type="password" name="pass" size="24" maxlength="64" required></td></tr>';
if (wc_setting('captcha_login', '1')) {
    echo '<tr><th>验证码</th><td><input type="text" name="code" size="8" required> <img src="' . wc_e(wc_url('captcha.php'))
        . '" width="120" height="40" class="capImg" alt="验证码" onclick="this.src=\'' . wc_e(wc_url('captcha.php')) . '?t=\'+Date.now()"></td></tr>';
}
echo '<tr><th>&nbsp;</th><td><label><input type="checkbox" name="remember" value="1"> 记住登录（30 天）</label></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="登录">　'
    . '<a href="' . wc_e(wc_url('register.php')) . '">免费注册</a>　<a href="' . wc_e(wc_url('forgot.php')) . '">忘记密码？</a></td></tr>'
    . '</table></form>'
    . '<div class="boxTips">连续输错 8 次将临时锁定该邮箱 15 分钟；如忘记内容可走找回密码。</div>';
wc_box_end();
wc_page_foot();
