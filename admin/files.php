<?php
/** 文件管理：全库检索、审核、删除、缩略图重建、访问日志 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$fid = wc_getint('id') > 0 ? wc_getint('id') : wc_postint('id');
$f = $fid > 0 ? wc_db_row('SELECT * FROM wc_file WHERE id=?', array($fid)) : null;
$act = cp_action();

if ($act === 'purge') {
    wc_need_file($f);
    wc_file_purge((int)$f['id']);
    wc_log_write('admin', '彻底删除文件', $f['name'] . ' #' . $f['id'] . ' uid=' . $f['uid'], wc_auth_uid(), wc_admin_nick());
    cp_back('已彻底删除 #' . (int)$f['id'] . ' 并释放 ' . wc_e(wc_format_size($f['size'])));
}
if ($act === 'softdel') {
    wc_need_file($f);
    wc_file_soft_delete(array((int)$f['id']), 'admin');
    wc_log_write('admin', '移入回收站', $f['name'] . ' #' . $f['id'], wc_auth_uid(), wc_admin_nick());
    cp_back('已将 #' . (int)$f['id'] . ' 移入回收站（外链同时关闭）');
}
if ($act === 'restore') {
    wc_need_file($f);
    wc_db_exec('UPDATE wc_file SET del_time=0 WHERE id=?', array((int)$f['id']));
    cp_back('已还原 #' . (int)$f['id']);
}
if ($act === 'rename') {
    wc_need_file($f);
    $name = wc_clean_name(wc_post('name'), $f['name']);
    wc_db_exec('UPDATE wc_file SET name=? WHERE id=?', array($name, (int)$f['id']));
    wc_log_write('admin', '重命名文件', '#' . $f['id'] . ' → ' . $name, wc_auth_uid(), wc_admin_nick());
    cp_back('已重命名为 ' . wc_e($name));
}
if ($act === 'thumb') {
    wc_need_file($f);
    $ok = wc_thumb_make((int)$f['id']);
    cp_back($ok ? '缩略图已重新生成' : '缩略图生成失败：需要 GD 扩展（图片）或正确配置 ffmpeg 路径', !$ok);
}
if ($act === 'flag') {
    wc_need_file($f);
    $reason = mb_substr(wc_clean_name(wc_post('reason'), '内容违规'), 0, 100);
    wc_db_exec('UPDATE wc_share SET status=0 WHERE file_id=?', array((int)$f['id']));
    $owner = wc_db_row('SELECT id,email,nick FROM wc_user WHERE id=?', array((int)$f['uid']));
    if ($owner) {
        wc_notice_add((int)$owner['id'], '文件被管理员处理', '文件「' . $f['name'] . '」(#' . $f['id'] . ') 因「' . $reason . '」被处理，关联外链已关闭。', 'report');
    }
    wc_log_write('admin', '审核处理文件', '#' . $f['id'] . ' ' . $reason, wc_auth_uid(), wc_admin_nick());
    cp_back('已关闭该文件的全部外链，并通知分享者');
}
if ($act === 'batch') {
    $ids = wc_intarr(isset($_POST['id']) ? $_POST['id'] : array());
    $op = wc_post('batch');
    $n = 0;
    foreach ($ids as $i) {
        $row = wc_db_row('SELECT * FROM wc_file WHERE id=?', array($i));
        if (!$row) { continue; }
        if ($op === 'purge') { wc_file_purge($i); } elseif ($op === 'softdel') { wc_file_soft_delete(array($i), 'admin'); }
        elseif ($op === 'restore') { wc_db_exec('UPDATE wc_file SET del_time=0 WHERE id=?', array($i)); }
        elseif ($op === 'thumb') { wc_thumb_make($i); }
        elseif ($op === 'closes') { wc_db_exec('UPDATE wc_share SET status=0 WHERE file_id=?', array($i)); }
        else { continue; }
        $n++;
    }
    wc_log_write('admin', '批量文件操作 ' . $op, '共 ' . $n . ' 项', wc_auth_uid(), wc_admin_nick());
    cp_back('批量操作完成，共处理 ' . $n . ' 个文件');
}

/* ---------- 检索 ---------- */
$kw = wc_get('kw');
$uid = wc_getint('uid');
$kind = wc_get('kind');
$ext = wc_get('ext');
$st = wc_get('st');
$min = wc_get('min');
$max = wc_get('max');
$ord = wc_get('ord', 'id');
$page = max(1, wc_getint('p'));
$size = 30;

$allow = array('id' => 'f.id', 'size' => 'f.size', 'time' => 'f.add_time', 'down' => 'f.`down`', 'view' => 'f.view', 'name' => 'f.name');
$ob = wc_db_order($ord, $allow, 'f.id');

