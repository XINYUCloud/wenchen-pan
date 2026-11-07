<?php
/** 站内消息：上传结果、外链被举报、空间告警、公告推送 */
require_once __DIR__ . '/core/bootstrap.php';
require_login();
$u = auth_user();
$uid = (int)$u['id'];

if (is_post()) {
    csrf_check();
    $do = post('do');
    if ($do === 'read') {
        db_exec('UPDATE wc_notice SET is_read=1 WHERE id=? AND uid=?', array(postint('id'), $uid));
    } elseif ($do === 'read_all') {
        db_exec('UPDATE wc_notice SET is_read=1 WHERE uid=?', array($uid));
    } elseif ($do === 'del') {
        db_exec('DELETE FROM wc_notice WHERE id=? AND uid=?', array(postint('id'), $uid));
    } elseif ($do === 'del_read') {
        db_exec('DELETE FROM wc_notice WHERE uid=? AND is_read=1', array($uid));
    }
    flash('操作已完成。');
    go('notice.php');
}

$page = max(1, getint('p'));
$size = 20;
$total = (int)db_count('wc_notice', 'uid=?', array($uid));
$rows = db_rows('SELECT * FROM wc_notice WHERE uid=? ORDER BY id DESC LIMIT ' . (($page - 1) * $size) . ",$size", array($uid));
$unread = notice_unread($uid);

page_head('站内消息');
crumbs(array(array('首页', url()), '站内消息'));
box_start('我的消息', '<span class="mini">未读 ' . $unread . ' 条 / 共 ' . $total . ' 条</span>');

echo '<div class="toolbar">'
    . '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="do" value="read_all">'
    . '<input type="submit" class="btn" value="全部标记已读"></form> '
    . '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="do" value="del_read">'
    . '<input type="submit" class="btn" value="清空已读" onclick="return confirm(\'删除所有已读消息？\')"></form>'
    . '</div>';

if (!$rows) {
    echo '<div class="empty">还没有收到消息。系统会在验证成功、外链被举报、空间不足时通知你。</div>';
} else {
    foreach ($rows as $n) {
        $typeName = array('system' => '系统', 'share' => '分享', 'quota' => '空间', 'report' => '举报', 'announce' => '公告');
        echo '<div class="msgBox' . ($n['is_read'] ? '' : ' msgNew') . '">'
            . '<div class="msgHd"><b>' . e($n['title']) . '</b>'
            . '<span class="tag">' . e(isset($typeName[$n['type']]) ? $typeName[$n['type']] : $n['type']) . '</span>'
            . ($n['is_read'] ? '' : '<span class="red">[未读]</span>')
            . '<span class="mini">　' . e(date('Y-m-d H:i', (int)$n['add_time'])) . '</span></div>'
            . '<div class="msgBd">' . nl2p($n['content']) . '</div>'
            . '<div class="msgFt"><form method="post" class="inl">' . csrf_field()
            . '<input type="hidden" name="do" value="' . ($n['is_read'] ? 'del' : 'read') . '">'
            . '<input type="hidden" name="id" value="' . (int)$n['id'] . '">'
            . '<input type="submit" class="btnS" value="' . ($n['is_read'] ? '删除这条' : '标记已读') . '"></form></div>'
            . '</div>';
    }
}
echo paginate($total, $page, $size, url('notice.php?p={p}'));
box_end();
page_foot();
