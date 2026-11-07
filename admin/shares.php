<?php
/** 外链管理：全部分享链接的检索、关闭、删除、日志 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$act = cp_action();
$code = wc_get('code') !== '' ? preg_replace('/[^A-Za-z0-9]/', '', wc_get('code')) : wc_post('code');
$s = $code !== '' ? wc_share_find($code) : null;

if ($act === 'close') {
    wc_need_share($s);
    wc_db_exec('UPDATE wc_share SET status=0 WHERE code=?', array($s['code']));
    wc_log_write('admin', '关闭外链', $s['code'], wc_auth_uid(), wc_admin_nick());
    cp_back('外链 ' . wc_e($s['code']) . ' 已关闭');
}
if ($act === 'open') {
    wc_need_share($s);
    wc_db_exec('UPDATE wc_share SET status=1 WHERE code=?', array($s['code']));
    cp_back('外链 ' . wc_e($s['code']) . ' 已恢复');
}
if ($act === 'del') {
    wc_need_share($s);
    wc_db_exec('DELETE FROM wc_share WHERE code=?', array($s['code']));
    wc_db_exec('DELETE FROM wc_share_log WHERE code=?', array($s['code']));
    WcKV::del('shareauth:' . $s['code']);
    wc_log_write('admin', '删除外链', $s['code'] . ' ' . $s['title'], wc_auth_uid(), wc_admin_nick());
    cp_back('外链记录已删除');
}
if ($act === 'unpwd') {
    wc_need_share($s);
    wc_db_exec('UPDATE wc_share SET pwd="" WHERE code=?', array($s['code']));
    WcKV::del('shareauth:' . $s['code']);
    cp_back('已清除该外链的访问密码');
}
if ($act === 'pub') {
    wc_need_share($s);
    $v = (int)$s['pub'] === 1 ? 0 : 1;
    wc_db_exec('UPDATE wc_share SET pub=? WHERE code=?', array($v, $s['code']));
    cp_back($v ? '已允许出现在首页/排行' : '已从公开索引中撤下');
}
if ($act === 'reset') {
    wc_need_share($s);
    $new = wc_new_share_code();
    wc_db_exec('UPDATE wc_share SET code=?, down=0, view=0 WHERE code=?', array($new, $s['code']));
    wc_log_write('admin', '重置外链短码', $s['code'] . ' → ' . $new, wc_auth_uid(), wc_admin_nick());
    cp_back('短码已重置为新地址：<b>' . wc_e(wc_share_page_url($new)) . '</b>（旧地址立即失效）');
}
if ($act === 'expire') {
    wc_need_share($s);
    $days = wc_postint('days');
    wc_db_exec('UPDATE wc_share SET expire=? WHERE code=?', array($days > 0 ? time() + $days * 86400 : 0, $s['code']));
    cp_back('有效期已更新');
}
if ($act === 'batch') {
    $codes = isset($_POST['code']) ? (array)$_POST['code'] : array();
    $op = wc_post('batch');
    $n = 0;
    foreach ($codes as $c) {
        $c = preg_replace('/[^A-Za-z0-9]/', '', (string)$c);
        if ($c === '') { continue; }
        if ($op === 'close') { wc_db_exec('UPDATE wc_share SET status=0 WHERE code=?', array($c)); }
        elseif ($op === 'del') { wc_db_exec('DELETE FROM wc_share WHERE code=?', array($c)); wc_db_exec('DELETE FROM wc_share_log WHERE code=?', array($c)); }
        elseif ($op === 'unpub') { wc_db_exec('UPDATE wc_share SET pub=0 WHERE code=?', array($c)); }
        else { continue; }
        $n++;
    }
    wc_log_write('admin', '批量外链操作 ' . $op, '共 ' . $n . ' 条', wc_auth_uid(), wc_admin_nick());
    cp_back('批量操作完成，共处理 ' . $n . ' 条外链');
}

$kw = wc_get('kw');
$tab = wc_get('tab');
$uid = wc_getint('uid');
$page = max(1, wc_getint('p'));
$size = 25;
$where = '1';
$args = array();
if ($kw !== '') { $where .= ' AND (s.code LIKE ? OR s.title LIKE ?)'; $lk = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $kw) . '%'; $args = array($lk, $lk); }
if ($uid > 0) { $where .= ' AND s.uid=?'; $args[] = $uid; }
if ($tab === 'on') { $where .= ' AND s.status=1 AND (s.expire=0 OR s.expire>' . time() . ')'; }
elseif ($tab === 'off') { $where .= ' AND (s.status=0 OR (s.expire>0 AND s.expire<' . time() . '))'; }
elseif ($tab === 'pub') { $where .= ' AND s.pub=1 AND s.status=1'; }
elseif ($tab === 'pwd') { $where .= " AND s.pwd<>''"; }
elseif ($tab === 'hot') { $where .= ' AND s.`down`>0'; }

$from = 'FROM wc_share s LEFT JOIN wc_user u ON u.id=s.uid WHERE ' . $where;
$total = (int)wc_db_one('SELECT COUNT(*) ' . $from, $args);
$rows = wc_db_rows('SELECT s.*, u.email, u.nick, u.status AS ustatus ' . $from . ' ORDER BY s.id DESC LIMIT ' . (($page - 1) * $size) . ",$size", $args);

cp_header('外链管理', 'shares.php');

cp_panel('筛选', '共 ' . number_format(wc_db_count('wc_share')) . ' 条分享记录');
echo '<div class="cpFilter"><form method="get" class="inl">'
    . '短码/标题 <input type="text" name="kw" size="18" value="' . wc_e($kw) . '"> '
    . 'UID <input type="text" name="uid" size="6" value="' . ($uid ?: '') . '"> '
    . '<select name="tab">'
    . '<option value="">全部</option><option value="on"' . ($tab === 'on' ? ' selected' : '') . '>有效</option>'
    . '<option value="off"' . ($tab === 'off' ? ' selected' : '') . '>已关闭/过期</option>'
    . '<option value="pub"' . ($tab === 'pub' ? ' selected' : '') . '>公开索引</option>'
    . '<option value="pwd"' . ($tab === 'pwd' ? ' selected' : '') . '>带密码</option>'
    . '<option value="hot"' . ($tab === 'hot' ? ' selected' : '') . '>有下载</option></select> '
    . '<input type="submit" class="btn" value="筛选"> <a href="' . wc_e(cp_url('shares.php')) . '">重置</a></form></div>';
cp_panel_end();

if ($s) {
    $logs = wc_db_rows('SELECT * FROM wc_share_log WHERE code=? ORDER BY id DESC LIMIT 50', array($s['code']));
    cp_panel('外链详情 ' . wc_e($s['code']), '<a href="' . wc_e(cp_url('shares.php')) . '">返回列表</a>');
    echo '<table class="cpTb">'
        . '<tr><td width="100">标题</td><td>' . wc_e($s['title']) . '</td><td width="90">对象</td><td>'
        . ((int)$s['file_id'] ? '文件 #' . (int)$s['file_id'] : '文件夹 #' . (int)$s['folder_id'])
        . '　<a href="' . wc_e(cp_url('files.php', array('id' => (int)$s['file_id']))) . '">查看文件</a></td></tr>'
        . '<tr><td>分享者</td><td>' . wc_e($s['nick'] !== '' ? $s['nick'] : $s['email']) . '（UID ' . (int)$s['uid'] . '）</td>'
        . '<td>账号状态</td><td>' . ((int)$s['ustatus'] === 2 ? '<span class="danger">已封禁</span>' : '正常') . '</td></tr>'
        . '<tr><td>页面地址</td><td colspan="3"><a href="' . wc_e(wc_share_page_url($s['code'])) . '" target="_blank">' . wc_e(wc_share_page_url($s['code'])) . '</a></td></tr>'
        . '<tr><td>直链地址</td><td colspan="3">' . ((int)$s['direct'] ? '<a href="' . wc_e(wc_share_direct_url($s['code'])) . '" target="_blank">' . wc_e(wc_share_direct_url($s['code'])) . '</a>' : '未开启直链') . '</td></tr>'
        . '<tr><td>状态</td><td>' . ((int)$s['status'] ? '有效' : '<span class="danger">已关闭</span>') . '　到期 ' . wc_e($s['expire'] ? date('Y-m-d H:i', (int)$s['expire']) : '永久')
        . '</td><td>数据</td><td>浏览 ' . (int)$s['view'] . '　下载 ' . (int)$s['down'] . '　上限 ' . ((int)$s['max_down'] ?: '不限') . '</td></tr>'
        . '<tr><td>安全</td><td colspan="3">' . ($s['pwd'] !== '' ? '<span class="miniTag">已设访问密码</span> ' : '无密码 ')
        . ($s['pub'] ? '<span class="miniTag">公开索引</span>' : '') . '</td></tr></table>';
    echo '<table class="cpTb" style="margin-top:6px"><tr class="hd"><td>快捷操作</td></tr><tr><td>'
        . ((int)$s['status'] ? cp_act('close', '关闭外链', array('code' => $s['code'])) : cp_act('open', '恢复外链', array('code' => $s['code']))) . '　'
        . cp_act('unpwd', '清除密码', array('code' => $s['code']), '清除后访客无需密码，确定？') . '　'
        . cp_act('pub', $s['pub'] ? '撤下公开索引' : '加入公开索引', array('code' => $s['code'])) . '　'
        . cp_act('reset', '重置短码', array('code' => $s['code']), '旧地址将立即失效，确定？') . '　'
        . cp_act('del', '删除记录', array('code' => $s['code']), '删除该外链记录？', 'danger')
        . '</td></tr></table>';
    echo '<form method="post" action="' . wc_e(cp_url('shares.php')) . '">' . wc_csrf_field()
        . '<input type="hidden" name="code" value="' . wc_e($s['code']) . '">'
        . '<table class="cpTb cpForm" style="margin-top:6px"><tr class="hd"><td width="110">有效期</td><td>参数</td><td width="90">&nbsp;</td></tr>'
        . '<tr><th>设置到期</th><td><select name="days"><option value="0">永久</option><option value="1">1 天</option>'
        . '<option value="7">7 天</option><option value="30">30 天</option><option value="365">1 年</option></select></td>'
        . '<td><input type="submit" class="btn" value="保存" formaction="?do=expire"></td></tr></table></form>';
    cp_panel_end();

    cp_panel('访问日志（最近 50 条）', '<span class="mini">用于核查异常下载</span>');
    if (!$logs) { echo '<div class="empty">暂无访问记录。</div>'; }
    else {
        echo '<table class="cpTb"><tr class="hd"><td width="130">时间</td><td width="120">IP</td><td width="100">结果</td>'
            . '<td width="90" align="right">字节</td><td>UA</td></tr>';
        foreach ($logs as $x) {
            echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td>' . wc_e(date('m-d H:i:s', (int)$x['add_time'])) . '</td><td>' . wc_e($x['ip']) . '</td>'
                . '<td>' . wc_e($x['result']) . '</td><td align="right">' . wc_e(wc_format_size($x['bytes'])) . '</td>'
                . '<td class="mini">' . wc_e(wc_cut_str($x['ua'], 70)) . '</td></tr>';
        }
        echo '</table>';
    }
    cp_panel_end();
}

if (!$rows) {
    cp_panel('外链列表');
    echo '<div class="empty">没有符合条件的外链。</div>';
    cp_panel_end();
} else {
    echo '<form method="post" action="' . wc_e(cp_url('shares.php')) . '">' . wc_csrf_field() . '<input type="hidden" name="do" value="batch">';
    cp_panel('外链列表（本页 ' . count($rows) . '）',
        '<select name="batch"><option value="close">批量关闭</option><option value="unpub">批量撤下公开</option><option value="del">批量删除记录</option></select> '
        . '<input type="submit" class="btn" value="执行" onclick="return confirm(\'确认执行批量操作？\')">');
    echo '<table class="cpTb"><tr class="hd"><td width="22"><input type="checkbox" onclick="wcCheckAll(this,\'code\')"></td>'
        . '<td width="70">短码</td><td>标题</td><td width="120">分享者</td><td width="50" align="center">浏览</td>'
        . '<td width="50" align="center">下载</td><td width="80">到期</td><td width="90">属性</td><td width="160">操作</td></tr>';
    foreach ($rows as $x) {
        $exp = (int)$x['expire'] > 0 && $x['expire'] < time();
        echo '<tr class="r' . ((int)$x['id'] % 2) . '">'
            . '<td><input type="checkbox" name="code[]" value="' . wc_e($x['code']) . '"></td>'
            . '<td><a href="?code=' . wc_e($x['code']) . '">' . wc_e($x['code']) . '</a></td>'
            . '<td>' . wc_e(wc_cut_str($x['title'], 38)) . '</td>'
            . '<td>' . wc_e(wc_cut_str($x['nick'] !== '' ? $x['nick'] : $x['email'], 16)) . '</td>'
            . '<td align="center">' . (int)$x['view'] . '</td><td align="center">' . (int)$x['down'] . '</td>'
            . '<td>' . wc_e($x['expire'] ? date('y-m-d', (int)$x['expire']) : '永久') . ($exp ? '<span class="danger">*</span>' : '') . '</td>'
            . '<td>' . ((int)$x['status'] === 0 ? '<span class="miniTag">关闭</span>' : '')
            . ($x['pwd'] !== '' ? '<span class="miniTag">密</span>' : '') . ($x['direct'] ? '<span class="miniTag">直</span>' : '')
            . ($x['pub'] ? '<span class="miniTag">公开</span>' : '') . '</td>'
            . '<td class="ops">' . cp_act($x['status'] ? 'close' : 'open', $x['status'] ? '关闭' : '恢复', array('code' => $x['code'])) . ' '
            . cp_act('del', '删除', array('code' => $x['code']), '删除该外链记录？', 'danger') . '</td></tr>';
    }
    echo '</table>';
    echo cp_page($total, $page, $size, cp_url('shares.php', array('kw' => $kw, 'tab' => $tab, 'uid' => $uid)));
    cp_panel_end();
    echo '</form>';
}

cp_foot();

function wc_need_share($s)
{
    if (!$s) { cp_back('外链不存在', true); }
}
