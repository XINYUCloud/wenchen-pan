<?php
/** 注册：邮箱 + 密码 + 验证码，注册后发送验证邮件 */
require_once __DIR__ . '/core/bootstrap.php';

if (auth_uid() > 0) { go('files.php'); }

$msg = '';
$sent = '';
if (is_post() && post('do') === 'reg') {
    csrf_check();
    if (!setting('reg_open', '1')) {
        $msg = '站点已关闭注册。';
    } else {
        if (setting('captcha_reg', '1') && !captcha_check(post('code'))) {
            $msg = '图形验证码不正确。';
        } else {
            $r = user_register(post('email'), (string)post('pass'), post('nick'));
            if (is_string($r)) {
                $msg = $r;
            } else {
                $sent = post('email');
                if ($r['verify']) {
                    // 自动登录，方便用户去用户中心重发验证信
                    $u = db_row('SELECT * FROM wc_user WHERE id=?', array((int)$r['uid']));
                    do_login($u);
                }
            }
        }
    }
}

if ($sent !== '') {
    page_head('注册成功 - 请验证邮箱');
    box_start('注册成功', '<a href="' . url('login.php') . '">返回登录</a>');
    echo '<div class="bigTip">你的账号 <b>' . e($sent) . '</b> 已创建成功。</div>'
        . '<p>系统已向该邮箱发送一封<b>验证邮件</b>，请在 48 小时内点击邮件中的链接完成验证。'
        . '未完成验证的账号<b>不能上传文件，也不能生成外链</b>。</p>'
        . '<p>没有收到？<br>1. 请检查垃圾邮件箱；<br>2. 稍等 10 分钟后在'
        . '<a href="' . url('user.php') . '">用户中心</a>点击「重新发送验证邮件」；<br>'
        . '3. 若站点尚未配置发信服务器，请联系管理员。</p>'
        . '<p><a class="mybtn" href="' . e(url('user.php')) . '">前往用户中心</a> '
        . '<a class="mybtn" href="' . e(url('login.php')) . '">返回登录</a></p>';
    box_end();
    page_foot();
    exit;
}

page_head('注册用户');
crumbs(array(array('首页', url()), '注册'));
box_start('新用户注册');
if ($msg !== '') { tip($msg, 'err'); }
echo '<form method="post" action="' . e(url('register.php')) . '" class="frm">' . csrf_field()
    . '<input type="hidden" name="do" value="reg">'
    . '<table class="frmTb">'
    . '<tr><th>邮箱地址</th><td><input type="text" name="email" size="34" maxlength="100" required value="' . e(post('email')) . '">'
    . '<div class="note">用于登录与接收验证邮件，请填写真实可用的邮箱。</div></td></tr>'
    . '<tr><th>登录密码</th><td><input type="password" name="pass" size="24" maxlength="64" required>'
    . '<div class="note">6-64 位，需同时包含字母与数字。</div></td></tr>'
    . '<tr><th>确认密码</th><td><input type="password" name="pass2" size="24" maxlength="64" required></td></tr>'
    . '<tr><th>昵称</th><td><input type="text" name="nick" size="20" maxlength="30" value="' . e(post('nick')) . '">'
    . '<div class="note">选填，默认取邮箱前缀。</div></td></tr>';
if (setting('captcha_reg', '1')) {
    echo '<tr><th>验证码</th><td><input type="text" name="code" size="8" maxlength="6" required> '
        . '<img src="' . e(url('captcha.php')) . '" width="120" height="40" class="capImg" alt="验证码" title="点击刷新" '
        . 'onclick="this.src=\'' . e(url('captcha.php')) . '?t=\'+Date.now()">'
        . '<div class="note">看不清？点击图片刷新。</div></td></tr>';
}
echo '<tr><th>服务条款</th><td><label><input type="checkbox" name="agree" value="1" required> 我已阅读并同意</label> '
    . '<a href="' . e(url('terms.php')) . '" target="_blank">《服务条款与隐私政策》</a></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="立即注册"> '
    . '<a href="' . e(url('login.php')) . '">已有账号，去登录</a>　<a href="' . e(url('forgot.php')) . '">忘记密码</a></td></tr>'
    . '</table></form>';
echo '<div class="boxTips">默认初始空间：' . e(setting('default_quota', '1 GB')) . '，单文件上限 ' . e(upload_limit_hint()) . '。</div>';
box_end();
page_foot();
