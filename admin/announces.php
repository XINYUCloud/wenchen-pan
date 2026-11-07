<?php
/** 公告管理：新增、编辑、置顶、发布/草稿、删除 */
require_once __DIR__ . '/common.php';
require_admin();

$act = cp_action();
$id = getint('id') > 0 ? getint('id') : postint('id');

if ($act === 'save' || $act === 'add') {
    $title = mb_substr(clean_name(post('title'), ''), 0, 190);
    $content = ann_clean(post('content'));
    $top = post('top') === '1' ? 1 : 0;
    $status = post('status') === '1' ? 1 : 0;
    if ($title === '') { cp_back('标题不能为空', true); }
    if ($id > 0) {
        $old = db_row('SELECT id FROM wc_announce WHERE id=?', array($id));
        if (!$old) { cp_back('公告不存在', true); }
        db_exec('UPDATE wc_announce SET title=?, content=?, top=?, status=?, edit_time=? WHERE id=?', array($title, $content, $top, $status, time(), $id));
        log_write('admin', '编辑公告', '#' . $id . ' ' . $title, auth_uid(), admin_nick());
        cp_back('公告已更新');
    }
    $nid = db_insert('wc_announce', array(
        'title' => $title, 'content' => $content, 'author' => admin_nick(),
        'top' => $top, 'status' => $status, 'add_time' => time(), 'edit_time' => time(),
    ));
    if (post('push') === '1' && $status === 1) {
        foreach (db_col('SELECT id FROM wc_user WHERE status<>2') as $ui) {
            notice_add((int)$ui, '新公告：' . $title, mb_substr(strip_tags($content), 0, 300), 'announce');
        }
    }
    log_write('admin', '发布公告', '#' . $nid . ' ' . $title, auth_uid(), admin_nick());
    cp_back('公告已创建' . (post('push') === '1' ? '，并已推送站内信' : ''));
}
if ($act === 'top') {
    $a = db_row('SELECT * FROM wc_announce WHERE id=?', array($id));
    if (!$a) { cp_back('公告不存在', true); }
    db_exec('UPDATE wc_announce SET top=? WHERE id=?', array((int)$a['top'] === 1 ? 0 : 1, $id));
    cp_back((int)$a['top'] === 1 ? '已取消置顶' : '已置顶');
}
if ($act === 'pub') {
    $a = db_row('SELECT * FROM wc_announce WHERE id=?', array($id));
    if (!$a) { cp_back('公告不存在', true); }
    db_exec('UPDATE wc_announce SET status=? WHERE id=?', array((int)$a['status'] === 1 ? 0 : 1, $id));
    cp_back((int)$a['status'] === 1 ? '已下线为草稿' : '已发布');
}
if ($act === 'del') {
    db_exec('DELETE FROM wc_announce WHERE id=?', array($id));
    log_write('admin', '删除公告', '#' . $id, auth_uid(), admin_nick());
    cp_back('公告已删除');
}

$edit = null;
if (isset($_GET['add'])) { $edit = array('id' => 0, 'title' => '', 'content' => '', 'top' => 0, 'status' => 1, 'view' => 0, 'add_time' => time(), 'author' => admin_nick()); }
elseif ($id > 0) { $edit = db_row('SELECT * FROM wc_announce WHERE id=?', array($id)); }

$page = max(1, getint('p'));
$size = 20;
$total = (int)db_count('wc_announce');
$rows = db_rows('SELECT * FROM wc_announce ORDER BY top DESC, id DESC LIMIT ' . (($page - 1) * $size) . ",$size");

cp_header('公告管理', 'announces.php');

if ($edit !== null) {
    cp_panel($edit['id'] ? '编辑公告 #' . (int)$edit['id'] : '新增公告', '<a href="' . e(cp_url('announces.php')) . '">返回列表</a>');
    echo '<form method="post" action="' . e(cp_url('announces.php')) . '">' . csrf_field()
        . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . (int)$edit['id'] . '">'
        . '<table class="cpTb cpForm">'
        . '<tr><th>标题</th><td><input type="text" name="title" size="70" maxlength="190" value="' . e($edit['title']) . '" required></td></tr>'
        . '<tr><th>正文</th><td><textarea name="content" class="txtArea" rows="12">' . e($edit['content']) . '</textarea>'
        . '<span class="note">支持基础 HTML（p/br/b/i/u/a/img/ul/ol/table），script、iframe、on* 事件会被自动清除。</span></td></tr>'
        . '<tr><th>选项</th><td><label><input type="checkbox" name="top" value="1"' . ((int)$edit['top'] ? ' checked' : '') . '> 置顶</label>　'
        . '<label><input type="checkbox" name="status" value="1"' . ((int)$edit['status'] ? ' checked' : '') . '> 发布（不勾选则为草稿）</label>　'
        . ($edit['id'] ? '' : '<label><input type="checkbox" name="push" value="1"> 同时推送站内信给全部正常账号</label>') . '</td></tr>'
        . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存">'
        . '　<a href="' . e(url('announce.php' . ($edit['id'] ? '?id=' . (int)$edit['id'] : ''))) . '" target="_blank">前台预览</a>'
        . '　<a href="' . e(cp_url('announces.php')) . '">取消</a></td></tr>'
        . '</table></form>';
    cp_panel_end();
}

cp_panel('公告列表', '<a class="btn" href="' . e(cp_url('announces.php', array('add' => 1))) . '">新增公告</a>');
if (!$rows) {
    echo '<div class="empty">还没有公告。</div>';
} else {
    echo '<table class="cpTb"><tr class="hd"><td width="50">ID</td><td>标题</td><td width="70">置顶</td><td width="70">状态</td>'
        . '<td width="60">浏览</td><td width="110">作者</td><td width="120">时间</td><td width="190">操作</td></tr>';
    foreach ($rows as $x) {
        echo '<tr class="r' . ((int)$x['id'] % 2) . '"><td>' . (int)$x['id'] . '</td>'
            . '<td><a href="?id=' . (int)$x['id'] . '">' . e(cut_str($x['title'], 46)) . '</a></td>'
            . '<td>' . ((int)$x['top'] ? '是' : '-') . '</td>'
            . '<td>' . ((int)$x['status'] ? '<span class="ok">已发布</span>' : '<span class="mini">草稿</span>') . '</td>'
            . '<td>' . (int)$x['view'] . '</td><td>' . e($x['author']) . '</td>'
            . '<td>' . e(date('y-m-d H:i', (int)$x['add_time'])) . '</td>'
            . '<td class="ops"><a href="?id=' . (int)$x['id'] . '">编辑</a> '
            . cp_act('top', $x['top'] ? '取消置顶' : '置顶', array('id' => (int)$x['id'])) . ' '
            . cp_act('pub', $x['status'] ? '下线' : '发布', array('id' => (int)$x['id'])) . ' '
            . cp_act('del', '删除', array('id' => (int)$x['id']), '删除该公告？', 'danger') . '</td></tr>';
    }
    echo '</table>';
    echo cp_page($total, $page, $size, cp_url('announces.php'));
}
cp_panel_end();
cp_foot();

/** 公告正文清洗：去掉脚本、框架与内联事件 */
function ann_clean($html)
{
    $html = (string)$html;
    $html = preg_replace('#<\s*(script|style|iframe|frame|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
    $html = preg_replace('#<\s*(script|style|iframe|frame|object|embed)[^>]*/?>#is', '', $html);
    $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
    $html = preg_replace('#(javascript|data|vbscript)\s*:#i', '', $html);
    return mb_substr(trim($html), 0, 60000);
}
