<?php
/** 我的分享：外链列表、编辑、关闭、重置短码、文件夹分享 */
require_once __DIR__ . '/core/bootstrap.php';
wc_require_login();
$u = wc_auth_user();

$tab = wc_get('tab', 'all');
$page = max(1, wc_getint('p'));
$size = 20;
$where = 's.uid=?';
if ($tab === 'on') { $where .= ' AND s.status=1 AND (s.expire=0 OR s.expire>' . time() . ')'; }
if ($tab === 'off') { $where .= ' AND (s.status=0 OR (s.expire>0 AND s.expire<' . time() . '))'; }
$join = 'FROM wc_share s LEFT JOIN wc_file f ON f.id=s.file_id LEFT JOIN wc_folder d ON d.id=s.folder_id WHERE ' . $where;
$total = (int)wc_db_one("SELECT COUNT(*) $join", array((int)$u['id']));
$rows = wc_db_rows("SELECT s.*, f.name AS fname, f.size, f.ext, f.kind, f.del_time, d.name AS dname $join"
    . ' ORDER BY s.id DESC LIMIT ' . (($page - 1) * $size) . ",$size", array((int)$u['id']));

wc_page_head('我的分享');
wc_crumbs(array(array('我的文件', wc_url('files.php')), '我的分享'));
wc_box_start('我的分享', '<a href="' . wc_e(wc_url('upload.php')) . '">去上传</a>');

echo '<div class="toolbar">'
    . '<a href="' . wc_e(wc_url('shares.php')) . '"' . ($tab === 'all' ? ' class="on"' : '') . '>全部</a> '
    . '<a href="' . wc_e(wc_url('shares.php?tab=on')) . '"' . ($tab === 'on' ? ' class="on"' : '') . '>有效</a> '
    . '<a href="' . wc_e(wc_url('shares.php?tab=off')) . '"' . ($tab === 'off' ? ' class="on"' : '') . '>已关闭/过期</a>'
    . '<span class="mini">　提示：删除外链记录不会删除文件本身。</span></div>';

/* 文件夹整体分享 */
$folders = wc_folder_tree((int)$u['id'], 0);
$actFolder = wc_getint('folder');
wc_box_start('分享整个文件夹', '<span class="mini">访客打开后可看到列表并打包下载</span>');
echo '<form method="post" action="' . wc_e(wc_url('api.php')) . '" class="frm">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="share_folder"><input type="hidden" name="back" value="shares.php">'
    . '<table class="frmTb"><tr><th>文件夹</th><td><select name="fid"><option value="0">请选择</option>';
foreach ($folders as $k) {
    echo '<option value="' . (int)$k['id'] . '"' . ($actFolder === (int)$k['id'] ? ' selected' : '') . '>'
        . str_repeat('　', (int)$k['depth']) . '└ ' . wc_e($k['name']) . '（' . (int)$k['cnt'] . ' 个文件）</option>';
}
echo '</select></td></tr>'
    . '<tr><th>有效期</th><td><select name="days"><option value="0">永久</option><option value="7">7 天</option>'
    . '<option value="30">30 天</option><option value="90">90 天</option></select></td></tr>'
    . '<tr><th>访问密码</th><td><input type="text" name="pwd" size="12" maxlength="16"> <span class="note">留空则无需密码</span></td></tr>'
    . '<tr><th>选项</th><td><label><input type="checkbox" name="direct" value="1"> 允许直链打包</label>　'
    . (wc_setting('index_show_share', '1') ? '<label><input type="checkbox" name="pub" value="1"> 公开到首页/排行</label>' : '') . '</td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="生成文件夹外链"></td></tr></table></form>';
wc_box_end();

