<?php
/** 邮箱验证：邮件里的链接落到这里 */
require_once __DIR__ . '/core/bootstrap.php';

$token = wc_get('t') !== '' ? wc_get('t') : wc_post('t');
$pendingUid = wc_token_check($token, 'v');
$res = wc_user_verify($token);
$ok = ($res === true);

if ($ok && wc_auth_uid() === 0 && $pendingUid > 0) {
    $u = wc_db_row('SELECT * FROM wc_user WHERE id=?', array($pendingUid));
    if ($u && (int)$u['status'] !== 2) { wc_do_login($u); }
}

wc_page_head($ok ? '邮箱验证成功' : '邮箱验证失败');
wc_box_start('邮箱验证');
if ($ok) {
    wc_tip('邮箱验证成功！你的账号现在可以使用上传、外链分享等全部功能。', 'ok');
    echo '<p><a class="mybtn" href="' . wc_e(wc_url('upload.php')) . '">立即上传文件</a> '
        . '<a class="mybtn" href="' . wc_e(wc_url('files.php')) . '">进入文件管理</a> '
        . '<a class="mybtn" href="' . wc_e(wc_url('index.php')) . '">返回首页</a></p>';
} else {
    wc_tip(wc_e($res), 'err');
    echo '<p>可以：<a href="' . wc_e(wc_url('login.php')) . '">登录后</a>在用户中心重新发送验证邮件，或 '
        . '<a href="' . wc_e(wc_url('register.php')) . '">重新注册</a>。</p>';
}
wc_box_end();
wc_page_foot();
