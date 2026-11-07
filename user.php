<?php
/** 用户中心：资料/头像/密码/邮箱/会话/空间明细/操作记录 */
require_once __DIR__ . '/core/bootstrap.php';
require_login();
$u = auth_user();
$uid = (int)$u['id'];
$tip = '';
$tipErr = '';

if (is_post()) {
    csrf_check();
    $do = post('do');
    if ($do === 'profile') {
        $nick = clean_name(post('nick'), $u['nick']);
        $sig = mb_substr(preg_replace('/[<>&"\']/', '', post('sig')), 0, 120);
        db_exec('UPDATE wc_user SET nick=?, sig=? WHERE id=?', array($nick, $sig, $uid));
        $_SESSION['uname'] = $nick !== '' ? $nick : $u['email'];
        $tip = '资料已保存。';
    } elseif ($do === 'pass') {
        if ((string)post('new') !== (string)post('new2')) { $tipErr = '两次输入的新密码不一致。'; }
        else {
            $r = user_change_pass($uid, (string)post('old'), (string)post('new'));
            if ($r === true) {
                if (post('kick') === '1') { kick_all($uid); auth_flush(); }
                $tip = '密码已修改' . (post('kick') === '1' ? '，其他设备已全部下线，请重新登录。' : '。');
            } else { $tipErr = (string)$r; }
        }
    } elseif ($do === 'email') {
        $r = user_change_email($uid, post('mail'));
        if ($r === true) { $tip = '确认邮件已发送至新邮箱 ' . e(post('mail')) . '，请在 2 小时内点击确认。'; }
        else { $tipErr = (string)$r; }
    } elseif ($do === 'resend') {
        $r = user_send_verify($uid);
        $tip = $r ? '验证邮件已重新发送，请查收（含垃圾箱）。' : '';
        $tipErr = $r ? '' : '发送失败或过于频繁：' . e((string)mail_last_error());
    } elseif ($do === 'kick') {
        kick_all($uid);
        $tip = '已向所有设备发出下线指令，其他浏览器需重新登录。';
    } elseif ($do === 'avatar') {
        $r = avatar_upload($uid, $u);
        if ($r === true) { $tip = '头像已更新。'; } else { $tipErr = $r; }
    } elseif ($do === 'avatar_clear') {
        $old = db_row('SELECT avatar FROM wc_user WHERE id=?', array($uid));
        if ($old['avatar'] !== '') { @unlink(DATA_DIR . '/avatars/' . $old['avatar']); }
        db_exec('UPDATE wc_user SET avatar=? WHERE id=?', array('', $uid));
        $tip = '头像已清除。';
    }
    $u = auth_user();
    flash($tipErr !== '' ? $tipErr : $tip, $tipErr !== '' ? 'err' : 'ok');
    go('user.php');
}

if (get('change') === '1' && get('t') !== '') {
    $r = user_apply_email_change(get('t'));
    if ($r === true) { alert('邮箱已更换成功，请查收新邮箱的验证邮件并完成验证。', url('user.php'), '换绑完成'); }
    alert((string)$r, url('user.php'), '换绑失败');
}

page_head('用户中心');
crumbs(array(array('首页', url()), '用户中心'));

flash_show();
if (get('tip') === 'noverify') { tip('你的邮箱还未验证，验证后才能上传与分享。', 'warn'); }

/* 空间明细 */
$kinds = db_rows('SELECT kind,COUNT(*) AS c,COALESCE(SUM(size),0) AS b FROM wc_file WHERE uid=? AND del_time=0 GROUP BY kind ORDER BY b DESC', array($uid));
$trashCnt = (int)db_one('SELECT COUNT(*) FROM wc_file WHERE uid=? AND del_time>0', array($uid));
$shareCnt = (int)db_count('wc_share', 'uid=? AND status=1', array($uid));
$folderCnt = (int)db_count('wc_folder', 'uid=?', array($uid));
$downCnt = (int)db_one('SELECT COALESCE(SUM(`down`),0) FROM wc_file WHERE uid=?', array($uid));
$traffic = (int)db_one('SELECT COALESCE(SUM(traffic),0) FROM wc_file WHERE uid=?', array($uid));

