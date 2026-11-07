<?php
/** 后台登录入口 */
require_once dirname(__DIR__) . '/core/bootstrap.php';

if (wc_is_admin()) { wc_go('admin/index.php'); }

$err = '';
if (wc_is_post()) {
    wc_csrf_check();
    if (wc_setting('admin_captcha', '1') && !wc_captcha_check(wc_post('code'), true)) {
        $err = '图形验证码不正确。';
    } elseif (!wc_rate_limit('adminlogin:' . wc_ip(), wc_setting_int('limit_admin_login', 10), 600)) {
        $err = '尝试次数过多，请 10 分钟后再试。';
    } else {
        $r = wc_try_login(wc_post('email'), (string)wc_post('pass'), wc_post('remember') === '1');
        if ($r !== true) {
            $err = (string)$r;
        } elseif (!wc_is_admin()) {
            wc_try_logout();
            $err = '该账号不是管理员，无法进入后台。';
        } else {
            wc_log_write('admin', '进入后台', '', wc_auth_uid(), wc_auth_user()['nick']);
            wc_go('admin/index.php');
        }
    }
}
?><!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理后台 - <?php echo wc_e(wc_setting('site_name', SITE_NAME)); ?></title>
<link rel="stylesheet" href="<?php echo wc_e(wc_url('assets/admin.css?v=' . WC_VERSION)); ?>">
<script src="<?php echo wc_e(wc_url('assets/js/common.js?v=' . WC_VERSION)); ?>"></script>
</head><body class="cp">
<div class="cpLogin">
  <h2><?php echo wc_e(wc_setting('site_name', SITE_NAME)); ?> · 管理后台</h2>
  <div class="bd">
    <?php if ($err !== ''): ?><div class="tip tipErr"><?php echo $err; ?></div><?php endif; ?>
    <form method="post" action="<?php echo wc_e(wc_url('admin/login.php')); ?>">
      <?php echo wc_csrf_field(); ?>
      <table>
        <tr><td width="60">邮箱</td><td><input type="text" name="email" size="26" maxlength="100" autofocus></td></tr>
        <tr><td>密码</td><td><input type="password" name="pass" size="26" maxlength="64"></td></tr>
        <?php if (wc_setting('admin_captcha', '1')): ?>
        <tr><td>验证码</td><td><input type="text" name="code" size="8" maxlength="6">
          <img src="<?php echo wc_e(wc_url('captcha.php')); ?>" width="120" height="40" class="capImg" alt="验证码"
               onclick="this.src='<?php echo wc_e(wc_url('captcha.php')); ?>?t='+Date.now()"></td></tr>
        <?php endif; ?>
        <tr><td>&nbsp;</td><td><label><input type="checkbox" name="remember" value="1">记住登录</label></td></tr>
        <tr><td>&nbsp;</td><td><input type="submit" class="btnBig" value="登录后台">
          &nbsp;<a href="<?php echo wc_e(wc_url()); ?>">返回前台</a></td></tr>
      </table>
    </form>
    <div class="mini" style="margin-top:10px;color:#7b8ea3">
      本页面仅限管理员使用；连续错误将被临时锁定 IP。
      <?php if (wc_auth_uid() > 0): ?><br><span class="danger">你已用普通账号登录，但权限不足。<a href="<?php echo wc_e(wc_url('logout.php')); ?>">退出</a> 后换管理员账号。</span><?php endif; ?>
    </div>
  </div>
</div>
</body></html>
