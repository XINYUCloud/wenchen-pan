<?php
/** 文件管理：全库检索、审核、删除、缩略图重建、访问日志 */
require_once __DIR__ . '/common.php';
require_admin();

$fid = getint('id') > 0 ? getint('id') : postint('id');
$f = $fid > 0 ? db_row('SELECT * FROM wc_file WHERE id=?', array($fid)) : null;
$act = cp_action();

if ($act === 'purge') {
    need_file($f);
    file_purge((int)$f['id']);
    log_write('admin', '彻底删除文件', $f['name'] . ' #' . $f['id'] . ' uid=' . $f['uid'], auth_uid(), admin_nick());
    cp_back('已彻底删除 #' . (int)$f['id'] . ' 并释放 ' . e(format_size($f['size'])));
}
if ($act === 'softdel') {
    need_file($f);
    file_soft_delete(array((int)$f['id']), 'admin');
    log_write('admin', '移入回收站', $f['name'] . ' #' . $f['id'], auth_uid(), admin_nick());
    cp_back('已将 #' . (int)$f['id'] . ' 移入回收站（外链同时关闭）');
}
if ($act === 'restore') {
    need_file($f);
    db_exec('UPDATE wc_file SET del_time=0 WHERE id=?', array((int)$f['id']));
    cp_back('已还原 #' . (int)$f['id']);
}
if ($act === 'rename') {
    need_file($f);
    $name = clean_name(post('name'), $f['name']);
    db_exec('UPDATE wc_file SET name=? WHERE id=?', array($name, (int)$f['id']));
    log_write('admin', '重命名文件', '#' . $f['id'] . ' → ' . $name, auth_uid(), admin_nick());
    cp_back('已重命名为 ' . e($name));
}
if ($act === 'thumb') {
    need_file($f);
    $ok = thumb_make((int)$f['id']);
    cp_back($ok ? '缩略图已重新生成' : '缩略图生成失败：需要 GD 扩展（图片）或正确配置 ffmpeg 路径', !$ok);
}
if ($act === 'flag') {
    need_file($f);
    $reason = mb_substr(clean_name(post('reason'), '内容违规'), 0, 100);
    db_exec('UPDATE wc_share SET status=0 WHERE file_id=?', array((int)$f['id']));
    $owner = db_row('SELECT id,email,nick FROM wc_user WHERE id=?', array((int)$f['uid']));
    if ($owner) {
        notice_add((int)$owner['id'], '文件被管理员处理', '文件「' . $f['name'] . '」(#' . $f['id'] . ') 因「' . $reason . '」被处理，关联外链已关闭。', 'report');
    }
    log_write('admin', '审核处理文件', '#' . $f['id'] . ' ' . $reason, auth_uid(), admin_nick());
    cp_back('已关闭该文件的全部外链，并通知分享者');
}
if ($act === 'batch') {
    $ids = intarr(isset($_POST['id']) ? $_POST['id'] : array());
    $op = post('batch');
    $n = 0;
    foreach ($ids as $i) {
        $row = db_row('SELECT * FROM wc_file WHERE id=?', array($i));
        if (!$row) { continue; }
        if ($op === 'purge') { file_purge($i); } elseif ($op === 'softdel') { file_soft_delete(array($i), 'admin'); }
        elseif ($op === 'restore') { db_exec('UPDATE wc_file SET del_time=0 WHERE id=?', array($i)); }
        elseif ($op === 'thumb') { thumb_make($i); }
        elseif ($op === 'closes') { db_exec('UPDATE wc_share SET status=0 WHERE file_id=?', array($i)); }
        else { continue; }
        $n++;
    }
    log_write('admin', '批量文件操作 ' . $op, '共 ' . $n . ' 项', auth_uid(), admin_nick());
    cp_back('批量操作完成，共处理 ' . $n . ' 个文件');
}

/* ---------- 检索 ---------- */
$kw = get('kw');
$uid = getint('uid');
$kind = get('kind');
$ext = get('ext');
$st = get('st');
$min = get('min');
$max = get('max');
$ord = get('ord', 'id');
$page = max(1, getint('p'));
$size = 30;

