<?php
/** 下载排行：仅展示用户勾选「公开索引」的分享 */
require_once __DIR__ . '/core/bootstrap.php';

if (!setting('index_show_share', '1')) { go('index.php'); }

$kind = get('kind');
$page = max(1, getint('p'));
$size = 20;

$join = 'FROM wc_share s LEFT JOIN wc_user u ON u.id=s.uid LEFT JOIN wc_file f ON f.id=s.file_id '
    . 'WHERE s.status=1 AND s.pub=1 AND s.file_id>0 AND f.del_time=0 AND (s.expire=0 OR s.expire>?) ';
$args = array(time());
if ($kind !== '' && in_array($kind, array('image', 'video', 'audio', 'doc', 'archive', 'code', 'other'), true)) {
    $join .= ' AND f.kind=?';
    $args[] = $kind;
}
$total = (int)db_one("SELECT COUNT(*) $join", $args);
$rows = db_rows("SELECT s.code,s.`down`,s.view,s.add_time,u.nick,f.id,f.name,f.size,f.ext,f.kind,f.thumb $join"
    . ' ORDER BY s.`down` DESC, s.id DESC LIMIT ' . (($page - 1) * $size) . ",$size", $args);

page_head('下载排行');
crumbs(array(array('首页', url()), '下载排行'));
box_start('公开分享下载排行', '<a href="' . e(url('search.php')) . '">搜索分享</a>');

echo '<div class="toolbar">按类型：';
echo '<a href="' . e(url('rank.php')) . '"' . ($kind === '' ? ' class="on"' : '') . '>全部</a> ';
foreach (kind_map() as $k => $v) {
    echo '<a href="' . e(url('rank.php?kind=' . $k)) . '"' . ($kind === $k ? ' class="on"' : '') . '>' . e(kind_name($k)) . '</a> ';
}
echo '</div>';

if (!$rows) {
    echo '<div class="empty">该分类下暂无公开分享。用户创建外链时勾选「允许出现在首页与下载排行」才会出现在这里。</div>';
} else {
    echo '<table class="tb list"><tr class="hd"><td width="34">名次</td><td width="40"></td><td>文件</td>'
        . '<td width="70">类型</td><td width="90" align="right">大小</td><td width="60" align="center">下载</td>'
        . '<td width="60" align="center">浏览</td><td width="110">分享者</td><td width="90">时间</td></tr>';
    $i = ($page - 1) * $size + 1;
    foreach ($rows as $x) {
        $cls = $i <= 3 ? ' class="top3"' : '';
        echo '<tr' . $cls . '><td align="center"><b>' . $i . '</b></td><td>' . file_thumb_html($x, 30) . '</td>'
            . '<td><a href="' . e(share_page_url($x['code'])) . '">' . e(cut_str($x['name'], 44)) . '</a></td>'
            . '<td>' . e(kind_name($x['kind'])) . '</td><td align="right">' . e(format_size($x['size'])) . '</td>'
            . '<td align="center">' . (int)$x['down'] . '</td><td align="center">' . (int)$x['view'] . '</td>'
            . '<td>' . e($x['nick'] !== '' ? $x['nick'] : '站长') . '</td><td>' . e(date('y-m-d', (int)$x['add_time'])) . '</td></tr>';
        $i++;
    }
    echo '</table>';
}
echo paginate($total, $page, $size, url('rank.php?kind=' . rawurlencode($kind) . '&p={p}'));
box_end();
page_foot();
