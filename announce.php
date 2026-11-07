<?php
/** 公告中心：列表 + 详情 */
require_once __DIR__ . '/core/bootstrap.php';

if (!wc_setting('announce_open', '1')) { wc_go('index.php'); }

$id = wc_getint('id');
if ($id > 0) {
    $a = wc_db_row('SELECT * FROM wc_announce WHERE id=?', array($id));
    if (!$a || (int)$a['status'] === 0) { wc_alert('公告不存在或已下线。', wc_url('announce.php'), '提示'); }
    wc_db_exec('UPDATE wc_announce SET view=view+1 WHERE id=?', array($id));
    wc_page_head(wc_cut_str($a['title'], 26));
    wc_crumbs(array(array('首页', wc_url()), array('公告中心', wc_url('announce.php')), '公告详情'));
    wc_box_start('公告详情');
    echo '<div class="annTitle"><b>' . wc_e($a['title']) . '</b></div>'
        . '<div class="annMeta">发布：' . wc_e(date('Y-m-d H:i', (int)$a['add_time'])) . '　发布人：' . wc_e($a['author'])
        . '　浏览：' . (int)$a['view'] . ($a['edit_time'] > $a['add_time'] ? '　最后修改：' . wc_e(date('Y-m-d H:i', (int)$a['edit_time'])) : '') . '</div>'
        . '<div class="annBody">' . nl2br((string)$a['content']) . '</div>'
        . '<div class="annFoot"><a class="mybtn" href="' . wc_e(wc_url('announce.php')) . '">返回公告列表</a></div>';
    $rel = wc_db_rows('SELECT id,title,add_time FROM wc_announce WHERE status=1 AND id<>? ORDER BY top DESC,add_time DESC LIMIT 8', array($id));
    if ($rel) {
        echo '<div class="hr"></div><b>其他公告</b><ul class="linkList">';
        foreach ($rel as $r) {
            echo '<li><a href="' . wc_e(wc_url('announce.php?id=' . (int)$r['id'])) . '">' . wc_e($r['title']) . '</a> <span class="mini">' . wc_e(date('Y-m-d', (int)$r['add_time'])) . '</span></li>';
        }
        echo '</ul>';
    }
    wc_box_end();
    wc_page_foot();
    exit;
}

$page = max(1, wc_getint('p'));
$size = 15;
$total = (int)wc_db_count('wc_announce', 'status=1');
$rows = wc_db_rows('SELECT * FROM wc_announce WHERE status=1 ORDER BY top DESC, add_time DESC LIMIT ' . (($page - 1) * $size) . ",$size");

wc_page_head('公告中心');
wc_crumbs(array(array('首页', wc_url()), '公告中心'));
wc_box_start('全部公告', '<span class="mini">共 ' . $total . ' 条</span>');
if (!$rows) {
    echo '<div class="empty">还没有公告。</div>';
} else {
    echo '<table class="tb list"><tr class="hd"><td width="70">标记</td><td>标题</td><td width="110">发布人</td>'
        . '<td width="120">发布时间</td><td width="70">浏览</td></tr>';
    foreach ($rows as $a) {
        echo '<tr><td>' . ($a['top'] ? '<span class="tagTop">置顶</span>' : '<span class="mini">公告</span>') . '</td>'
            . '<td><a href="' . wc_e(wc_url('announce.php?id=' . (int)$a['id'])) . '">' . wc_e($a['title']) . '</a>'
            . '<div class="annBrief">' . wc_e(wc_cut_str(strip_tags((string)$a['content']), 80)) . '</div></td>'
            . '<td>' . wc_e($a['author']) . '</td><td>' . wc_e(date('Y-m-d H:i', (int)$a['add_time'])) . '</td>'
            . '<td>' . (int)$a['view'] . '</td></tr>';
    }
    echo '</table>';
}
echo wc_paginate($total, $page, $size, wc_url('announce.php?p={p}'));
wc_box_end();
wc_page_foot();