$allow = array('id' => 'f.id', 'size' => 'f.size', 'time' => 'f.add_time', 'down' => 'f.`down`', 'view' => 'f.view', 'name' => 'f.name');
$ob = db_order($ord, $allow, 'f.id');

$where = '1';
$args = array();
if ($kw !== '') { $where .= ' AND f.name LIKE ?'; $args[] = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $kw) . '%'; }
if ($uid > 0) { $where .= ' AND f.uid=?'; $args[] = $uid; }
if ($kind !== '' && in_array($kind, array('image', 'video', 'audio', 'doc', 'archive', 'code', 'other'), true)) { $where .= ' AND f.kind=?'; $args[] = $kind; }
if ($ext !== '') { $where .= ' AND f.ext=?'; $args[] = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext)); }
if ($st === 'trash') { $where .= ' AND f.del_time>0'; } elseif ($st === 'normal') { $where .= ' AND f.del_time=0'; }
if ($min !== '') { $where .= ' AND f.size>=' . (int)parse_size($min); }
if ($max !== '') { $where .= ' AND f.size<=' . (int)parse_size($max); }

$from = 'FROM wc_file f LEFT JOIN wc_user u ON u.id=f.uid WHERE ' . $where;
$total = (int)db_one('SELECT COUNT(*) ' . $from, $args);
$sumBytes = (int)db_one('SELECT COALESCE(SUM(f.size),0) ' . $from, $args);
$rows = db_rows('SELECT f.*, u.email, u.nick ' . $from . ' ORDER BY ' . $ob . ' DESC LIMIT ' . (($page - 1) * $size) . ",$size", $args);

cp_header('文件管理', 'files.php');

cp_panel('全库检索', '命中 ' . number_format($total) . ' 个文件，合计 ' . format_size($sumBytes));
echo '<div class="cpFilter"><form method="get" class="inl">'
    . '关键词 <input type="text" name="kw" size="16" value="' . e($kw) . '"> '
    . 'UID <input type="text" name="uid" size="6" value="' . ($uid ?: '') . '"> '
    . '<select name="kind"><option value="">全部类型</option>';
foreach (kind_map() as $k => $v) { echo '<option value="' . $k . '"' . ($kind === $k ? ' selected' : '') . '>' . e(kind_name($k)) . '</option>'; }
echo '</select> 扩展名 <input type="text" name="ext" size="5" value="' . e($ext) . '" placeholder="zip"> '
    . '大小 <input type="text" name="min" size="6" value="' . e($min) . '" placeholder="10M"> ~ <input type="text" name="max" size="6" value="' . e($max) . '" placeholder="1G"> '
    . '<select name="st"><option value="">正常+回收站</option><option value="normal"' . ($st === 'normal' ? ' selected' : '') . '>仅正常</option>'
    . '<option value="trash"' . ($st === 'trash' ? ' selected' : '') . '>仅回收站</option></select> '
    . '<select name="ord">' . sort_options($ord) . '</select> '
    . '<input type="submit" class="btn" value="检索"> <a href="' . e(cp_url('files.php')) . '">重置</a></form></div>';
cp_panel_end();

