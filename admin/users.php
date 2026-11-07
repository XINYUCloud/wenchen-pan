<?php
/** 用户管理：搜索、封禁、配额、重置密码、角色、删除、发站内信 */
require_once __DIR__ . '/common.php';
require_admin();

$uid = getint('uid') > 0 ? getint('uid') : postint('uid');
$act = cp_action();
$u = $uid > 0 ? db_row('SELECT * FROM wc_user WHERE id=?', array($uid)) : null;

switch ($act) {
    case 'ban':
        need_user($u);
        $reason = mb_substr(clean_name(rqstr('reason'), '违反服务条款'), 0, 190);
        db_exec('UPDATE wc_user SET status=2, ban_reason=? WHERE id=?', array($reason, $uid));
        kick_all($uid);
        notice_add($uid, '账号已被封禁', '原因：' . $reason . "\n如有疑问请联系站长申诉。", 'system');
        log_write('admin', '封禁账号', $u['email'] . ' 原因:' . $reason, auth_uid(), admin_name());
        cp_back('已封禁 ' . e($u['email']) . '，原因：' . e($reason));

    case 'unban':
        need_user($u);
        db_exec('UPDATE wc_user SET status=?, ban_reason="" WHERE id=?', array((int)$u['verified'] === 1 ? 1 : 0, $uid));
        notice_add($uid, '账号已解封', '你的账号限制已解除，请遵守服务条款。', 'system');
        log_write('admin', '解封账号', $u['email'], auth_uid(), admin_name());
        cp_back('已解封 ' . e($u['email']));

    case 'quota':
        need_user($u);
        need_super_role();
        $q = is_numeric(post('quota')) ? (int)(post('quota') * 1073741824) : parse_size(post('quota'));
        if ($q <= 0) { cp_back('配额填写不正确（支持 GB/MB 或数字 GB）', true); }
        db_exec('UPDATE wc_user SET quota=? WHERE id=?', array($q, $uid));
        log_write('admin', '调整配额', $u['email'] . ' → ' . format_size($q), auth_uid(), admin_name());
        cp_back('已将 ' . e($u['email']) . ' 的配额设为 ' . e(format_size($q)));

    case 'role':
        need_user($u);
        need_super_role();
        $role = post('role');
        if (!in_array($role, array('user', 'admin', 'super'), true)) { cp_back('角色不正确', true); }
        if ((int)$u['id'] === auth_uid() && $role !== 'super') { cp_back('不能取消自己的超级管理员权限', true); }
        db_exec('UPDATE wc_user SET role=? WHERE id=?', array($role, $uid));
        log_write('admin', '调整角色', $u['email'] . ' → ' . $role, auth_uid(), admin_name());
        cp_back('角色已更新为 ' . e($role));

    case 'ver':
        need_user($u);
        $v = (int)$u['verified'] === 1 ? 0 : 1;
        db_exec('UPDATE wc_user SET verified=?, status=IF(status=2,2,?) WHERE id=?', array($v, $v, $uid));
        cp_back($v ? '已手工标记邮箱为已验证' : '已取消验证状态');

    case 'resend':
        need_user($u);
        $r = user_send_verify($uid, $u['email']);
        cp_back($r ? '已向 ' . e($u['email']) . ' 发送验证邮件' : '发送失败：' . e((string)mail_last_error()), !$r);

    case 'newpass':
        need_user($u);
        $np = substr(str_shuffle('abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8) . rand(10, 99);
        db_exec('UPDATE wc_user SET pass=? WHERE id=?', array(password_hash($np, PASSWORD_DEFAULT), $uid));
        kick_all($uid);
        log_write('admin', '重置用户密码', $u['email'], auth_uid(), admin_name());
        flash('已重置 ' . e($u['email']) . ' 的密码为：<b>' . e($np) . '</b>（请立即转告用户，本提示只出现一次）', 'ok');
        go('users.php?uid=' . $uid);

    case 'notice':
        $title = mb_substr(clean_name(post('title'), '管理员通知'), 0, 100);
        $content = mb_substr((string)post('content'), 0, 2000);
        if ($content === '') { cp_back('通知内容不能为空', true); }
        if (post('all') === '1') {
            require_super();
            $ids = db_col('SELECT id FROM wc_user WHERE status<>2');
            foreach ($ids as $i) { notice_add((int)$i, $title, $content, 'system'); }
            log_write('admin', '全站广播', $title, auth_uid(), admin_name());
            cp_back('已向 ' . count($ids) . ' 个正常账号广播站内信');
        }
        need_user($u);
        notice_add($uid, $title, $content, 'system');
        log_write('admin', '发送站内信', $u['email'] . '：' . $title, auth_uid(), admin_name());
        cp_back('已发送站内信给 ' . e($u['email']));

    case 'mail':
        need_user($u);
        $subj = mb_substr(clean_name(post('subject'), ''), 0, 150);
        $body = (string)post('body');
        if ($subj === '') { cp_back('主题不能为空', true); }
        $r = mail_send($u['email'], $subj, mail_layout($subj, nl2p($body)));
        log_write('admin', '发送邮件给用户', $u['email'] . '：' . $subj, auth_uid(), admin_name());
        cp_back($r === true ? '邮件已发出（或已记录到发信日志）' : '发送失败：' . e((string)$r), $r !== true);

    case 'del':
        need_user($u);
        need_super_role();
        if ((int)$u['id'] === auth_uid()) { cp_back('不能删除自己', true); }
        $ids = db_col('SELECT id FROM wc_file WHERE uid=?', array($uid));
        foreach ($ids as $i) { file_purge((int)$i); }
        db_exec('DELETE FROM wc_folder WHERE uid=?', array($uid));
        db_exec('DELETE FROM wc_share WHERE uid=?', array($uid));
        db_exec('DELETE FROM wc_notice WHERE uid=?', array($uid));
        db_exec('DELETE FROM wc_user WHERE id=?', array($uid));
        kick_all($uid);
        log_write('admin', '删除账号', $u['email'] . ' 及 ' . count($ids) . ' 个文件', auth_uid(), admin_name());
        cp_back('已删除 ' . e($u['email']) . ' 与其 ' . count($ids) . ' 个文件');
}

/* ---------- 列表 ---------- */
$kw = get('kw');
$f = get('f');
$page = max(1, getint('p'));
$size = 25;
$where = '1';
$args = array();
if ($kw !== '') {
    $where .= ' AND (email LIKE ? OR nick LIKE ?)';
    $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $kw) . '%';
    $args = array($like, $like);
}
if ($f === 'banned') { $where .= ' AND status=2'; }
elseif ($f === 'nover') { $where .= ' AND verified=0'; }
elseif ($f === 'admin') { $where .= " AND role<>'user'"; }
elseif ($f === 'big') { $where .= ' AND used>' . parse_size('1 GB'); }

$total = (int)db_one("SELECT COUNT(*) FROM wc_user WHERE $where", $args);
$rows = db_rows("SELECT * FROM wc_user WHERE $where ORDER BY id DESC LIMIT " . (($page - 1) * $size) . ",$size", $args);

cp_header('用户管理', 'users.php');

cp_panel('筛选', '共 ' . number_format(db_count('wc_user')) . ' 个账号');
echo '<div class="cpFilter"><form method="get" class="inl">'
    . '<input type="text" name="kw" size="24" value="' . e($kw) . '" placeholder="邮箱或昵称"> '
    . '<select name="f">'
    . '<option value="">全部账号</option>'
    . '<option value="nover"' . ($f === 'nover' ? ' selected' : '') . '>未验证邮箱</option>'
    . '<option value="banned"' . ($f === 'banned' ? ' selected' : '') . '>已封禁</option>'
    . '<option value="admin"' . ($f === 'admin' ? ' selected' : '') . '>管理员</option>'
    . '<option value="big"' . ($f === 'big' ? ' selected' : '') . '>占用超 1GB</option>'
    . '</select> <input type="submit" class="btn" value="筛选"> '
    . '<a href="' . e(cp_url('users.php')) . '">重置</a></form>'
    . '<form method="post" class="inl" action="' . e(cp_url('users.php')) . '">' . csrf_field()
    . '<input type="hidden" name="do" value="notice"><input type="hidden" name="uid" value="0">'
    . '<input type="hidden" name="all" value="1"><input type="hidden" name="title" value="全站公告通知">'
    . '<input type="hidden" name="content" value="">'
    . '　<a href="' . e(cp_url('users.php', array('broadcast' => 1))) . '">全站广播站内信</a></form></div>';
cp_panel_end();

if ($u) {
    cp_panel('用户详情：' . e($u['email']), '<a href="' . e(cp_url('users.php')) . '">返回列表</a>');
    render_detail($u);
    cp_panel_end();
}

if (isset($_GET['uid']) && !$u) { flash('该用户不存在或已被删除。', 'err'); }

if (isset($_GET['broadcast'])) {
    cp_panel('全站广播站内信（仅超级管理员）', '<a href="' . e(cp_url('users.php')) . '">取消</a>');
    echo '<form method="post" action="' . e(cp_url('users.php')) . '">' . csrf_field()
        . '<input type="hidden" name="do" value="notice"><input type="hidden" name="all" value="1"><input type="hidden" name="uid" value="0">'
        . '<table class="cpTb cpForm">'
        . '<tr><th>标题</th><td><input type="text" name="title" size="40" maxlength="100" required></td></tr>'
        . '<tr><th>正文</th><td><textarea name="content" rows="6" class="txtArea" required></textarea></td></tr>'
        . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="发送给全部正常账号"></td></tr></table></form>';
    cp_panel_end();
}

if (!$rows) {
    cp_panel('账号列表');
    echo '<div class="empty">没有符合条件的账号。</div>';
    cp_panel_end();
} else {
    cp_panel('账号列表', '本页 ' . count($rows) . ' 条');
    echo '<table class="cpTb"><tr class="hd"><td width="40">ID</td><td width="180">邮箱 / 昵称</td><td width="90" align="right">已用</td>'
        . '<td width="80" align="right">配额</td><td width="70">状态</td><td width="60">角色</td><td width="100">最后活跃</td>'
        . '<td>操作</td></tr>';
    foreach ($rows as $x) {
        $st = (int)$x['status'] === 2 ? '<span class="danger">封禁</span>' : ((int)$x['verified'] === 1 ? '<span class="ok">正常</span>' : '待验证');
        echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td>' . (int)$x['id'] . '</td>'
            . '<td>' . e($x['email']) . '<br><span class="mini">' . e(cut_str($x['nick'], 14)) . '</span></td>'
            . '<td align="right">' . e(format_size($x['used'])) . '</td><td align="right">' . e(format_size($x['quota'])) . '</td>'
            . '<td>' . $st . '</td><td>' . e($x['role']) . '</td>'
            . '<td>' . e($x['last_time'] ? date('y-m-d H:i', (int)$x['last_time']) : '-') . '</td>'
            . '<td class="ops">'
            . '<a href="?uid=' . (int)$x['id'] . '">详情</a> '
            . ((int)$x['status'] === 2
                ? cp_act('unban', '解封', array('uid' => (int)$x['id']))
                : '<a href="?uid=' . (int)$x['id'] . '">封禁</a>') . ' '
            . '<a href="?uid=' . (int)$x['id'] . '">改配额</a> '
            . ((int)$x['verified'] === 0 ? cp_act('ver', '标记已验证', array('uid' => (int)$x['id'])) . ' ' : '')
            . ((int)$x['id'] !== auth_uid() ? '<a href="?uid=' . (int)$x['id'] . '">删除</a>' : '')
            . '</td></tr>';
    }
    echo '</table>';
    echo cp_page($total, $page, $size, cp_url('users.php', array('kw' => $kw, 'f' => $f)));
    cp_panel_end();
}

cp_foot();

/* ---------- 辅助 ---------- */
function need_user($u)
{
    if (!$u) { cp_back('用户不存在', true); }
}

function need_super_role()
{
    if (auth_role() !== 'super') { cp_back('该操作仅限超级管理员', true); }
}

function render_detail($u)
{
    $uid = (int)$u['id'];
    $files = db_rows('SELECT * FROM wc_file WHERE uid=? ORDER BY add_time DESC LIMIT 8', array($uid));
    $shares = db_rows('SELECT * FROM wc_share WHERE uid=? ORDER BY id DESC LIMIT 6', array($uid));
    echo '<table class="cpTb"><tr class="hd"><td colspan="4">基本信息</td></tr>'
        . '<tr><td width="90">ID</td><td width="140">' . (int)$u['id'] . '</td><td width="90">邮箱</td><td>' . e($u['email'])
        . ' ' . ((int)$u['verified'] ? '<span class="ok">已验证</span>' : '<span class="danger">未验证</span>') . '</td></tr>'
        . '<tr><td>昵称</td><td>' . e($u['nick']) . '</td><td>注册 IP</td><td>' . e($u['reg_ip']) . '　' . e(date('Y-m-d H:i', (int)$u['reg_time'])) . '</td></tr>'
        . '<tr><td>已用</td><td>' . e(format_size($u['used'])) . '</td><td>配额</td><td>' . e(format_size($u['quota']))
        . '　（超管可调）</td></tr>'
        . '<tr><td>状态</td><td>' . e($u['status']) . '</td><td>封禁原因</td><td>' . e($u['ban_reason']) . '</td></tr>'
        . '<tr><td>角色</td><td>' . e($u['role']) . '</td><td>登录次数</td><td>' . (int)$u['login_cnt'] . '　最后 ' . e($u['last_time'] ? date('Y-m-d H:i', (int)$u['last_time']) : '-') . ' ' . e($u['last_ip']) . '</td></tr>'
        . '</table>';

    echo '<form method="post" action="' . e(cp_url('users.php')) . '" class="inl">' . csrf_field()
        . '<input type="hidden" name="uid" value="' . $uid . '">'
        . '<table class="cpTb cpForm"><tr class="hd"><td width="110">操作</td><td>参数</td><td width="90">&nbsp;</td></tr>';
    echo '<tr><th>封禁 / 解封</th><td><input type="text" name="reason" size="36" placeholder="封禁原因（会写入站内信）">　'
        . cp_act('ban', '封禁', array('uid' => $uid), '确认封禁该账号？所有设备会被强制下线。')
        . '　' . cp_act('unban', '解封', array('uid' => $uid)) . '</td><td></td></tr>';
    echo '<tr><th>调整配额</th><td><input type="text" name="quota" size="12" placeholder="如 5 GB"> <span class="mini">支持 GB / MB 文本或纯数字（按 GB）</span></td>'
        . '<td><input type="submit" class="btn" value="保存" formmethod="post" formaction="?do=quota"></td></tr>';
    echo '<tr><th>角色</th><td><select name="role"><option value="user">普通用户</option><option value="admin">管理员</option><option value="super">超级管理员</option></select></td>'
        . '<td><input type="submit" class="btn" value="保存" formmethod="post" formaction="?do=role"></td></tr>';
    echo '<tr><th>发站内信</th><td><input type="text" name="title" size="24" placeholder="标题"><br><textarea name="content" rows="3" cols="60" placeholder="正文"></textarea></td>'
        . '<td><input type="submit" class="btn" value="发送" formmethod="post" formaction="?do=notice"></td></tr>';
    echo '<tr><th>发邮件</th><td><input type="text" name="subject" size="24" placeholder="邮件主题"><br><textarea name="body" rows="3" cols="60" placeholder="邮件正文"></textarea></td>'
        . '<td><input type="submit" class="btn" value="发送" formmethod="post" formaction="?do=mail"></td></tr>';
    echo '<tr><th>危险操作</th><td>'
        . cp_act('newpass', '重置密码', array('uid' => $uid), '生成新随机密码并强制下线？') . '　'
        . cp_act('resend', '重发验证邮件', array('uid' => $uid)) . '　'
        . cp_act('ver', '切换验证状态', array('uid' => $uid)) . '</td>'
        . '<td>' . ((int)$u['id'] !== auth_uid() ? cp_act('del', '删除账号', array('uid' => $uid), '将连同其全部文件一起彻底删除，不可恢复！', 'danger') : '') . '</td></tr>';
    echo '</table></form>';

    echo '<div class="clr"></div>';
    echo '<table class="cpTb" style="margin-top:8px"><tr class="hd"><td width="150">最近文件</td><td>名称</td><td width="90" align="right">大小</td><td width="100">时间</td><td width="60">下载</td></tr>';
    if (!$files) { echo '<tr><td colspan="5" class="mini">还没有上传文件</td></tr>'; }
    foreach ($files as $x) {
        echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td>#' . (int)$x['id'] . '</td><td>' . e(cut_str($x['name'], 46))
            . ((int)$x['del_time'] ? ' <span class="danger">回收站</span>' : '') . '</td>'
            . '<td align="right">' . e(format_size($x['size'])) . '</td><td>' . e(date('y-m-d H:i', (int)$x['add_time'])) . '</td><td>' . (int)$x['down'] . '</td></tr>';
    }
    echo '</table>';

    if ($shares) {
        echo '<table class="cpTb" style="margin-top:6px"><tr class="hd"><td width="90">短码</td><td>标题</td><td width="70">状态</td><td width="60">下载</td><td width="100">到期</td></tr>';
        foreach ($shares as $x) {
            echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td><a href="' . e(share_page_url($x['code'])) . '" target="_blank">' . e($x['code']) . '</a></td>'
                . '<td>' . e(cut_str($x['title'], 40)) . '</td><td>' . ((int)$x['status'] ? '有效' : '关闭') . '</td>'
                . '<td>' . (int)$x['down'] . '</td><td>' . e($x['expire'] ? date('y-m-d', (int)$x['expire']) : '永久') . '</td></tr>';
        }
        echo '</table>';
    }
}
