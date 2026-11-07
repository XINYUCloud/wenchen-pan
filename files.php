<?php
/** 前台文件管理：文件夹树、列表/网格视图、排序筛选搜索、批量操作、单项操作表单 */
require_once __DIR__ . '/core/bootstrap.php';
wc_require_login();
$u = wc_auth_user();

$fid = wc_getint('fid');
$view = wc_get('view') === 'grid' ? 'grid' : 'list';
$order = wc_get('order', 'time');
$asc = wc_get('asc') === '1' ? 1 : 0;
$kind = wc_get('kind');
$q = wc_get('q');
$star = wc_getint('star');
$del = wc_getint('del');   // 1=只看回收站由 trash.php 负责，这里固定 0
$page = max(1, wc_getint('p'));
$size = 30;

if ($fid > 0 && !wc_folder_check_owner($fid, (int)$u['id'])) { wc_go('files.php'); }

$allowOrder = array('time' => 'add_time', 'name' => 'name', 'size' => 'size', 'down' => '`down`', 'kind' => 'kind', 'view' => 'view');
$ob = wc_db_order($order, $allowOrder, 'add_time');
$dir = $asc ? 'ASC' : 'DESC';

$where = 'uid=? AND del_time=0';
$args = array((int)$u['id']);
if ($kind !== '' && in_array($kind, array('image', 'video', 'audio', 'doc', 'archive', 'code', 'other'), true)) {
    $where .= ' AND kind=?';
    $args[] = $kind;
}
if ($star) { $where .= ' AND star=1'; }
if ($q !== '') { $where .= ' AND name LIKE ?'; $args[] = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $q) . '%'; }
if ((int)$fid > 0) { $where .= ' AND folder=' . (int)$fid; }
else { $where .= ' AND folder=0'; }

$total = (int)wc_db_one("SELECT COUNT(*) FROM wc_file WHERE $where", $args);
$rows = wc_db_rows("SELECT * FROM wc_file WHERE $where ORDER BY `$ob` $dir, id DESC LIMIT " . (($page - 1) * $size) . ",$size", $args);
$folders = wc_db_rows('SELECT * FROM wc_folder WHERE uid=? AND parent=? ORDER BY name', array((int)$u['id'], (int)$fid));

$act = wc_get('act');
$targetId = wc_getint('id');

wc_page_head('我的文件');
wc_crumbs(array_merge(array(array('我的文件', wc_url('files.php'))), array_map(function ($f) {
    return array($f['name'], wc_url('files.php?fid=' . (int)$f['id']));
}, wc_folder_breadcrumb($fid))));

/* ---------- 单项操作表单（无 JS 也可用） ---------- */
if ($act === 'frename') {
    $d = wc_db_row('SELECT * FROM wc_folder WHERE id=? AND uid=?', array(wc_getint('fid'), (int)$u['id']));
    if ($d) {
        wc_box_start('文件夹改名', '<a href="' . wc_e(wc_url('files.php?fid=' . $fid)) . '">关闭</a>');
        echo '<form method="post" action="' . wc_e(wc_url('api.php')) . '" class="frm"><table class="frmTb">' . wc_csrf_field()
            . '<input type="hidden" name="do" value="folder_rename"><input type="hidden" name="fid" value="' . (int)$d['id'] . '">'
            . '<input type="hidden" name="back" value="' . wc_e('files.php?fid=' . (int)$d['parent']) . '">'
            . '<tr><th>新名称</th><td><input type="text" name="name" size="30" maxlength="60" value="' . wc_e($d['name']) . '"></td></tr>'
            . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存">　<a href="' . wc_e(wc_url('files.php?fid=' . $fid)) . '">取消</a></td></tr>'
            . '</table></form>';
        wc_box_end();
    }
}