if ($f) {
    $owner = db_row('SELECT * FROM wc_user WHERE id=?', array((int)$f['uid']));
    $abs = file_abs($f);
    $shares = db_rows('SELECT * FROM wc_share WHERE file_id=? ORDER BY id DESC', array((int)$f['id']));
    $logs = db_rows('SELECT * FROM wc_share_log WHERE code IN (SELECT code FROM wc_share WHERE file_id=?) ORDER BY id DESC LIMIT 20', array((int)$f['id']));
    cp_panel('文件详情 #' . (int)$f['id'], '<a href="' . e(cp_url('files.php')) . '">返回列表</a>');
    echo '<table class="cpTb">';
    echo '<tr class="hd"><td colspan="4">基本信息</td></tr>'
        . '<tr><td width="90">名称</td><td colspan="3"><b>' . e($f['name']) . '</b>　' . e(kind_name($f['kind'])) . ' .' . e($f['ext']) . '</td></tr>'
        . '<tr><td>大小</td><td width="200">' . e(format_size($f['size'])) . '（' . number_format((int)$f['size']) . ' 字节）</td>'
        . '<td width="90">真实类型</td><td>' . e($abs !== '' ? (sniff_type($abs) ?: '未知') : '<span class="danger">实体文件缺失</span>') . '</td></tr>'
        . '<tr><td>SHA1</td><td class="mono">' . e($f['sha1']) . '</td><td>MD5</td><td class="mono">' . e($abs !== '' ? md5_file($abs) : '-') . '</td></tr>'
        . '<tr><td>存储路径</td><td class="mono" colspan="3">' . e($f['path']) . '　' . ($abs !== '' ? '<a href="' . e(url('api.php?do=download&id=' . (int)$f['id'])) . '" target="_blank">下载查看</a>' : '')
        . '　引用数 ' . (int)db_one('SELECT COUNT(*) FROM wc_file WHERE sha1=? AND path=?', array($f['sha1'], $f['path'])) . '</td></tr>';
    echo '<tr class="hd"><td colspan="4">归属与状态</td></tr>'
        . '<tr><td>所属用户</td><td>' . e($owner ? $owner['email'] : '已删除') . '（UID ' . (int)$f['uid'] . '）</td>'
        . '<td>上传时间</td><td>' . e(date('Y-m-d H:i:s', (int)$f['add_time'])) . '</td></tr>'
        . '<tr><td>状态</td><td>' . ((int)$f['del_time'] ? '<span class="danger">回收站中 ' . e(date('Y-m-d H:i', (int)$f['del_time'])) . '</span>' : '<span class="ok">正常</span>') . '</td>'
        . '<td>访问</td><td>预览 ' . (int)$f['view'] . '　下载 ' . (int)$f['down'] . '　流量 ' . e(format_size($f['traffic'])) . '</td></tr>'
        . '<tr><td>标签/说明</td><td colspan="3">' . e($f['tags']) . ' ' . e($f['note']) . '</td></tr>';
    echo '</table>';

    echo '<form method="post" action="' . e(cp_url('files.php')) . '">' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int)$f['id'] . '">'
        . '<table class="cpTb cpForm" style="margin-top:8px"><tr class="hd"><td width="110">管理操作</td><td>参数</td><td width="120">&nbsp;</td></tr>'
        . '<tr><th>重命名</th><td><input type="text" name="name" size="40" value="' . e($f['name']) . '"></td>'
        . '<td><input type="submit" class="btn" value="保存" formaction="?do=rename"></td></tr>'
        . '<tr><th>审核处理</th><td><input type="text" name="reason" size="30" placeholder="违规理由"> <span class="mini">关闭全部外链并通知分享者</span></td>'
        . '<td><input type="submit" class="btn" value="处理" formaction="?do=flag"></td></tr>'
        . '<tr><th>其他</th><td>' . cp_act('thumb', '重建缩略图', array('id' => (int)$f['id'])) . '　'
        . ((int)$f['del_time'] ? cp_act('restore', '还原文件', array('id' => (int)$f['id'])) : cp_act('softdel', '移入回收站', array('id' => (int)$f['id']), '移入回收站并关闭外链？')) . '</td>'
        . '<td>' . cp_act('purge', '彻底删除', array('id' => (int)$f['id']), '彻底删除实体与记录，不可恢复！', 'danger') . '</td></tr>'
        . '</table></form>';

    if ($shares) {
        echo '<table class="cpTb" style="margin-top:8px"><tr class="hd"><td width="90">短码</td><td width="60">状态</td><td width="60">密码</td>'
            . '<td width="60">直链</td><td width="60">公开</td><td width="60">下载</td><td width="100">到期</td><td width="100">创建</td></tr>';
        foreach ($shares as $x) {
            echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td><a href="' . e(share_page_url($x['code'])) . '" target="_blank">' . e($x['code']) . '</a></td>'
                . '<td>' . ((int)$x['status'] ? '有效' : '关闭') . '</td><td>' . ($x['pwd'] ? '有' : '-') . '</td>'
                . '<td>' . ($x['direct'] ? '是' : '-') . '</td><td>' . ($x['pub'] ? '是' : '-') . '</td>'
                . '<td>' . (int)$x['down'] . '</td><td>' . e($x['expire'] ? date('y-m-d', (int)$x['expire']) : '永久') . '</td>'
                . '<td>' . e(date('y-m-d', (int)$x['add_time'])) . '</td></tr>';
        }
        echo '</table>';
    }
    if ($logs) {
        echo '<table class="cpTb" style="margin-top:8px"><tr class="hd"><td width="130">时间</td><td width="120">IP</td><td width="90">结果</td>'
            . '<td width="90" align="right">字节</td><td>UA</td></tr>';
        foreach ($logs as $x) {
            echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td>' . e(date('m-d H:i:s', (int)$x['add_time'])) . '</td><td>' . e($x['ip']) . '</td>'
                . '<td>' . e($x['result']) . '</td><td align="right">' . e(format_size($x['bytes'])) . '</td>'
                . '<td class="mini">' . e(cut_str($x['ua'], 60)) . '</td></tr>';
        }
        echo '</table>';
    }
    cp_panel_end();
}

