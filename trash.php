<?php
/** 回收站：软删除文件在此还原或彻底删除，超过保留期由系统自动清理 */
require_once __DIR__ . '/core/bootstrap.php';
require_login();
$u = auth_user();

$days = (int)setting('recycle_days', '7');
$page = max(1, getint('p'));
$size = 30;
$total = (int)db_one('SELECT COUNT(*) FROM wc_file WHERE uid=? AND del_time>0', array((int)$u['id']));
$rows = db_rows('SELECT * FROM wc_file WHERE uid=? AND del_time>0 ORDER BY del_time DESC LIMIT ' . (($page - 1) * $size) . ",$size", array((int)$u['id']));
$bytes = (int)db_one('SELECT COALESCE(SUM(size),0) FROM wc_file WHERE uid=? AND del_time>0', array((int)$u['id']));

page_head('回收站');
crumbs(array(array('我的文件', url('files.php')), '回收站'));
box_start('回收站', '<a href="' . e(url('files.php')) . '">返回文件管理</a>');

echo '<div class="toolbar">'
    . '<a href="' . e(api_url('purge', array('all' => 1))) . '" onclick="return confirm(\'彻底清空回收站？此操作不可恢复\')" class="btn">清空回收站</a>'
    . '　<a href="' . e(api_url('restore', array('all' => 1))) . '" class="btn">全部还原</a>'
    . '<div class="mini">保留策略：删除后保留 <b>' . $days . '</b> 天，到期自动彻底删除；当前 ' . $total . ' 个文件，' . format_size($bytes) . '。</div>'
    . '</div>';

if (!$rows) {
    echo '<div class="empty">回收站是空的。</div>';
    box_end();
    page_foot();
    exit;
}

echo '<form method="post" action="' . e(url('api.php')) . '">' . csrf_field()
    . '<input type="hidden" name="back" value="trash.php">'
    . '<table class="tb list"><tr class="hd"><td width="24"><input type="checkbox" onclick="wcCheckAll(this,\'file\')"></td>'
    . '<td width="40"></td><td>名称</td><td width="90" align="right">大小</td><td width="120">删除时间</td>'
    . '<td width="90">剩余保留</td><td width="150">操作</td></tr>';
foreach ($rows as $f) {
    $left = $days - floor((time() - (int)$f['del_time']) / 86400);
    echo '<tr><td><input type="checkbox" name="file[]" value="' . (int)$f['id'] . '"></td>'
        . '<td>' . file_icon($f['kind'], $f['ext']) . '</td>'
        . '<td>' . e(cut_str($f['name'], 44)) . ' <span class="mini">#' . (int)$f['id'] . '</span></td>'
        . '<td align="right">' . e(format_size($f['size'])) . '</td>'
        . '<td>' . e(date('Y-m-d H:i', (int)$f['del_time'])) . '</td>'
        . '<td>' . ($left <= 0 ? '<span class="red">即将清除</span>' : $left . ' 天') . '</td>'
        . '<td><a href="' . e(api_url('restore', array('id' => (int)$f['id']))) . '">还原</a> '
        . '<a class="red" href="' . e(api_url('purge', array('id' => (int)$f['id']))) . '" onclick="return confirm(\'彻底删除该文件？\')">彻底删除</a></td></tr>';
}
echo '</table>'
    . '<div class="batchBar"><select name="batch"><option value="restore">还原选中</option><option value="purge">彻底删除选中</option></select> '
    . '<input type="submit" class="btn" value="执行"></div>';
// 批量按钮统一走 purge/restore，由后端读取 batch 字段
echo '<input type="hidden" name="do" value="batch_trash">';
echo '</form>';

echo paginate($total, $page, $size, url('trash.php?p={p}'));
box_end();
page_foot();