if ($act !== '' && $targetId > 0) {
    $f = wc_db_row('SELECT * FROM wc_file WHERE id=? AND uid=?', array($targetId, (int)$u['id']));
    if ($f) {
        wc_box_start('操作：' . wc_e(wc_cut_str($f['name'], 30)), '<a href="' . wc_e(wc_url('files.php?fid=' . $fid)) . '">关闭</a>');
        echo '<form method="post" action="' . wc_e(wc_url('api.php')) . '" class="frm"><table class="frmTb">' . wc_csrf_field();
        echo '<input type="hidden" name="do" value="' . wc_e($act) . '"><input type="hidden" name="id" value="' . (int)$f['id'] . '">';
        echo '<input type="hidden" name="back" value="' . wc_e('files.php?fid=' . $fid . '&view=' . $view) . '">';
        if ($act === 'rename') {
            echo '<tr><th>新文件名</th><td><input type="text" name="name" size="40" maxlength="200" value="' . wc_e($f['name']) . '"></td></tr>';
        } elseif ($act === 'note') {
            echo '<tr><th>文件说明</th><td><textarea name="note" rows="3" cols="50">' . wc_e($f['note']) . '</textarea></td></tr>'
                . '<tr><th>标签</th><td><input type="text" name="tags" size="40" value="' . wc_e($f['tags']) . '" placeholder="用逗号分隔，如：素材,2026">'
                . '<div class="note">标签用于筛选与检索。</div></td></tr>';
        } elseif ($act === 'move') {
            echo '<tr><th>移动到</th><td><select name="fid"><option value="0">根目录</option>';
            foreach (wc_folder_tree((int)$u['id'], 0) as $k) {
                if ((int)$k['id'] === $targetId) { continue; }
                echo '<option value="' . (int)$k['id'] . '">' . str_repeat('　', (int)$k['depth']) . '└ ' . wc_e($k['name']) . '</option>';
            }
            echo '</select></td></tr>';
        } elseif ($act === 'info') {
            $abs = wc_file_abs($f);
            echo '<tr><th>名称</th><td>' . wc_e($f['name']) . '</td></tr>'
                . '<tr><th>类型</th><td>' . wc_e(wc_kind_name($f['kind'])) . '（.' . wc_e($f['ext']) . '）' . ($abs !== '' ? '，真实类型：' . wc_e(wc_sniff_type($abs)) : ' <span class="red">实体文件缺失</span>') . '</td></tr>'
                . '<tr><th>大小</th><td>' . wc_format_size($f['size']) . '（' . number_format((int)$f['size']) . ' 字节）</td></tr>'
                . '<tr><th>SHA1</th><td class="mono">' . wc_e($f['sha1']) . '</td></tr>'
                . '<tr><th>MD5</th><td class="mono">' . wc_e($abs !== '' ? md5_file($abs) : '-') . '</td></tr>'
                . '<tr><th>上传时间</th><td>' . wc_e(date('Y-m-d H:i:s', (int)$f['add_time'])) . '</td></tr>'
                . '<tr><th>访问</th><td>预览 ' . (int)$f['view'] . ' 次，下载 ' . (int)$f['down'] . ' 次，累计流量 ' . wc_format_size($f['traffic']) . '</td></tr>'
                . '<tr><th>标签/说明</th><td>' . wc_e($f['tags']) . ' ' . wc_e($f['note']) . '</td></tr>';
        } elseif ($act === 'share') {
            $ex = wc_db_row('SELECT * FROM wc_share WHERE file_id=? AND status=1 ORDER BY id DESC LIMIT 1', array((int)$f['id']));
            echo '<tr><th>文件</th><td>' . wc_e($f['name']) . '（' . wc_format_size($f['size']) . '）</td></tr>'
                . '<tr><th>有效期</th><td><select name="days"><option value="0">永久</option><option value="1">1 天</option>'
                . '<option value="7">7 天</option><option value="30">30 天</option><option value="90">90 天</option></select></td></tr>'
                . '<tr><th>访问密码</th><td><input type="text" name="pwd" size="12" maxlength="16" placeholder="留空则无">'
                . '<div class="note">4-16 位，访客需输入密码才能下载。</div></td></tr>'
                . '<tr><th>下载次数上限</th><td><input type="number" name="max_down" size="6" min="0" value="0"> <span class="note">0 表示不限</span></td></tr>'
                . '<tr><th>直链</th><td><label><input type="checkbox" name="direct" value="1" ' . ($ex && $ex['direct'] ? 'checked' : '') . '> 允许 /d/ 直链直接输出文件流</label>'
            . (wc_setting('index_show_share', '1') ? '<br><label><input type="checkbox" name="pub" value="1" ' . ($ex && $ex['pub'] ? 'checked' : '') . '> 允许出现在首页与下载排行（公开展示，任何人都能浏览）</label>' : '')
            . '</td></tr>';
            if ($ex) {
                echo '<tr><th>当前外链</th><td><a href="' . wc_e(wc_share_page_url($ex['code'])) . '" target="_blank">' . wc_e(wc_share_page_url($ex['code'])) . '</a>'
                . ' ' . ($ex['expire'] > 0 ? '（到期 ' . wc_e(date('Y-m-d', (int)$ex['expire'])) . '）' : '（永久）')
                . '　已下载 ' . (int)$ex['down'] . ' 次 <a href="' . wc_e(wc_url('shares.php')) . '">管理我的分享</a></td></tr>'
                . '<tr><th>&nbsp;</th><td><label><input type="checkbox" name="new" value="1"> 忽略已有链接，另外生成一条新外链</label></td></tr>';
            }
        }
        echo '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="确认">'
            . '　<a href="' . wc_e(wc_url('files.php?fid=' . $fid)) . '">取消</a></td></tr></table></form>';
        wc_box_end();
    }
}