$used = (int)$u['used'];
$pct = (int)$u['quota'] > 0 ? min(100, round($used / max(1, (int)$u['quota']) * 100, 1)) : 0;
box_start('我的空间', '<a href="' . e(url('upload.php')) . '">上传文件</a> | <a href="' . e(url('files.php')) . '">文件管理</a>');
echo '<div class="bar barBig"><i style="width:' . $pct . '%"></i></div>'
    . '<div class="uStat">已用 <b>' . e(format_size($used)) . '</b> ／ 配额 <b>' . e(format_size($u['quota'])) . '</b>（' . $pct . '%）　剩余 ' . e(format_size(max(0, (int)$u['quota'] - $used))) . '</div>'
    . '<table class="tb mini"><tr class="hd"><td>类型</td><td>文件数</td><td>占用</td><td>占比</td></tr>';
foreach ($kinds as $k) {
    $p = $used > 0 ? round($k['b'] / $used * 100, 1) : 0;
    echo '<tr><td>' . file_icon($k['kind']) . ' ' . e(kind_name($k['kind'])) . '</td><td>' . (int)$k['c'] . '</td><td>' . e(format_size($k['b'])) . '</td><td>' . $p . '%</td></tr>';
}
echo '</table>'
    . '<div class="mini">文件夹 ' . $folderCnt . ' 个 ｜ 回收站 ' . $trashCnt . ' 个 ｜ 有效外链 ' . $shareCnt . ' 条 ｜ 累计下载 ' . $downCnt . ' 次 ｜ 消耗流量 ' . e(format_size($traffic)) . '</div>';
box_end();

/* 账号资料 + 头像 */
box_start('账号资料');
echo '<table class="colsTb"><tr><td width="52%">';
echo '<form method="post" action="' . e(url('user.php')) . '" class="frm">' . csrf_field() . '<input type="hidden" name="do" value="profile">'
    . '<table class="frmTb">'
    . '<tr><th>登录邮箱</th><td>' . e($u['email']) . ' ' . ((int)$u['verified'] === 1 ? '<span class="green">已验证</span>' : '<span class="red">未验证</span>') . '</td></tr>'
    . '<tr><th>昵称</th><td><input type="text" name="nick" size="20" maxlength="30" value="' . e($u['nick']) . '"></td></tr>'
    . '<tr><th>个性签名</th><td><input type="text" name="sig" size="34" maxlength="120" value="' . e($u['sig']) . '"></td></tr>'
    . '<tr><th>注册时间</th><td class="mini">' . e(date('Y-m-d H:i', (int)$u['reg_time'])) . '　上次登录：' . e($u['last_time'] ? date('Y-m-d H:i', (int)$u['last_time']) : '-') . '</td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存资料"></td></tr></table></form>';
echo '</td><td width="48%">';
echo '<div class="avRow">' . user_face($u, 96) . '<div class="avSide"><b>头像</b><br>'
    . '<form method="post" action="' . e(url('user.php')) . '" enctype="multipart/form-data">' . csrf_field()
    . '<input type="hidden" name="do" value="avatar"><input type="file" name="files[]" size="16"> '
    . '<input type="submit" class="btn" value="上传头像"></form>'
    . ($u['avatar'] !== '' ? '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="do" value="avatar_clear"><input type="submit" class="btn" value="清除头像"></form>' : '')
    . '<div class="note">建议正方形图片，≤2MB，最大 512px。</div>'
    . '<div class="note">未上传头像时自动使用 Gravatar（当前来源：' . e(gravatar_backend_name()) . '），'
    . ($u['avatar'] !== '' ? '自定义头像优先于 Gravatar。' : '邮箱 ' . e($u['email']) . ' 在 Gravatar 上有头像即会显示。')
    . '</div>'
    . '</div><div class="clr"></div></div>';
echo '</td></tr></table>';
box_end();

/* 安全设置 */
box_start('安全设置');
echo '<table class="colsTb"><tr><td width="52%">';
echo '<form method="post" action="' . e(url('user.php')) . '" class="frm">' . csrf_field() . '<input type="hidden" name="do" value="pass">'
    . '<table class="frmTb">'
    . '<tr><th>原密码</th><td><input type="password" name="old" size="22" required></td></tr>'
    . '<tr><th>新密码</th><td><input type="password" name="new" size="22" required> <span class="note">6-64 位，含字母和数字</span></td></tr>'
    . '<tr><th>确认新密码</th><td><input type="password" name="new2" size="22" required></td></tr>'
    . '<tr><th>&nbsp;</th><td><label><input type="checkbox" name="kick" value="1"> 修改后强制其他设备下线</label></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="修改密码"></td></tr></table></form>';
