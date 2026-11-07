<?php
/** 邮箱验证：邮件里的链接落到这里 */
require_once __DIR__ . '/core/bootstrap.php';

$token = get('t') !== '' ? get('t') : post('t');
$pendingUid = token_check($token, 'v');
$res = user_verify($token);
$ok = ($res === true);

if ($ok && auth_uid() === 0 && $pendingUid > 0) {
    $u = db_row('SELECT * FROM wc_user WHERE id=?', array($pendingUid));
    if ($u && (int)$u['status'] !== 2) { do_login($u); }
}

page_head($ok ? '邮箱验证成功' : '邮箱验证失败');
box_start('邮箱验证');
if ($ok) {
    tip('邮箱验证成功！你的账号现在可以使用上传、外链分享等全部功能。', 'ok');
    echo '<p><a class="mybtn" href="' . e(url('upload.php')) . '">立即上传文件</a> '
        . '<a class="mybtn" href="' . e(url('files.php')) . '">进入文件管理</a> '
        . '<a class="mybtn" href="' . e(url('index.php')) . '">返回首页</a></p>';
} else {
    tip(e($res), 'err');
    echo '<p>可以：<a href="' . e(url('login.php')) . '">登录后</a>在用户中心重新发送验证邮件，或 '
        . '<a href="' . e(url('register.php')) . '">重新注册</a>。</p>';
}
box_end();
page_foot();