/* ---------- 工具条 ---------- */
$qstr = http_build_query(array('fid' => $fid, 'view' => $view, 'order' => $order, 'asc' => $asc, 'kind' => $kind ?: '', 'q' => $q, 'star' => $star ?: ''));
wc_box_start('文件位置：' . wc_e($fid ? ('文件夹 #' . $fid) : '根目录'),
    '<a href="' . wc_e(wc_url('upload.php?fid=' . $fid)) . '"><b>上传文件</b></a> | '
    . '<a href="' . wc_e(wc_url('files.php?view=' . ($view === 'grid' ? 'list' : 'grid') . '&fid=' . $fid)) . '">切换' . ($view === 'grid' ? '列表' : '网格') . '视图</a> | '
    . '<a href="' . wc_e(wc_url('trash.php')) . '">回收站</a> | <a href="' . wc_e(wc_url('shares.php')) . '">我的分享</a>');

echo '<div class="toolbar">'
    . '<form method="post" action="' . wc_e(wc_url('api.php')) . '" class="inl">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="folder_add"><input type="hidden" name="back" value="' . wc_e('files.php?fid=' . $fid) . '">'
    . '新建文件夹 <input type="text" name="name" size="16" maxlength="60" placeholder="文件夹名称"> <input type="submit" class="btn" value="创建"></form>'
    . '<form method="get" action="' . wc_e(wc_url('files.php')) . '" class="inl">'
    . '<input type="hidden" name="fid" value="' . (int)$fid . '"><input type="hidden" name="view" value="' . wc_e($view) . '">'
    . '搜索 <input type="text" name="q" size="14" value="' . wc_e($q) . '"> '
    . '<select name="kind"><option value="">全部类型</option>';
foreach (wc_kind_map() as $k => $v) { echo '<option value="' . $k . '"' . ($kind === $k ? ' selected' : '') . '>' . wc_e(wc_kind_name($k)) . '</option>'; }
echo '</select> <label><input type="checkbox" name="star" value="1"' . ($star ? ' checked' : '') . '>只看收藏</label> '
    . '<input type="submit" class="btn" value="筛选"> <a href="' . wc_e(wc_url('files.php?fid=' . $fid)) . '">重置</a></form>';
if ($fid > 0) {
    $parentFid = (int)wc_db_one('SELECT parent FROM wc_folder WHERE id=?', array($fid));
    echo '<a class="btn" href="' . wc_e(wc_url('files.php?fid=' . $parentFid . '&view=' . $view)) . '">返回上层</a>';
    echo '<a class="btn" href="' . wc_e(wc_url('api.php?do=folder_zip&fid=' . $fid)) . '">打包下载本层</a>';
}
echo '<div class="clr"></div></div>';

/* ---------- 批量操作 ---------- */
echo '<form method="post" action="' . wc_e(wc_url('api.php')) . '" id="batchForm">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="batch">'
    . '<input type="hidden" name="back" value="' . wc_e('files.php?' . $qstr) . '">';
echo wc_skeleton_rows(5, 5);

