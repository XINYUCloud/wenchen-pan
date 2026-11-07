<?php
/** 站内搜索：搜公开分享；登录用户同时给出「搜我的文件」入口 */
require_once __DIR__ . '/core/bootstrap.php';

$q = get('q');
if (get('from') === 'mine' && auth_uid() > 0) { go('files.php?q=' . urlencode($q)); }

page_head('站内搜索');
crumbs(array(array('首页', url()), '搜索'));
box_start('站内搜索');

echo '<form method="get" action="' . e(url('search.php')) . '" class="inl">'
    . '<input type="text" name="q" size="34" maxlength="60" value="' . e($q) . '" autofocus>'
    . ' <input type="submit" class="btn" value="搜索公开分享">';
if (auth_uid() > 0) {
    echo ' 　<a href="' . e(url('files.php?q=' . urlencode($q))) . '">在我的文件里搜索</a>';
}
echo '</form>';

if ($q === '') {
    echo '<div class="empty">请输入关键词。这里只会检索用户设为「公开索引」的分享；搜索自己的文件请点上方链接。</div>';
    box_end();
    page_foot();
    exit;
}
if (!setting('index_show_share', '1')) {
    tip('站点未开启公开分享索引，无法检索。', 'warn');
    box_end();
    page_foot();
    exit;
}

$like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $q) . '%';
$rows = db_rows('SELECT s.code,s.`down`,s.view,s.add_time,u.nick,f.name,f.size,f.ext,f.kind,f.thumb '
    . 'FROM wc_share s LEFT JOIN wc_user u ON u.id=s.uid LEFT JOIN wc_file f ON f.id=s.file_id '
    . 'WHERE s.status=1 AND s.pub=1 AND f.del_time=0 AND (s.expire=0 OR s.expire>?) AND (f.name LIKE ? OR f.tags LIKE ?) '
    . 'ORDER BY s.`down` DESC LIMIT 40', array(time(), $like, $like));

if (!$rows) {
    echo '<div class="empty">没有找到与「' . e($q) . '」相关的公开分享。</div>';
} else {
    echo '<div class="mini">共 ' . count($rows) . ' 条结果（最多显示 40 条）</div>';
    echo '<table class="tb list"><tr class="hd"><td width="40"></td><td>文件</td><td width="70">类型</td>'
        . '<td width="90" align="right">大小</td><td width="60" align="center">下载</td><td width="110">分享者</td><td width="90">时间</td></tr>';
    foreach ($rows as $x) {
        echo '<tr><td>' . file_thumb_html($x, 30) . '</td><td><a href="' . e(share_page_url($x['code'])) . '">' . e(cut_str($x['name'], 44)) . '</a></td>'
            . '<td>' . e(kind_name($x['kind'])) . '</td><td align="right">' . e(format_size($x['size'])) . '</td>'
            . '<td align="center">' . (int)$x['down'] . '</td><td>' . e($x['nick']) . '</td><td>' . e(date('y-m-d', (int)$x['add_time'])) . '</td></tr>';
    }
    echo '</table>';
}
box_end();
page_foot();
