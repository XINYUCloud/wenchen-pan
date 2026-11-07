<?php
/** 首页：站点介绍 + 公告 + 公开分享（最新 / 热门）+ 登录后的快捷面板 */
require_once __DIR__ . '/core/bootstrap.php';

$u = wc_auth_user();
$ann = wc_db_rows('SELECT * FROM wc_announce WHERE status=1 ORDER BY top DESC, add_time DESC LIMIT 5');
$pubOn = wc_setting('index_show_share', '1') && wc_setting('announce_open', '1');
$pubSql = 'SELECT s.code,s.expire,s.add_time AS share_time,s.`down`,u.nick,f.id,f.name,f.size,f.ext,f.kind '
    . 'FROM wc_share s LEFT JOIN wc_user u ON u.id=s.uid LEFT JOIN wc_file f ON f.id=s.file_id '
    . 'WHERE s.status=1 AND s.pub=1 AND s.file_id>0 AND f.del_time=0 AND (s.expire=0 OR s.expire>?) ';
$latest = $pubOn ? wc_db_rows($pubSql . 'ORDER BY s.id DESC LIMIT 10', array(time())) : array();
$hot = $pubOn ? wc_db_rows($pubSql . 'ORDER BY s.`down` DESC LIMIT 10', array(time())) : array();

wc_page_head('');

/* ---------- 站点介绍 / 快捷面板 ---------- */
wc_box_start('欢迎使用' . wc_e(wc_setting('site_name', SITE_NAME)), $u ? '<a href="' . wc_e(wc_url('upload.php')) . '"><b>上传文件</b></a>' : '<a href="' . wc_e(wc_url('register.php')) . '"><b>免费注册</b></a>');
echo '<div class="intro">'
    . '<p class="notice">' . wc_e(wc_setting('site_notice', '')) . '</p>'
    . '<table class="featTb"><tr>';
$feats = array(
    array('多格式', '图片、视频、音频、文档、压缩包、代码文件统统支持'),
    array('外链分享', '每个文件独立短码，可设密码、有效期与次数上限'),
    array('断点续传', '大文件自动分片上传，中断后可继续，支持 Range 下载'),
    array('在线预览', '图片灯箱、视频拖动播放、文本/PDF 直接看'),
    array('秒传去重', '相同 SHA1 的文件只保存一份实体，秒级完成'),
    array('打包下载', '文件夹一键 ZIP 打包，访客无需登录'),
);
foreach (array_slice($feats, 0, 3) as $t) { echo '<td><b>' . wc_e($t[0]) . '</b><br><span class="mini">' . wc_e($t[1]) . '</span></td>'; }
echo '</tr><tr>';
foreach (array_slice($feats, 3) as $t) { echo '<td><b>' . wc_e($t[0]) . '</b><br><span class="mini">' . wc_e($t[1]) . '</span></td>'; }
echo '</tr></table></div>';
wc_box_end();

/* ---------- 登录后：最近文件 ---------- */
if ($u) {
    $recent = wc_db_rows('SELECT * FROM wc_file WHERE uid=? AND del_time=0 ORDER BY add_time DESC LIMIT 6', array((int)$u['id']));
    wc_box_start('我的最近上传', '<a href="' . wc_e(wc_url('files.php')) . '">全部文件</a> | <a href="' . wc_e(wc_url('upload.php')) . '">继续上传</a>');
    if (!$recent) {
        echo '<div class="empty">还没有上传过文件。' . ((int)$u['verified'] === 0 ? '请先完成邮箱验证。' : '<a href="' . wc_e(wc_url('upload.php')) . '">现在去上传</a>。') . '</div>';
    } else {
        echo '<table class="tb list"><tr class="hd"><td width="40"></td><td>名称</td><td width="90" align="right">大小</td>'
            . '<td width="100">时间</td><td width="130">操作</td></tr>';
        foreach ($recent as $f) {
            echo '<tr><td>' . wc_file_icon($f['kind'], $f['ext']) . '</td>'
                . '<td><a href="' . wc_e(wc_url('preview.php?id=' . (int)$f['id'])) . '" target="_blank">' . wc_e(wc_cut_str($f['name'], 40)) . '</a></td>'
                . '<td align="right">' . wc_e(wc_format_size($f['size'])) . '</td><td>' . wc_e(wc_human_time($f['add_time'])) . '</td>'
                . '<td><a href="' . wc_e(wc_url('files.php?act=share&id=' . (int)$f['id'])) . '">分享</a> '
                . '<a href="' . wc_e(wc_url('api.php?do=download&id=' . (int)$f['id'])) . '">下载</a></td></tr>';
        }
        echo '</table>';
    }
    wc_box_end();
}

/* ---------- 公告 ---------- */
if ($ann && wc_setting('announce_open', '1')) {
    wc_box_start('站点公告', '<a href="' . wc_e(wc_url('announce.php')) . '">全部公告</a>');
    echo '<ul class="annList">';
    foreach ($ann as $a) {
        echo '<li>' . ($a['top'] ? '<span class="tagTop">置顶</span>' : '')
            . '<a href="' . wc_e(wc_url('announce.php?id=' . (int)$a['id'])) . '">' . wc_e($a['title']) . '</a>'
            . '<span class="mini">　' . wc_e(date('Y-m-d', (int)$a['add_time'])) . '　浏览 ' . (int)$a['view'] . '</span>'
            . '<div class="annBrief">' . wc_e(wc_cut_str(strip_tags((string)$a['content']), 90)) . '</div></li>';
    }
    echo '</ul>';
    wc_box_end();
}

/* ---------- 公开分享 ---------- */
if ($pubOn && ($latest || $hot)) {
    echo '<table class="twoCol"><tr><td width="50%" valign="top">';
    wc_box_start('最新公开分享', '<a href="' . wc_e(wc_url('rank.php')) . '">查看全部</a>');
    if (!$latest) { echo '<div class="empty">暂时还没有公开的分享。站长可在后台关闭本栏目。</div>'; }
    else {
        echo '<ul class="linkList">';
        foreach ($latest as $x) {
            echo '<li>' . wc_file_icon($x['kind'], $x['ext']) . ' <a href="' . wc_e(wc_share_page_url($x['code'])) . '">' . wc_e(wc_cut_str($x['name'], 28)) . '</a>'
                . '<span class="mini">　' . wc_e(wc_format_size($x['size'])) . '　' . wc_e($x['nick'] !== '' ? $x['nick'] : '站长') . '</span></li>';
        }
        echo '</ul>';
    }
    wc_box_end();
    echo '</td><td width="50%" valign="top">';
    wc_box_start('下载排行', '<a href="' . wc_e(wc_url('rank.php')) . '">完整榜单</a>');
    if (!$hot) { echo '<div class="empty">暂无排行数据。</div>'; }
    else {
        echo '<ol class="rankList">';
        foreach ($hot as $x) {
            echo '<li><a href="' . wc_e(wc_share_page_url($x['code'])) . '">' . wc_e(wc_cut_str($x['name'], 26)) . '</a>'
                . '<span class="mini">　' . (int)$x['down'] . ' 次</span></li>';
        }
        echo '</ol>';
    }
    wc_box_end();
    echo '</td></tr></table>';
}

wc_page_foot();