$where = '1';
$args = array();
if ($kw !== '') { $where .= ' AND f.name LIKE ?'; $args[] = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $kw) . '%'; }
if ($uid > 0) { $where .= ' AND f.uid=?'; $args[] = $uid; }
if ($kind !== '' && in_array($kind, array('image', 'video', 'audio', 'doc', 'archive', 'code', 'other'), true)) { $where .= ' AND f.kind=?'; $args[] = $kind; }
if ($ext !== '') { $where .= ' AND f.ext=?'; $args[] = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext)); }
if ($st === 'trash') { $where .= ' AND f.del_time>0'; } elseif ($st === 'normal') { $where .= ' AND f.del_time=0'; }
if ($min !== '') { $where .= ' AND f.size>=' . (int)wc_parse_size($min); }
if ($max !== '') { $where .= ' AND f.size<=' . (int)wc_parse_size($max); }

$from = 'FROM wc_file f LEFT JOIN wc_user u ON u.id=f.uid WHERE ' . $where;
$total = (int)wc_db_one('SELECT COUNT(*) ' . $from, $args);
$sumBytes = (int)wc_db_one('SELECT COALESCE(SUM(f.size),0) ' . $from, $args);
$rows = wc_db_rows('SELECT f.*, u.email, u.nick ' . $from . ' ORDER BY ' . $ob . ' DESC LIMIT ' . (($page - 1) * $size) . ",$size", $args);

cp_header('文件管理', 'files.php');

cp_panel('全库检索', '命中 ' . number_format($total) . ' 个文件，合计 ' . wc_format_size($sumBytes));
echo '<div class="cpFilter"><form method="get" class="inl">'
    . '关键词 <input type="text" name="kw" size="16" value="' . wc_e($kw) . '"> '
    . 'UID <input type="text" name="uid" size="6" value="' . ($uid ?: '') . '"> '
    . '<select name="kind"><option value="">全部类型</option>';
foreach (wc_kind_map() as $k => $v) { echo '<option value="' . $k . '"' . ($kind === $k ? ' selected' : '') . '>' . wc_e(wc_kind_name($k)) . '</option>'; }
echo '</select> 扩展名 <input type="text" name="ext" size="5" value="' . wc_e($ext) . '" placeholder="zip"> '
    . '大小 <input type="text" name="min" size="6" value="' . wc_e($min) . '" placeholder="10M"> ~ <input type="text" name="max" size="6" value="' . wc_e($max) . '" placeholder="1G"> '
    . '<select name="st"><option value="">正常+回收站</option><option value="normal"' . ($st === 'normal' ? ' selected' : '') . '>仅正常</option>'
    . '<option value="trash"' . ($st === 'trash' ? ' selected' : '') . '>仅回收站</option></select> '
    . '<select name="ord">' . wc_sort_options($ord) . '</select> '
    . '<input type="submit" class="btn" value="检索"> <a href="' . wc_e(cp_url('files.php')) . '">重置</a></form></div>';
cp_panel_end();

