<?php
/** 首页：站点介绍 + 公告 + 公开分享（最新 / 热门）+ 登录后的快捷面板 */
require_once __DIR__ . '/core/bootstrap.php';

$u = auth_user();
$ann = db_rows('SELECT * FROM wc_announce WHERE status=1 ORDER BY top DESC, add_time DESC LIMIT 5');
$pubOn = setting('index_show_share', '1') && setting('announce_open', '1');
$pubSql = 'SELECT s.code,s.expire,s.add_time AS share_time,s.`down`,u.nick,f.id,f.name,f.size,f.ext,f.kind '
    . 'FROM wc_share s LEFT JOIN wc_user u ON u.id=s.uid LEFT JOIN wc_file f ON f.id=s.file_id '
    . 'WHERE s.status=1 AND s.pub=1 AND s.file_id>0 AND f.del_time=0 AND (s.expire=0 OR s.expire>?) ';
$latest = $pubOn ? db_rows($pubSql . 'ORDER BY s.id DESC LIMIT 10', array(time())) : array();
$hot = $pubOn ? db_rows($pubSql . 'ORDER BY s.`down` DESC LIMIT 10', array(time())) : array();

page_head('');

/* ---------- 站点介绍 / 快捷面板 ---------- */
box_start('欢迎使用' . e(setting('site_name', SITE_NAME)), $u ? '<a href="' . e(url('upload.php')) . '"><b>上传文件</b></a>' : '<a href="' . e(url('register.php')) . '"><b>免费注册</b></a>');
echo '<div class="intro">'
    . '<p class="notice">' . e(setting('site_notice', '')) . '</p>'
    . '<table class="featTb"><tr>';
$feats = array(
    array('多格式', '图片、视频、音频、文档、压缩包、代码文件统统支持'),
    array('外链分享', '每个文件独立短码，可设密码、有效期与次数上限'),
    array('断点续传', '大文件自动分片上传，中断后可继续，支持 Range 下载'),
    array('在线预览', '图片灯箱、视频拖动播放、文本/PDF 直接看'),
    array('秒传去重', '相同 SHA1 的文件只保存一份实体，秒级完成'),
    array('打包下载', '文件夹一键 ZIP 打包，访客无需登录'),
);
foreach (array_slice($feats, 0, 3) as $t) { echo '<td><b>' . e($t[0]) . '</b><br><span class="mini">' . e($t[1]) . '</span></td>'; }
echo '</tr><tr>';
foreach (array_slice($feats, 3) as $t) { echo '<td><b>' . e($t[0]) . '</b><br><span class="mini">' . e($t[1]) . '</span></td>'; }
echo '</tr></table></div>';
box_end();

/* ---------- 登录后：最近文件 ---------- */
if ($u) {
    $recent = db_rows('SELECT * FROM wc_file WHERE uid=? AND del_time=0 ORDER BY add_time DESC LIMIT 6', array((int)$u['id']));
    box_start('我的最近上传', '<a href="' . e(url('files.php')) . '">全部文件</a> | <a href="' . e(url('upload.php')) . '">继续上传</a>');
    if (!$recent) {
        echo '<div class="empty">还没有上传过文件。' . ((int)$u['verified'] === 0 ? '请先完成邮箱验证。' : '<a href="' . e(url('upload.php')) . '">现在去上传</a>。') . '</div>';
    } else {
        echo '<table class="tb list"><tr class="hd"><td width="40"></td><td>名称</td><td width="90" align="right">大小</td>'
            . '<td width="100">时间</td><td width="130">操作</td></tr>';
        foreach ($recent as $f) {
            echo '<tr><td>' . file_icon($f['kind'], $f['ext']) . '</td>'
                . '<td><a href="' . e(url('preview.php?id=' . (int)$f['id'])) . '" target="_blank">' . e(cut_str($f['name'], 40)) . '</a></td>'
                . '<td align="right">' . e(format_size($f['size'])) . '</td><td>' . e(human_time($f['add_time'])) . '</td>'
                . '<td><a href="' . e(url('files.php?act=share&id=' . (int)$f['id'])) . '">分享</a> '
                . '<a href="' . e(url('api.php?do=download&id=' . (int)$f['id'])) . '">下载</a></td></tr>';
        }
        echo '</table>';
    }
    box_end();
}

/* ---------- 公告 ---------- */
if ($ann && setting('announce_open', '1')) {
    box_start('站点公告', '<a href="' . e(url('announce.php')) . '">全部公告</a>');
    echo '<ul class="annList">';
    foreach ($ann as $a) {
        echo '<li>' . ($a['top'] ? '<span class="tagTop">置顶</span>' : '')
            . '<a href="' . e(url('announce.php?id=' . (int)$a['id'])) . '">' . e($a['title']) . '</a>'
            . '<span class="mini">　' . e(date('Y-m-d', (int)$a['add_time'])) . '　浏览 ' . (int)$a['view'] . '</span>'
            . '<div class="annBrief">' . e(cut_str(strip_tags((string)$a['content']), 90)) . '</div></li>';
    }
    echo '</ul>';
    box_end();
}

/* ---------- 公开分享 ---------- */
if ($pubOn && ($latest || $hot)) {
    echo '<table class="twoCol"><tr><td width="50%" valign="top">';
    box_start('最新公开分享', '<a href="' . e(url('rank.php')) . '">查看全部</a>');
    if (!$latest) { echo '<div class="empty">暂时还没有公开的分享。站长可在后台关闭本栏目。</div>'; }
    else {
        echo '<ul class="linkList">';
        foreach ($latest as $x) {
            echo '<li>' . file_icon($x['kind'], $x['ext']) . ' <a href="' . e(share_page_url($x['code'])) . '">' . e(cut_str($x['name'], 28)) . '</a>'
                . '<span class="mini">　' . e(format_size($x['size'])) . '　' . e($x['nick'] !== '' ? $x['nick'] : '站长') . '</span></li>';
        }
        echo '</ul>';
    }
    box_end();
    echo '</td><td width="50%" valign="top">';
    box_start('下载排行', '<a href="' . e(url('rank.php')) . '">完整榜单</a>');
    if (!$hot) { echo '<div class="empty">暂无排行数据。</div>'; }
    else {
        echo '<ol class="rankList">';
        foreach ($hot as $x) {
            echo '<li><a href="' . e(share_page_url($x['code'])) . '">' . e(cut_str($x['name'], 26)) . '</a>'
                . '<span class="mini">　' . (int)$x['down'] . ' 次</span></li>';
        }
        echo '</ol>';
    }
    box_end();
    echo '</td></tr></table>';
}

page_foot();