/* ---------- 文件夹 ---------- */
if ($folders) {
    echo '<table class="tb"><tr class="hd"><td width="24"><input type="checkbox" onclick="wcCheckAll(this,\'folder\')"></td><td>文件夹</td>';
    echo '<td width="80">文件数</td><td width="90">占用</td><td width="120">创建时间</td><td width="200">操作</td></tr>';
    foreach ($folders as $d) {
        $cnt = (int)wc_db_one('SELECT COUNT(*) FROM wc_file WHERE uid=? AND folder=? AND del_time=0', array((int)$u['id'], (int)$d['id']));
        $bytes = (int)wc_db_one('SELECT COALESCE(SUM(size),0) FROM wc_file WHERE uid=? AND folder=? AND del_time=0', array((int)$u['id'], (int)$d['id']));
        echo '<tr><td><input type="checkbox" name="folder[]" value="' . (int)$d['id'] . '"></td>'
            . '<td><a href="' . wc_e(wc_url('files.php?fid=' . (int)$d['id'] . '&view=' . $view)) . '" data-folder-id="' . (int)$d['id'] . '"><span class="fIcon fI_dir">夹</span><b>' . wc_e($d['name']) . '</b></a></td>'
            . '<td>' . $cnt . '</td><td>' . wc_format_size($bytes) . '</td><td>' . wc_e(date('Y-m-d', (int)$d['add_time'])) . '</td>'
            . '<td><a href="' . wc_e(wc_url('api.php?do=folder_zip&fid=' . (int)$d['id'])) . '">打包</a> '
            . '<a href="' . wc_e(wc_url('files.php?act=frename&fid=' . (int)$d['id'])) . '">改名</a> '
            . '<a href="' . wc_e(wc_api_url('folder_del', array('fid' => (int)$d['id']))) . '" onclick="return confirm(\'删除该文件夹？其中文件将进入回收站\')">删除</a> '
            . '<a href="' . wc_e(wc_url('shares.php?folder=' . (int)$d['id'])) . '">分享</a></td></tr>';
    }
    echo '</table>';
}

/* ---------- 文件 ---------- */
if (!$rows && !$folders) {
    echo '<div class="empty">这里还没有文件。' . ($q !== '' || $kind !== '' ? '当前筛选条件下没有结果，' : '')
        . '<a href="' . wc_e(wc_url('upload.php?fid=' . $fid)) . '">立即上传</a>。</div>';
} elseif ($view === 'grid') {
    echo '<table class="tb grid"><tr class="hd"><td colspan="' . (count($rows) ? 4 : 1) . '">网格视图：共 ' . $total . ' 个文件（本页 ' . count($rows) . '）</td></tr><tr>';
    $i = 0;
    foreach ($rows as $f) {
        if ($i % 5 === 0 && $i > 0) { echo '</tr><tr>'; }
        echo '<td class="cell"><label><input type="checkbox" name="file[]" value="' . (int)$f['id'] . '"></label>'
            . '<a class="gridThumb" href="' . wc_e(wc_url('preview.php?id=' . (int)$f['id'])) . '" target="_blank" title="' . wc_e($f['name']) . '">' . wc_file_thumb_html($f, 88) . '</a>'
            . '<div class="gName" title="' . wc_e($f['name']) . '">' . wc_e(wc_cut_str($f['name'], 12)) . '</div>'
            . '<div class="gSize">' . wc_format_size($f['size']) . '</div></td>';
        $i++;
    }
    while ($i % 5 !== 0) { echo '<td class="cell emptyCell">&nbsp;</td>'; $i++; }
    echo '</tr></table>';
} else {
    echo '<table class="tb list"><tr class="hd">'
        . '<td width="24"><input type="checkbox" onclick="wcCheckAll(this,\'file\')"></td>'
        . '<td width="44">&nbsp;</td><td>名称' . wc_sortlink($order, $asc, 'name', 'time', $qstr) . '</td>'
        . '<td width="70">类型</td><td width="90" align="right">大小' . wc_sortlink($order, $asc, 'size', 'time', $qstr) . '</td>'
        . '<td width="60" align="center">下载' . wc_sortlink($order, $asc, 'down', 'time', $qstr) . '</td>'
        . '<td width="100">上传时间' . wc_sortlink($order, $asc, 'time', 'time', $qstr) . '</td><td width="190">操作</td></tr>';
    foreach ($rows as $f) {
        $link = $f['kind'] === 'image' || $f['kind'] === 'video' || $f['kind'] === 'audio'
            ? wc_url('preview.php?id=' . (int)$f['id']) : wc_url('api.php?do=download&id=' . (int)$f['id']);
        echo '<tr class="r' . (substr((string)$f['id'], -1) % 2) . '" data-file-id="' . (int)$f['id'] . '">'
            . '<td><input type="checkbox" name="file[]" value="' . (int)$f['id'] . '"></td>'
            . '<td align="center">' . wc_file_thumb_html($f, 34) . '</td>'
            . '<td>' . ($f['star'] ? '<span class="star" title="已收藏">★</span>' : '')
            . '<a href="' . wc_e($link) . '" target="_blank" title="' . wc_e($f['note']) . '">' . wc_e(wc_cut_str($f['name'], 46)) . '</a>'
            . ($f['thumb'] ? ' <span class="miniTag">缩略</span>' : '')
            . ($f['tags'] !== '' ? ' <span class="miniTag">' . wc_e(wc_cut_str($f['tags'], 14)) . '</span>' : '')
            . '</td>'
            . '<td>' . wc_e(wc_kind_name($f['kind'])) . '</td>'
            . '<td align="right">' . wc_format_size($f['size']) . '</td>'
            . '<td align="center">' . (int)$f['down'] . '</td>'
            . '<td>' . wc_e(wc_human_time($f['add_time'])) . '</td>'
            . '<td class="ops">'
            . '<a href="javascript:;" data-copy-links="' . (int)$f['id'] . '" title="复制直链（图片/视频/文档）"><i class="ic ic-copy"></i>直链</a> '
            . '<a href="' . wc_e(wc_url('files.php?act=share&id=' . (int)$f['id'] . '&fid=' . $fid . '&view=' . $view)) . '">分享</a> '
            . '<a href="' . wc_e(wc_url('files.php?act=rename&id=' . (int)$f['id'] . '&fid=' . $fid . '&view=' . $view)) . '">改名</a> '
            . '<a href="' . wc_e(wc_url('files.php?act=move&id=' . (int)$f['id'] . '&fid=' . $fid)) . '">移动</a> '
            . '<a href="' . wc_e(wc_api_url('star', array('id' => (int)$f['id'], 'back' => 'files.php?fid=' . $fid))) . '">' . ($f['star'] ? '取消收藏' : '收藏') . '</a> '
            . '<a href="' . wc_e(wc_url('files.php?act=note&id=' . (int)$f['id'] . '&fid=' . $fid)) . '">说明</a> '
            . '<a href="' . wc_e(wc_url('files.php?act=info&id=' . (int)$f['id'] . '&fid=' . $fid)) . '">详情</a> '
            . '<a href="' . wc_e(wc_url('api.php?do=download&id=' . (int)$f['id'])) . '">下载</a> '
            . '<a href="' . wc_e(wc_api_url('del', array('id' => (int)$f['id'], 'back' => 'files.php?fid=' . $fid))) . '" data-confirm="放入回收站？">删除</a>'
            . '</td></tr>';
    }
    echo '</table>';
}