if (!$rows) {
    echo '<div class="empty">你还没有创建任何外链。到 <a href="' . wc_e(wc_url('files.php')) . '">文件管理</a> 里点某个文件的「分享」即可生成。</div>';
} else {
    echo '<table class="tb list"><tr class="hd"><td width="150">短码 / 链接</td><td>名称</td><td width="60">对象</td>'
        . '<td width="80" align="right">大小</td><td width="50" align="center">浏览</td><td width="50" align="center">下载</td>'
        . '<td width="110">有效期</td><td width="70">属性</td><td width="180">操作</td></tr>';
    foreach ($rows as $x) {
        $isFolder = (int)$x['folder_id'] > 0;
        $name = $isFolder ? $x['dname'] : $x['fname'];
        $expired = (int)$x['expire'] > 0 && $x['expire'] < time();
        $st = (int)$x['status'] === 0 ? '<span class="red">已关闭</span>' : ($expired ? '<span class="red">已过期</span>' : '<span class="green">有效</span>');
        echo '<tr><td><a href="' . wc_e(wc_share_page_url($x['code'])) . '" target="_blank" class="mono">' . wc_e($x['code']) . '</a>'
            . '<br><span class="mini">' . wc_e(date('Y-m-d', (int)$x['add_time'])) . '</span></td>'
            . '<td>' . wc_e(wc_cut_str($name, 34)) . ($isFolder ? '/' : '') . '</td>'
            . '<td>' . ($isFolder ? '文件夹' : '文件') . '</td>'
            . '<td align="right">' . wc_e(wc_format_size($isFolder ? 0 : $x['size'])) . '</td>'
            . '<td align="center">' . (int)$x['view'] . '</td><td align="center">' . (int)$x['down'] . '</td>'
            . '<td>' . ($x['expire'] > 0 ? wc_e(date('Y-m-d', (int)$x['expire'])) : '永久')
            . ($x['max_down'] > 0 ? '<br><span class="mini">上限 ' . (int)$x['max_down'] . '</span>' : '') . '</td>'
            . '<td>' . ($x['pwd'] !== '' ? '<span class="miniTag">密码</span>' : '')
            . ($x['direct'] ? '<span class="miniTag">直链</span>' : '')
            . ($x['pub'] ? '<span class="miniTag">公开</span>' : '') . ' ' . $st . '</td>'
            . '<td class="ops">'
            . '<a href="' . wc_e(wc_url('shares.php?act=edit&code=' . rawurlencode($x['code']))) . '">编辑</a> '
            . ((int)$x['status'] === 1
                ? '<a href="' . wc_e(wc_api_url('share_close', array('code' => $x['code']))) . '">关闭</a>'
                : '<a href="' . wc_e(wc_api_url('share_open', array('code' => $x['code']))) . '">开启</a>')
            . ' <a href="' . wc_e(wc_api_url('share_reset', array('code' => $x['code']))) . '" onclick="return confirm(\'重置后旧地址立即失效\')">重置短码</a> '
            . '<a class="red" href="' . wc_e(wc_api_url('share_del', array('code' => $x['code']))) . '" onclick="return confirm(\'删除该外链记录？\')">删除</a>'
            . '</td></tr>';
    }
    echo '</table>';
}

echo wc_paginate($total, $page, $size, wc_url('shares.php?tab=' . $tab . '&p={p}'));
wc_box_end();

/* 编辑表单 */
if (wc_get('act') === 'edit' && wc_get('code') !== '') {
    $one = wc_db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(wc_get('code'), (int)$u['id']));
    if ($one) {
        wc_box_start('编辑外链 ' . wc_e($one['code']), '<a href="' . wc_e(wc_url('shares.php')) . '">关闭</a>');
        echo '<form method="post" action="' . wc_e(wc_url('api.php')) . '" class="frm">' . wc_csrf_field()
            . '<input type="hidden" name="do" value="share_edit"><input type="hidden" name="code" value="' . wc_e($one['code']) . '">'
            . '<input type="hidden" name="back" value="shares.php">'
            . '<table class="frmTb">'
            . '<tr><th>当前指向</th><td>' . wc_e($one['title']) . '</td></tr>'
            . '<tr><th>有效期</th><td><select name="days">'
            . '<option value="0">永久</option><option value="1">1 天</option><option value="7">7 天</option>'
            . '<option value="30">30 天</option><option value="90">90 天</option><option value="365">1 年</option></select> '
            . '<span class="note">当前到期：' . ($one['expire'] > 0 ? wc_e(date('Y-m-d H:i', (int)$one['expire'])) : '永久') . '；选择后会重新计算</span></td></tr>'
            . '<tr><th>访问密码</th><td><input type="text" name="pwd" size="14" maxlength="16" placeholder="不改请留空">'
            . '<div class="note">填 <b>-</b> 表示清除密码；当前' . ($one['pwd'] !== '' ? '已设置密码' : '未设置密码') . '。</div></td></tr>'
            . '<tr><th>下载上限</th><td><input type="number" name="max_down" size="6" min="0" value="' . (int)$one['max_down'] . '"> <span class="note">0 不限，已下载 ' . (int)$one['down'] . ' 次</span></td></tr>'
            . '<tr><th>开关</th><td><label><input type="checkbox" name="direct" value="1"' . ($one['direct'] ? ' checked' : '') . '> 允许直链</label>　'
            . (wc_setting('index_show_share', '1') ? '<label><input type="checkbox" name="pub" value="1"' . ($one['pub'] ? ' checked' : '') . '> 公开到首页/排行</label>' : '')
            . '</td></tr>'
            . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存">　<a href="' . wc_e(wc_url('shares.php')) . '">取消</a></td></tr>'
            . '</table></form>';
        wc_box_end();
    }
}
wc_page_foot();
