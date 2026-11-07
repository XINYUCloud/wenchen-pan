<?php
/** 举报处理：核实→关闭外链/删除文件/驳回，处理留痕 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$act = cp_action();
$id = wc_getint('id') > 0 ? wc_getint('id') : wc_postint('id');
$r = $id > 0 ? wc_db_row('SELECT * FROM wc_report WHERE id=?', array($id)) : null;

function wc_report_finish($r, $note, $actionText)
{
    wc_db_exec('UPDATE wc_report SET status=1, handle=?, edit_time=? WHERE id=?', array(mb_substr($note, 0, 240), time(), (int)$r['id']));
    $s = $r['code'] !== '' ? wc_share_find($r['code']) : null;
    if ($s && wc_post('notify') === '1') {
        wc_notice_add((int)$s['uid'], '外链被处理', '你的分享 ' . wc_share_page_url($s['code']) . "\n标题：" . $s['title'] . "\n处理结果：" . $note, 'report');
    }
    wc_log_write('admin', '处理举报 #' . $r['id'], $actionText . '；' . $note, wc_auth_uid(), wc_admin_nick());
}

if ($act === 'handle') {
    wc_need_report($r);
    $note = mb_substr(wc_clean_name(wc_post('note'), '经核实已处理'), 0, 240);
    $s = $r['code'] !== '' ? wc_share_find($r['code']) : null;
    if ($s && wc_post('close') === '1') { wc_db_exec('UPDATE wc_share SET status=0 WHERE code=?', array($s['code'])); }
    if ($s && wc_post('delete') === '1') {
        wc_db_exec('UPDATE wc_share SET status=0 WHERE code=?', array($s['code']));
        $f = wc_db_row('SELECT * FROM wc_file WHERE id=?', array((int)$s['file_id']));
        if ($f) { wc_file_purge((int)$f['id']); }
    }
    wc_report_finish($r, $note, '已处理');
    cp_back('举报 #' . (int)$r['id'] . ' 已处理：' . wc_e($note));
}
if ($act === 'reject') {
    wc_need_report($r);
    $note = mb_substr(wc_clean_name(wc_post('note'), '经核实不构成违规，驳回'), 0, 240);
    wc_db_exec('UPDATE wc_report SET status=2, handle=?, edit_time=? WHERE id=?', array($note, time(), (int)$r['id']));
    wc_log_write('admin', '驳回举报 #' . $r['id'], $note, wc_auth_uid(), wc_admin_nick());
    cp_back('举报 #' . (int)$r['id'] . ' 已驳回');
}
if ($act === 'pending') {
    wc_need_report($r);
    wc_db_exec('UPDATE wc_report SET status=0, handle="" WHERE id=?', array((int)$r['id']));
    cp_back('已恢复为待处理');
}
if ($act === 'del') {
    wc_need_report($r);
    wc_db_exec('DELETE FROM wc_report WHERE id=?', array((int)$r['id']));
    cp_back('举报记录已删除');
}

$st = wc_get('st');
$page = max(1, wc_getint('p'));
$size = 25;
$where = '1';
if ($st === '0' || $st === '1' || $st === '2') { $where = 'status=' . (int)$st; }
$total = (int)wc_db_one("SELECT COUNT(*) FROM wc_report WHERE $where");
$rows = wc_db_rows("SELECT * FROM wc_report WHERE $where ORDER BY status ASC, id DESC LIMIT " . (($page - 1) * $size) . ",$size");

cp_header('举报处理', 'reports.php');

$openCnt = wc_db_count('wc_report', 'status=0');
cp_panel('状态', '待处理 <b class="danger">' . $openCnt . '</b> 条／全部 ' . wc_db_count('wc_report') . ' 条');
echo '<div class="cpFilter"><a href="' . wc_e(cp_url('reports.php')) . '"' . ($st === '' ? ' class="on"' : '') . '>全部</a>　'
    . '<a href="' . wc_e(cp_url('reports.php', array('st' => 0))) . '"' . ($st === '0' ? ' class="on"' : '') . '>待处理</a>　'
    . '<a href="' . wc_e(cp_url('reports.php', array('st' => 1))) . '"' . ($st === '1' ? ' class="on"' : '') . '>已处理</a>　'
    . '<a href="' . wc_e(cp_url('reports.php', array('st' => 2))) . '"' . ($st === '2' ? ' class="on"' : '') . '>已驳回</a></div>';
cp_panel_end();

if ($r) {
    $s = $r['code'] !== '' ? wc_share_find($r['code']) : null;
    $file = $s && (int)$s['file_id'] > 0 ? wc_db_row('SELECT * FROM wc_file WHERE id=?', array((int)$s['file_id'])) : null;
    $stMap = array('0' => '<span class="danger">待处理</span>', '1' => '<span class="ok">已处理</span>', '2' => '已驳回');
    cp_panel('举报详情 #' . (int)$r['id'], '<a href="' . wc_e(cp_url('reports.php')) . '">返回列表</a>');
    echo '<table class="cpTb">'
        . '<tr><td width="100">状态</td><td width="200">' . $stMap[(string)(int)$r['status']] . '</td>'
        . '<td width="100">提交时间</td><td>' . wc_e(date('Y-m-d H:i:s', (int)$r['add_time'])) . '　处理时间：' . wc_e($r['edit_time'] ? date('Y-m-d H:i:s', (int)$r['edit_time']) : '-') . '</td></tr>'
        . '<tr><td>理由</td><td>' . wc_e($r['reason']) . '</td><td>举报人 IP</td><td>' . wc_e($r['ip']) . '</td></tr>'
        . '<tr><td>联系方式</td><td>' . wc_e($r['contact']) . '</td><td>被举报地址</td><td><a href="' . wc_e($r['url']) . '" target="_blank">' . wc_e($r['url']) . '</a></td></tr>'
        . '<tr><td>详情</td><td colspan="3">' . wc_nl2p($r['detail']) . '</td></tr>'
        . '<tr><td>处理备注</td><td colspan="3">' . wc_e($r['handle']) . '</td></tr>';
    if ($s) {
        echo '<tr class="hd"><td colspan="4">关联信息</td></tr>'
            . '<tr><td>外链</td><td><a href="' . wc_e(wc_share_page_url($s['code'])) . '" target="_blank">' . wc_e($s['code']) . '</a>　' . ((int)$s['status'] ? '有效' : '已关闭') . '</td>'
            . '<td>分享者</td><td><a href="' . wc_e(cp_url('users.php', array('uid' => (int)$s['uid']))) . '">' . wc_e($s['email']) . '</a>（UID ' . (int)$s['uid'] . '）</td></tr>';
        if ($file) {
            echo '<tr><td>文件</td><td colspan="3"><a href="' . wc_e(cp_url('files.php', array('id' => (int)$file['id']))) . '">' . wc_e($file['name']) . '</a>'
                . '　' . wc_e(wc_format_size($file['size'])) . '　真实类型：' . wc_e(wc_sniff_type(wc_file_abs($file)) ?: '-') . '</td></tr>';
        }
    } elseif ($r['code'] !== '') {
        echo '<tr><td colspan="4" class="danger">关联外链已不存在（可能已被删除）</td></tr>';
    }
    echo '</table>';

    echo '<form method="post" action="' . wc_e(cp_url('reports.php')) . '">' . wc_csrf_field()
        . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
        . '<table class="cpTb cpForm" style="margin-top:8px">'
        . '<tr class="hd"><td width="110">处理</td><td>参数</td><td width="110">&nbsp;</td></tr>'
        . '<tr><th>处理说明</th><td><input type="text" name="note" size="60" placeholder="例如：已确认侵犯版权，删除文件并关闭外链"></td>'
        . '<td><input type="submit" class="btn" value="标记已处理" formaction="?do=handle"></td></tr>'
        . '<tr><th>连带动作</th><td><label><input type="checkbox" name="close" value="1" checked> 关闭该外链</label>　'
        . '<label><input type="checkbox" name="delete" value="1"> 彻底删除文件</label>　'
        . '<label><input type="checkbox" name="notify" value="1" checked> 站内信通知分享者</label></td>'
        . '<td class="mini">勾选「彻底删除文件」不可恢复</td></tr>'
        . '<tr><th>驳回</th><td class="mini">使用上方处理说明作为驳回理由</td>'
        . '<td><input type="submit" class="btn" value="驳回举报" formaction="?do=reject"></td></tr>'
        . '<tr><th>其他</th><td>' . cp_act('pending', '恢复待处理', array('id' => (int)$r['id'])) . '</td>'
        . '<td>' . cp_act('del', '删除记录', array('id' => (int)$r['id']), '删除该举报记录？', 'danger') . '</td></tr>'
        . '</table></form>';
    cp_panel_end();
}

cp_panel('举报列表', '本页 ' . count($rows) . ' 条');
if (!$rows) {
    echo '<div class="empty">没有符合条件的举报。</div>';
} else {
    echo '<table class="cpTb"><tr class="hd"><td width="40">ID</td><td width="70">状态</td><td width="90">理由</td>'
        . '<td>地址</td><td width="110">举报 IP</td><td width="110">联系方式</td><td width="100">时间</td><td width="140">操作</td></tr>';
    foreach ($rows as $x) {
        $sts = array('0' => '<span class="danger">待处理</span>', '1' => '<span class="ok">已处理</span>', '2' => '已驳回');
        echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td>' . (int)$x['id'] . '</td><td>' . $sts[(int)$x['status']] . '</td>'
            . '<td>' . wc_e($x['reason']) . '</td>'
            . '<td><a href="?id=' . (int)$x['id'] . '">' . wc_e(wc_cut_str($x['url'], 44)) . '</a></td>'
            . '<td>' . wc_e($x['ip']) . '</td><td>' . wc_e(wc_cut_str($x['contact'], 14)) . '</td>'
            . '<td>' . wc_e(date('y-m-d H:i', (int)$x['add_time'])) . '</td>'
            . '<td class="ops"><a href="?id=' . (int)$x['id'] . '">处理</a> '
            . ((int)$x['code'] !== '' ? '<a href="' . wc_e(wc_url('s.php?c=' . rawurlencode($x['code']))) . '" target="_blank">查看内容</a>' : '') . ' '
            . cp_act('del', '删除', array('id' => (int)$x['id']), '删除记录？', 'danger') . '</td></tr>';
    }
    echo '</table>';
    echo cp_page($total, $page, $size, cp_url('reports.php', array('st' => $st)));
}
cp_panel_end();
cp_foot();

function wc_need_report($r)
{
    if (!$r) { cp_back('举报记录不存在', true); }
}