echo '<div class="batchBar">'
    . '选中项：'
    . '<select name="batch">'
    . '<option value="">选择批量操作</option>'
    . '<option value="del">删除放入回收站</option>'
    . '<option value="star">收藏</option>'
    . '<option value="unstar">取消收藏</option>'
    . '<option value="share">批量生成外链</option>'
    . '<option value="zip">打包下载 ZIP</option>'
    . '</select> ';
echo '<select name="tofid"><option value="">移动到…</option><option value="0">根目录</option>';
foreach (wc_folder_tree((int)$u['id'], 0) as $k) {
    echo '<option value="' . (int)$k['id'] . '">' . str_repeat('　', (int)$k['depth']) . '└ ' . wc_e($k['name']) . '</option>';
}
echo '</select> <select name="days"><option value="0">外链永久</option><option value="7">7 天</option><option value="30">30 天</option></select> '
    . '<input type="submit" class="btn" value="执行">'
    . '<span class="mini">共 ' . $total . ' 个文件，占用 ' . wc_format_size(array_sum(array_map(function ($r) { return (int)$r['size']; }, $rows))) . '（本页）</span>'
    . '</div></form>';

echo '<div class="batchBar" style="margin-top:8px"><span class="mini">批量直链导出（勾选文件后点击）：</span>'
    . '<a class="btn" href="javascript:;" data-export="txt">TXT</a>'
    . '<a class="btn" href="javascript:;" data-export="md">Markdown</a>'
    . '<a class="btn" href="javascript:;" data-export="html">HTML 列表</a>'
    . '<a class="btn" href="javascript:;" data-export="wall">图片墙相册</a>'
    . '<a class="btn" href="javascript:;" data-export="json">JSON</a>'
    . '<span class="mini">　未勾选时导出当前文件夹全部文件</span></div>';

echo wc_paginate($total, $page, $size, wc_url('files.php?' . $qstr . ($qstr ? '&' : '') . 'p={p}'));
wc_box_end();
wc_page_foot();

function wc_sortlink($cur, $asc, $col, $default, $qstr)
{
    if ($cur === $col) {
        return '<a href="' . wc_e(wc_url('files.php?' . preg_replace('/asc=\d/', 'asc=' . ($asc ? 0 : 1), $qstr) . '&order=' . $col)) . '" class="on">'
            . ($asc ? '▲' : '▼') . '</a>';
    }
    return '<a href="' . wc_e(wc_url('files.php?' . $qstr . '&order=' . $col . '&asc=0')) . '" class="sort">↕</a>';
}
