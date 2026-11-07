<?php
/** 公告中心：列表 + 详情 */
require_once __DIR__ . '/core/bootstrap.php';

if (!setting('announce_open', '1')) { go('index.php'); }

$id = getint('id');
if ($id > 0) {
    $a = db_row('SELECT * FROM wc_announce WHERE id=?', array($id));
    if (!$a || (int)$a['status'] === 0) { alert('公告不存在或已下线。', url('announce.php'), '提示'); }
    db_exec('UPDATE wc_announce SET view=view+1 WHERE id=?', array($id));
    page_head(cut_str($a['title'], 26));
    crumbs(array(array('首页', url()), array('公告中心', url('announce.php')), '公告详情'));
    box_start('公告详情');
    echo '<div class="annTitle"><b>' . e($a['title']) . '</b></div>'
        . '<div class="annMeta">发布：' . e(date('Y-m-d H:i', (int)$a['add_time'])) . '　发布人：' . e($a['author'])
        . '　浏览：' . (int)$a['view'] . ($a['edit_time'] > $a['add_time'] ? '　最后修改：' . e(date('Y-m-d H:i', (int)$a['edit_time'])) : '') . '</div>'
        . '<div class="annBody">' . nl2br((string)$a['content']) . '</div>'
        . '<div class="annFoot"><a class="mybtn" href="' . e(url('announce.php')) . '">返回公告列表</a></div>';
    $rel = db_rows('SELECT id,title,add_time FROM wc_announce WHERE status=1 AND id<>? ORDER BY top DESC,add_time DESC LIMIT 8', array($id));
    if ($rel) {
        echo '<div class="hr"></div><b>其他公告</b><ul class="linkList">';
        foreach ($rel as $r) {
            echo '<li><a href="' . e(url('announce.php?id=' . (int)$r['id'])) . '">' . e($r['title']) . '</a> <span class="mini">' . e(date('Y-m-d', (int)$r['add_time'])) . '</span></li>';
        }
        echo '</ul>';
    }
    box_end();
    page_foot();
    exit;
}

$page = max(1, getint('p'));
$size = 15;
$total = (int)db_count('wc_announce', 'status=1');
$rows = db_rows('SELECT * FROM wc_announce WHERE status=1 ORDER BY top DESC, add_time DESC LIMIT ' . (($page - 1) * $size) . ",$size");

page_head('公告中心');
crumbs(array(array('首页', url()), '公告中心'));
box_start('全部公告', '<span class="mini">共 ' . $total . ' 条</span>');
if (!$rows) {
    echo '<div class="empty">还没有公告。</div>';
} else {
    echo '<table class="tb list"><tr class="hd"><td width="70">标记</td><td>标题</td><td width="110">发布人</td>'
        . '<td width="120">发布时间</td><td width="70">浏览</td></tr>';
    foreach ($rows as $a) {
        echo '<tr><td>' . ($a['top'] ? '<span class="tagTop">置顶</span>' : '<span class="mini">公告</span>') . '</td>'
            . '<td><a href="' . e(url('announce.php?id=' . (int)$a['id'])) . '">' . e($a['title']) . '</a>'
            . '<div class="annBrief">' . e(cut_str(strip_tags((string)$a['content']), 80)) . '</div></td>'
            . '<td>' . e($a['author']) . '</td><td>' . e(date('Y-m-d H:i', (int)$a['add_time'])) . '</td>'
            . '<td>' . (int)$a['view'] . '</td></tr>';
    }
    echo '</table>';
}
echo paginate($total, $page, $size, url('announce.php?p={p}'));
box_end();
page_foot();
