<?php
/** 后台登录入口 */
require_once dirname(__DIR__) . '/core/bootstrap.php';

if (is_admin()) { go('admin/index.php'); }

$err = '';
if (is_post()) {
    csrf_check();
    if (setting('admin_captcha', '1') && !captcha_check(post('code'), true)) {
        $err = '图形验证码不正确。';
    } elseif (!rate_limit('adminlogin:' . ip(), setting_int('limit_admin_login', 10), 600)) {
        $err = '尝试次数过多，请 10 分钟后再试。';
    } else {
        $r = try_login(post('email'), (string)post('pass'), post('remember') === '1');
        if ($r !== true) {
            $err = (string)$r;
        } elseif (!is_admin()) {
            try_logout();
            $err = '该账号不是管理员，无法进入后台。';
        } else {
            log_write('admin', '进入后台', '', auth_uid(), auth_user()['nick']);
            go('admin/index.php');
        }
    }
}
?><!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理后台 - <?php echo e(setting('site_name', SITE_NAME)); ?></title>
<link rel="stylesheet" href="<?php echo e(url('assets/admin.css?v=' . WC_VERSION)); ?>">
<script src="<?php echo e(url('assets/js/common.js?v=' . WC_VERSION)); ?>"></script>
</head><body class="cp">
<div class="cpLogin">
  <h2><?php echo e(setting('site_name', SITE_NAME)); ?> · 管理后台</h2>
  <div class="bd">
    <?php if ($err !== ''): ?><div class="tip tipErr"><?php echo $err; ?></div><?php endif; ?>
    <form method="post" action="<?php echo e(url('admin/login.php')); ?>">
      <?php echo csrf_field(); ?>
      <table>
        <tr><td width="60">邮箱</td><td><input type="text" name="email" size="26" maxlength="100" autofocus></td></tr>
        <tr><td>密码</td><td><input type="password" name="pass" size="26" maxlength="64"></td></tr>
        <?php if (setting('admin_captcha', '1')): ?>
        <tr><td>验证码</td><td><input type="text" name="code" size="8" maxlength="6">
          <img src="<?php echo e(url('captcha.php')); ?>" width="120" height="40" class="capImg" alt="验证码"
               onclick="this.src='<?php echo e(url('captcha.php')); ?>?t='+Date.now()"></td></tr>
        <?php endif; ?>
        <tr><td>&nbsp;</td><td><label><input type="checkbox" name="remember" value="1">记住登录</label></td></tr>
        <tr><td>&nbsp;</td><td><input type="submit" class="btnBig" value="登录后台">
          &nbsp;<a href="<?php echo e(url()); ?>">返回前台</a></td></tr>
      </table>
    </form>
    <div class="mini" style="margin-top:10px;color:#7b8ea3">
      本页面仅限管理员使用；连续错误将被临时锁定 IP。
      <?php if (auth_uid() > 0): ?><br><span class="danger">你已用普通账号登录，但权限不足。<a href="<?php echo e(url('logout.php')); ?>">退出</a> 后换管理员账号。</span><?php endif; ?>
    </div>
  </div>
</div>
</body></html>