if ($f) {
    $owner = wc_db_row('SELECT * FROM wc_user WHERE id=?', array((int)$f['uid']));
    $abs = wc_file_abs($f);
    $shares = wc_db_rows('SELECT * FROM wc_share WHERE file_id=? ORDER BY id DESC', array((int)$f['id']));
    $logs = wc_db_rows('SELECT * FROM wc_share_log WHERE code IN (SELECT code FROM wc_share WHERE file_id=?) ORDER BY id DESC LIMIT 20', array((int)$f['id']));
    cp_panel('文件详情 #' . (int)$f['id'], '<a href="' . wc_e(cp_url('files.php')) . '">返回列表</a>');
    echo '<table class="cpTb">';
    echo '<tr class="hd"><td colspan="4">基本信息</td></tr>'
        . '<tr><td width="90">名称</td><td colspan="3"><b>' . wc_e($f['name']) . '</b>　' . wc_e(wc_kind_name($f['kind'])) . ' .' . wc_e($f['ext']) . '</td></tr>'
        . '<tr><td>大小</td><td width="200">' . wc_e(wc_format_size($f['size'])) . '（' . number_format((int)$f['size']) . ' 字节）</td>'
        . '<td width="90">真实类型</td><td>' . wc_e($abs !== '' ? (wc_sniff_type($abs) ?: '未知') : '<span class="danger">实体文件缺失</span>') . '</td></tr>'
        . '<tr><td>SHA1</td><td class="mono">' . wc_e($f['sha1']) . '</td><td>MD5</td><td class="mono">' . wc_e($abs !== '' ? md5_file($abs) : '-') . '</td></tr>'
        . '<tr><td>存储路径</td><td class="mono" colspan="3">' . wc_e($f['path']) . '　' . ($abs !== '' ? '<a href="' . wc_e(wc_url('api.php?do=download&id=' . (int)$f['id'])) . '" target="_blank">下载查看</a>' : '')
        . '　引用数 ' . (int)wc_db_one('SELECT COUNT(*) FROM wc_file WHERE sha1=? AND path=?', array($f['sha1'], $f['path'])) . '</td></tr>';
    echo '<tr class="hd"><td colspan="4">归属与状态</td></tr>'
        . '<tr><td>所属用户</td><td>' . wc_e($owner ? $owner['email'] : '已删除') . '（UID ' . (int)$f['uid'] . '）</td>'
        . '<td>上传时间</td><td>' . wc_e(date('Y-m-d H:i:s', (int)$f['add_time'])) . '</td></tr>'
        . '<tr><td>状态</td><td>' . ((int)$f['del_time'] ? '<span class="danger">回收站中 ' . wc_e(date('Y-m-d H:i', (int)$f['del_time'])) . '</span>' : '<span class="ok">正常</span>') . '</td>'
        . '<td>访问</td><td>预览 ' . (int)$f['view'] . '　下载 ' . (int)$f['down'] . '　流量 ' . wc_e(wc_format_size($f['traffic'])) . '</td></tr>'
        . '<tr><td>标签/说明</td><td colspan="3">' . wc_e($f['tags']) . ' ' . wc_e($f['note']) . '</td></tr>';
    echo '</table>';

    echo '<form method="post" action="' . wc_e(cp_url('files.php')) . '">' . wc_csrf_field()
        . '<input type="hidden" name="id" value="' . (int)$f['id'] . '">'
        . '<table class="cpTb cpForm" style="margin-top:8px"><tr class="hd"><td width="110">管理操作</td><td>参数</td><td width="120">&nbsp;</td></tr>'
        . '<tr><th>重命名</th><td><input type="text" name="name" size="40" value="' . wc_e($f['name']) . '"></td>'
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
            echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td><a href="' . wc_e(wc_share_page_url($x['code'])) . '" target="_blank">' . wc_e($x['code']) . '</a></td>'
                . '<td>' . ((int)$x['status'] ? '有效' : '关闭') . '</td><td>' . ($x['pwd'] ? '有' : '-') . '</td>'
                . '<td>' . ($x['direct'] ? '是' : '-') . '</td><td>' . ($x['pub'] ? '是' : '-') . '</td>'
                . '<td>' . (int)$x['down'] . '</td><td>' . wc_e($x['expire'] ? date('y-m-d', (int)$x['expire']) : '永久') . '</td>'
                . '<td>' . wc_e(date('y-m-d', (int)$x['add_time'])) . '</td></tr>';
        }
        echo '</table>';
    }
    if ($logs) {
        echo '<table class="cpTb" style="margin-top:8px"><tr class="hd"><td width="130">时间</td><td width="120">IP</td><td width="90">结果</td>'
            . '<td width="90" align="right">字节</td><td>UA</td></tr>';
        foreach ($logs as $x) {
            echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td>' . wc_e(date('m-d H:i:s', (int)$x['add_time'])) . '</td><td>' . wc_e($x['ip']) . '</td>'
                . '<td>' . wc_e($x['result']) . '</td><td align="right">' . wc_e(wc_format_size($x['bytes'])) . '</td>'
                . '<td class="mini">' . wc_e(wc_cut_str($x['ua'], 60)) . '</td></tr>';
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
    echo '<form method="post" action="' . wc_e(cp_url('files.php')) . '">' . wc_csrf_field()
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
            . '<td><a href="' . wc_e(cp_url('users.php', array('uid' => (int)$x['uid']))) . '">' . wc_e(wc_cut_str($x['email'], 18)) . '</a></td>'
            . '<td><a href="?id=' . (int)$x['id'] . '">' . wc_e(wc_cut_str($x['name'], 40)) . '</a>'
            . ((int)$x['del_time'] ? ' <span class="danger">回收站</span>' : '')
            . ($x['thumb'] ? ' <span class="miniTag">缩略</span>' : '') . '</td>'
            . '<td>' . wc_e(wc_kind_name($x['kind'])) . '</td>'
            . '<td align="right">' . wc_e(wc_format_size($x['size'])) . '</td>'
            . '<td align="center">' . (int)$x['down'] . '</td>'
            . '<td>' . wc_e(date('y-m-d', (int)$x['add_time'])) . '</td>'
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

function wc_need_file($f)
{
    if (!$f) { cp_back('文件不存在或已被删除', true); }
}

function wc_sort_options($cur)
{
    $o = array('id' => '按编号（最新）', 'time' => '按上传时间', 'size' => '按文件大小', 'down' => '按下载次数', 'view' => '按预览次数', 'name' => '按名称');
    $h = '';
    foreach ($o as $k => $v) { $h .= '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . $v . '</option>'; }
    return $h;
}