if (!$rows) {
    cp_panel('文件列表');
    echo '<div class="empty">没有符合条件的文件。</div>';
    cp_panel_end();
} else {
    echo '<form method="post" action="' . e(cp_url('files.php')) . '">' . csrf_field()
        . '<input type="hidden" name="do" value="batch">';
    cp_panel('文件列表（本页 ' . count($rows) . '）',
        '<select name="batch">'
        . '<option value="softdel">批量移入回收站</option><option value="purge">批量彻底删除</option>'
        . '<option value="restore">批量还原</option><option value="closes">批量关闭外链</option>'
        . '<option value="thumb">批量重建缩略图</option></select> '
        . '<input type="submit" class="btn" value="执行" onclick="return confirm(\'确认对勾选文件执行该批量操作？\')">');
    echo '<table class="cpTb"><tr class="hd"><td width="24"><input type="checkbox" onclick="wcCheckAll(this,\'id\')"></td>'
        . '<td width="50">ID</td><td width="130">用户</td><td>名称</td><td width="60">类型</td>'
        . '<td width="80" align="right">大小</td><td width="50" align="center">下载</td><td width="90">时间</td><td width="150">操作</td></tr>';
    foreach ($rows as $x) {
        echo '<tr class="r' . ((int)$x['id'] % 2) . '">'
            . '<td><input type="checkbox" name="id[]" value="' . (int)$x['id'] . '"></td>'
            . '<td>' . (int)$x['id'] . '</td>'
            . '<td><a href="' . e(cp_url('users.php', array('uid' => (int)$x['uid']))) . '">' . e(cut_str($x['email'], 18)) . '</a></td>'
            . '<td><a href="?id=' . (int)$x['id'] . '">' . e(cut_str($x['name'], 40)) . '</a>'
            . ((int)$x['del_time'] ? ' <span class="danger">回收站</span>' : '')
            . ($x['thumb'] ? ' <span class="miniTag">缩略</span>' : '') . '</td>'
            . '<td>' . e(kind_name($x['kind'])) . '</td>'
            . '<td align="right">' . e(format_size($x['size'])) . '</td>'
            . '<td align="center">' . (int)$x['down'] . '</td>'
            . '<td>' . e(date('y-m-d', (int)$x['add_time'])) . '</td>'
            . '<td class="ops">' . cp_act('softdel', '回收', array('id' => (int)$x['id'])) . ' '
            . cp_act('purge', '删除', array('id' => (int)$x['id']), '彻底删除该文件？', 'danger') . ' '
            . cp_act('thumb', '缩略图', array('id' => (int)$x['id'])) . '</td></tr>';
    }
    echo '</table>';
    echo cp_page($total, $page, $size, cp_url('files.php', array('kw' => $kw, 'uid' => $uid, 'kind' => $kind, 'ext' => $ext, 'st' => $st, 'ord' => $ord, 'min' => $min, 'max' => $max)));
    cp_panel_end();
    echo '</form>';
}

cp_foot();

function need_file($f)
{
    if (!$f) { cp_back('文件不存在或已被删除', true); }
}

function sort_options($cur)
{
    $o = array('id' => '按编号（最新）', 'time' => '按上传时间', 'size' => '按文件大小', 'down' => '按下载次数', 'view' => '按预览次数', 'name' => '按名称');
    $h = '';
    foreach ($o as $k => $v) { $h .= '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . $v . '</option>'; }
    return $h;
}
