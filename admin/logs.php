<?php
/** 日志审计：操作/登录/上传/系统日志的检索与清理 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$act = cp_action();
if ($act === 'clean_old') {
    $days = max(1, wc_postint('keep'));
    $n = wc_db_exec('DELETE FROM wc_log WHERE add_time<?', array(time() - $days * 86400));
    wc_db_exec('DELETE FROM wc_share_log WHERE add_time<?', array(time() - $days * 86400));
    wc_log_write('admin', '清理日志', '删除 ' . $days . ' 天前的共 ' . $n . ' 条', wc_auth_uid(), wc_admin_nick());
    cp_back('已清理 ' . $n . ' 条系统日志与同期访问日志');
}
if ($act === 'truncate') {
    wc_require_super();
    wc_db_exec('DELETE FROM wc_log');
    wc_db_exec('DELETE FROM wc_share_log');
    cp_back('日志已全部清空（该操作本身也已被清除，请注意）');
}
if ($act === 'del') {
    wc_db_exec('DELETE FROM wc_log WHERE id=?', array(wc_getint('id')));
    cp_back('该条日志已删除');
}

$type = wc_get('type');
$kw = wc_get('kw');
$uid = wc_getint('uid');
$ipf = wc_get('ip');
$days = wc_get('days');
$page = max(1, wc_getint('p'));
$size = 50;

$where = '1';
$args = array();
if ($type !== '') { $where .= ' AND type=?'; $args[] = $type; }
if ($kw !== '') { $where .= ' AND (action LIKE ? OR detail LIKE ? OR uname LIKE ?)'; $lk = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $kw) . '%'; $args = array_merge($args, array($lk, $lk, $lk)); }
if ($uid > 0) { $where .= ' AND uid=?'; $args[] = $uid; }
if ($ipf !== '') { $where .= ' AND ip LIKE ?'; $args[] = $ipf . '%'; }
if ($days !== '' && is_numeric($days)) { $where .= ' AND add_time>=' . (time() - (int)$days * 86400); }

$total = (int)wc_db_one("SELECT COUNT(*) FROM wc_log WHERE $where", $args);
$rows = wc_db_rows("SELECT * FROM wc_log WHERE $where ORDER BY id DESC LIMIT " . (($page - 1) * $size) . ",$size", $args);
$types = wc_db_rows('SELECT type,COUNT(*) AS c FROM wc_log GROUP BY type ORDER BY c DESC');

cp_header('日志审计', 'logs.php');

cp_panel('筛选', '共 ' . number_format($total) . ' 条匹配日志');
echo '<div class="cpFilter"><form method="get" class="inl">';
echo '类型 <select name="type"><option value="">全部</option>';
foreach ($types as $t) {
    echo '<option value="' . wc_e($t['type']) . '"' . ($type === $t['type'] ? ' selected' : '') . '>' . wc_e($t['type']) . '（' . (int)$t['c'] . '）</option>';
}
echo '</select> 关键词 <input type="text" name="kw" size="14" value="' . wc_e($kw) . '" placeholder="动作/详情/账号"> '
    . 'UID <input type="text" name="uid" size="6" value="' . ($uid ?: '') . '"> '
    . 'IP 前缀 <input type="text" name="ip" size="12" value="' . wc_e($ipf) . '"> '
    . '时间 <select name="days"><option value="">不限</option>';
foreach (array('1', '3', '7', '30') as $d) { echo '<option value="' . $d . '"' . ($days === $d ? ' selected' : '') . '>近 ' . $d . ' 天</option>'; }
echo '</select> <input type="submit" class="btn" value="查询"> <a href="' . wc_e(cp_url('logs.php')) . '">重置</a></form></div>';
cp_panel_end();

cp_panel('日志清理', '<span class="mini">保留期设置：当前 ' . (int)wc_setting('log_keep_days', 30) . ' 天（自动清理在站点设置中修改）</span>');
echo '<form method="post" action="' . wc_e(cp_url('logs.php')) . '" class="inl">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="clean_old">'
    . '删除 <input type="number" name="keep" value="' . (int)wc_setting('log_keep_days', 30) . '" size="4" min="1"> 天前的日志 '
    . '<input type="submit" class="btn" value="执行清理"></form>';
if (wc_auth_role() === 'super') {
    echo '　' . cp_act('truncate', '清空全部日志', array(), '该操作不可恢复，确定清空所有日志与外链访问记录？', 'danger');
}
cp_panel_end();

cp_panel('日志明细', '本页 ' . count($rows) . ' 条');
if (!$rows) {
    echo '<div class="empty">没有符合条件的日志。</div>';
} else {
    echo '<table class="cpTb"><tr class="hd"><td width="60">ID</td><td width="120">时间</td><td width="70">类型</td>'
        . '<td width="110">账号</td><td width="40">UID</td><td width="220">动作</td><td>详情</td><td width="110">IP</td><td width="50">&nbsp;</td></tr>';
    foreach ($rows as $x) {
        echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td>' . (int)$x['id'] . '</td>'
            . '<td>' . wc_e(date('m-d H:i:s', (int)$x['add_time'])) . '</td><td>' . wc_e($x['type']) . '</td>'
            . '<td>' . wc_e(wc_cut_str($x['uname'], 14)) . '</td><td>' . (int)$x['uid'] . '</td>'
            . '<td>' . wc_e(wc_cut_str($x['action'], 26)) . '</td>'
            . '<td class="mini">' . wc_e(wc_cut_str($x['detail'], 70)) . '</td>'
            . '<td>' . wc_e($x['ip']) . '</td>'
            . '<td>' . cp_act('del', '×', array('id' => (int)$x['id']), '删除该条？') . '</td></tr>';
    }
    echo '</table>';
    echo cp_page($total, $page, $size, cp_url('logs.php', array('type' => $type, 'kw' => $kw, 'uid' => $uid, 'ip' => $ipf, 'days' => $days)));
}
cp_panel_end();
cp_foot();