echo '</td><td width="48%">';
echo '<form method="post" action="' . e(url('user.php')) . '" class="frm">' . csrf_field() . '<input type="hidden" name="do" value="email">'
    . '<table class="frmTb">'
    . '<tr><th>新邮箱</th><td><input type="text" name="mail" size="26" maxlength="100" required>'
    . '<div class="note">换绑后需在新邮箱点击确认，并且要重新完成邮箱验证。</div></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="发送换绑确认信"></td></tr></table></form>';
echo '<div class="hr"></div><form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="do" value="kick">'
    . '<input type="submit" class="btn" value="退出所有设备"></form>';
if ((int)$u['verified'] === 0) {
    echo ' <form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="do" value="resend">'
        . '<input type="submit" class="btn" value="重新发送验证邮件"></form>';
}
echo '<div class="note">当前会话 ID：' . e(substr(session_id(), 0, 10)) . '…　存储后端：' . e(KV::backend() === 'redis' ? 'Redis' : 'MySQL（自动降级）') . '</div>';
echo '</td></tr></table>';
box_end();

/* 最近操作 */
$logs = db_rows('SELECT * FROM wc_log WHERE uid=? ORDER BY id DESC LIMIT 12', array($uid));
if ($logs) {
    box_start('最近操作记录', '<a href="' . e(url('notice.php')) . '">站内消息</a>');
    echo '<table class="tb mini"><tr class="hd"><td width="130">时间</td><td width="90">类型</td><td>动作</td><td width="90">IP</td></tr>';
    foreach ($logs as $l) {
        echo '<tr><td>' . e(date('m-d H:i', (int)$l['add_time'])) . '</td><td>' . e($l['type']) . '</td>'
            . '<td>' . e($l['action']) . ($l['detail'] !== '' ? '：' . e(cut_str($l['detail'], 40)) : '') . '</td><td>' . e($l['ip']) . '</td></tr>';
    }
    echo '</table>';
    box_end();
}
page_foot();

/** 头像上传（优先 GD 裁成 256 方形） */
function avatar_upload($uid, $u)
{
    $files = upload_normalize();
    if (!$files) { return '请选择图片文件'; }
    $f = $files[0];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) { return upload_err_msg((int)$f['error']); }
    if (!is_uploaded_file($f['tmp_name'])) { return '非法上传来源'; }
    if ((int)$f['size'] > 2 * 1048576) { return '头像不得超过 2MB'; }
    $ext = ext_of($f['name']);
    if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) { return '头像仅支持 jpg/png/gif/webp'; }
    $mime = sniff_type($f['tmp_name']);
    if (strpos((string)$mime, 'image/') !== 0) { return '文件内容不是真实图片'; }
    dir_ensure(DATA_DIR . '/avatars');
    $name = $uid . '_' . time() . '.' . $ext;
    $dest = DATA_DIR . '/avatars/' . $name;
    $made = false;
    if (function_exists('imagecreatetruecolor')) {
        $src = null;
        if ($ext === 'png') { $src = @imagecreatefrompng($f['tmp_name']); }
        elseif ($ext === 'gif') { $src = @imagecreatefromgif($f['tmp_name']); }
        elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($f['tmp_name']); }
        else { $src = @imagecreatefromjpeg($f['tmp_name']); }
        if ($src) {
            $w = imagesx($src); $h = imagesy($src);
            $side = min($w, $h);
            $dst = imagecreatetruecolor(256, 256);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, 256, 256, $white);
            imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 256, 256, $side, $side);
            $made = @imagejpeg($dst, $dest, 88);
            imagedestroy($src); imagedestroy($dst);
            if ($made) { $name = substr($name, 0, -strlen($ext)) . 'jpg'; @rename($dest, DATA_DIR . '/avatars/' . $name); }
        }
    }
    if (!$made && !@move_uploaded_file($f['tmp_name'], $dest)) { return '保存头像失败'; }
    $old = db_row('SELECT avatar FROM wc_user WHERE id=?', array($uid));
    db_exec('UPDATE wc_user SET avatar=? WHERE id=?', array($name, $uid));
    if ($old && $old['avatar'] !== '' && $old['avatar'] !== $name) { @unlink(DATA_DIR . '/avatars/' . $old['avatar']); }
    log_write('user', '更新头像', $name, $uid, $u['nick']);
    return true;
}
