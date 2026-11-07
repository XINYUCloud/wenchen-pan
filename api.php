<?php
/**
 * 前台动作接口：所有增删改、分享、下载、秒传、URL 转存都走这里
 * GET 类破坏性动作需带 k=CSRF token；AJAX 返回 JSON，普通表单跳转回原页
 */
require_once __DIR__ . '/core/bootstrap.php';
require_login();
$u = auth_user();
$do = get('do') !== '' ? get('do') : post('do');
$back = get('back') !== '' ? get('back') : post('back');
if ($back === '' || !preg_match('#^[A-Za-z0-9_./?&=%-]+$#', $back)) { $back = 'files.php'; }

if (!is_post()) {
    if ($do !== 'download' && $do !== 'folder_zip' && get('k') !== csrf_token()) {
        json_err('请求校验失败，请刷新页面重试', array('code' => 'csrf'));
    }
} else {
    csrf_check();
}

/** 回到来源页并带提示 */
function api_back($msg, $bad = false)
{
    global $back, $u;
    if (is_ajax()) {
        $r = array('ok' => $bad ? 0 : 1, 'msg' => $msg, 'url' => url($back));
        json_out($r);
    }
    alert($msg, url($back), $bad ? '操作失败' : '操作成功', false);
    // 提示页会自动跳转
    exit;
}

function own_file($id)
{
    global $u;
    $f = db_row('SELECT * FROM wc_file WHERE id=? AND uid=?', array((int)$id, (int)$u['id']));
    if (!$f) { api_back('文件不存在或不属于你', true); }
    return $f;
}

function own_folder($id)
{
    global $u;
    $d = db_row('SELECT * FROM wc_folder WHERE id=? AND uid=?', array((int)$id, (int)$u['id']));
    if (!$d) { api_back('文件夹不存在或不属于你', true); }
    return $d;
}

switch ($do) {

    /* ---------- 下载 ---------- */
    case 'download':
        $f = own_file(rqint('id'));
        if ((int)$f['del_time'] > 0) { api_back('文件在回收站中，请先还原', true); }
        $abs = file_abs($f);
        if ($abs === '') { api_back('实体文件缺失，请联系管理员', true); }
        if (!rate_limit('dl:' . (int)$u['id'] . ':' . ip(), setting_int('limit_download_ip', 300), 60)) {
            api_back('下载过于频繁，请稍后再试', true);
        }
        db_exec('UPDATE wc_file SET `down`=`down`+1, traffic=traffic+? WHERE id=?', array((int)$f['size'], (int)$f['id']));
        stat_touch('down_cnt');
        stat_touch('down_bytes', (int)$f['size']);
        $disp = get('a') === 'inline' ? 'inline' : 'attachment';
        if ($disp === 'inline' && in_array($f['ext'], array('svg', 'html', 'htm'), true)) {
            api_back('出于安全考虑，该类型不支持浏览器内直接打开，请下载后查看', true);
        }
        $bytes = stream_file($abs, $f['name'], $disp);
        if ($bytes > 0 && $bytes < (int)$f['size']) {
            db_exec('UPDATE wc_file SET `down`=`down`-1 WHERE id=?', array((int)$f['id']));
        }
        exit;

    /* ---------- 收藏 ---------- */
    case 'star':
    case 'unstar':
        $f = own_file(rqint('id'));
        db_exec('UPDATE wc_file SET star=? WHERE id=?', array($do === 'star' ? 1 : 0, (int)$f['id']));
        api_back($do === 'star' ? '已加入收藏' : '已取消收藏');

    /* ---------- 重命名 / 说明 / 移动 / 复制 ---------- */
    case 'rename':
        $f = own_file(rqint('id'));
        $name = clean_name(post('name'), $f['name']);
        db_exec('UPDATE wc_file SET name=? WHERE id=?', array($name, (int)$f['id']));
        db_exec('UPDATE wc_share SET title=? WHERE file_id=?', array($name . '（改名后）', (int)$f['id']));
        api_back('已重命名为：' . e($name));

    case 'note':
        $f = own_file(rqint('id'));
        $note = mb_substr(clean_name(post('note'), ''), 0, 200);
        $tags = mb_substr(preg_replace('/[<>&"\'\\\\]/', '', post('tags')), 0, 200);
        db_exec('UPDATE wc_file SET note=?, tags=? WHERE id=?', array($note, $tags, (int)$f['id']));
        api_back('说明与标签已保存');

    case 'move':
        $f = own_file(rqint('id'));
        $to = postint('fid');
        if ($to > 0) { own_folder($to); }
        if ($to === (int)$f['folder']) { api_back('文件已在该目录中', true); }
        db_exec('UPDATE wc_file SET folder=? WHERE id=?', array($to, (int)$f['id']));
        api_back('已移动 ' . e($f['name']));

    case 'copy':
        $f = own_file(rqint('id'));
        $chk = quota_check($u, (int)$f['size']);
        if ($chk !== true) { api_back($chk, true); }
        $id = db_insert('wc_file', array(
            'uid' => (int)$u['id'], 'folder' => (int)$f['folder'], 'name' => $f['name'], 'ext' => $f['ext'],
            'kind' => $f['kind'], 'size' => (int)$f['size'], 'sha1' => $f['sha1'], 'path' => $f['path'],
            'tags' => $f['tags'], 'note' => $f['note'], 'add_time' => time(),
        ));
        db_exec('UPDATE wc_user SET used=used+? WHERE id=?', array((int)$f['size'], (int)$u['id']));
        api_back('已复制一份，新文件编号 #' . $id);

    /* ---------- 删除 / 还原 / 彻底删除 ---------- */
    case 'del':
        $ids = isset($_REQUEST['file']) && is_array($_REQUEST['file']) ? intarr($_REQUEST['file']) : array(rqint('id'));
        $n = file_soft_delete($ids, (int)$u['id']);
        api_back('已将 ' . $n . ' 个文件放入回收站');

    /* ---------- 批量操作（前台文件管理页表单） ---------- */
    case 'batch':
        $op = post('batch');
        $ids = intarr(isset($_POST['file']) ? $_POST['file'] : array());
        $to = postint('tofid');
        if ($op === '' || $op === '0') { api_back('请先选择批量操作', true); }
        if (!$ids) { api_back('请先勾选文件', true); }

        if ($op === 'move') {
            if (post('tofid') === '') { api_back('请选择目标文件夹（根目录请选「根目录」）', true); }
            if ($to > 0) { own_folder($to); }
            $n = 0;
            foreach ($ids as $i) {
                $f = db_row('SELECT id FROM wc_file WHERE id=? AND uid=?', array($i, (int)$u['id']));
                if ($f) { db_exec('UPDATE wc_file SET folder=? WHERE id=?', array($to, $i)); $n++; }
            }
            api_back('已移动 ' . $n . ' 个文件');
        }
        if ($op === 'del') {
            $n = file_soft_delete($ids, (int)$u['id']);
            api_back('已将 ' . $n . ' 个文件放入回收站');
        }
        if ($op === 'star' || $op === 'unstar') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $n = db_exec('UPDATE wc_file SET star=? WHERE uid=? AND id IN (' . $ph . ')',
                array_merge(array($op === 'star' ? 1 : 0, (int)$u['id']), $ids));
            api_back('已更新 ' . $n . ' 个文件的收藏状态');
        }
        if ($op === 'share') {
            if ((int)$u['verified'] === 0) { api_back('邮箱未验证，不能生成外链', true); }
            $made = array();
            foreach ($ids as $i) {
                $f = db_row('SELECT * FROM wc_file WHERE id=? AND uid=? AND del_time=0', array($i, (int)$u['id']));
                if (!$f) { continue; }
                $s = share_create((int)$u['id'], (int)$f['id'], 0, array('days' => postint('days'), 'direct' => post('direct') === '1'));
                $made[] = '<a href="' . e(share_page_url($s['code'])) . '" target="_blank">' . e(cut_str($f['name'], 24)) . '</a>';
                if (count($made) >= 20) { break; }
            }
            api_back('已生成 ' . count($made) . ' 条外链：<br>' . implode('<br>', $made));
        }
        if ($op === 'zip') {
            $zip = new ZipStream('wenchen_' . date('Ymd_His') . '.zip');
            $n = zip_add_files($zip, $ids);
            if ($n === 0) { api_back('所选文件无法打包', true); }
            $zip->finish();
            exit;
        }
        api_back('不支持的批量操作：' . e($op), true);

    case 'batch_del':
        $n = file_soft_delete($_POST['file'], (int)$u['id']);
        api_back('已将 ' . $n . ' 个文件放入回收站');

    case 'restore':
        if (rqint('all') === 1) {
            $ids = db_col('SELECT id FROM wc_file WHERE uid=? AND del_time>0', array((int)$u['id']));
        } else {
            $ids = isset($_REQUEST['file']) && is_array($_REQUEST['file']) ? intarr($_REQUEST['file']) : array(rqint('id'));
        }
        $n = file_restore($ids, (int)$u['id']);
        user_sync_used((int)$u['id']);
        api_back('已还原 ' . $n . ' 个文件');

    case 'purge':
    case 'batch_trash':
        $batch = post('batch');
        if (rqint('all') === 1) {
            $ids = db_col('SELECT id FROM wc_file WHERE uid=? AND del_time>0', array((int)$u['id']));
        } elseif ($do === 'batch_trash') {
            if (!in_array($batch, array('restore', 'purge'), true)) { api_back('请选择批量操作', true); }
            $ids = intarr(isset($_POST['file']) ? $_POST['file'] : array());
            if ($batch === 'restore') {
                $n = file_restore($ids, (int)$u['id']);
                user_sync_used((int)$u['id']);
                api_back('已还原 ' . $n . ' 个文件');
            }
        } else {
            $ids = isset($_REQUEST['file']) && is_array($_REQUEST['file']) ? intarr($_REQUEST['file']) : array(rqint('id'));
        }
        if (!$ids) { api_back('未选择文件', true); }
        $n = 0;
        foreach ($ids as $id) {
            $f = db_row('SELECT id,size FROM wc_file WHERE id=? AND uid=?', array((int)$id, (int)$u['id']));
            if ($f) { file_purge((int)$f['id']); $n++; }
        }
        user_sync_used((int)$u['id']);
        log_write('file', '彻底删除 ' . $n . ' 个文件', '', (int)$u['id'], $u['nick']);
        api_back('已彻底删除 ' . $n . ' 个文件，空间已释放');

    /* ---------- 文件夹 ---------- */
    case 'folder_add':
        $name = clean_name(post('name'), '');
        if ($name === '') { api_back('请填写文件夹名称', true); }
        $parent = postint('parent') > 0 ? postint('parent') : rqint('fid');
        if ($parent > 0) { own_folder($parent); }
        if (folder_depth($parent) >= 8) { api_back('目录层级最多 8 层', true); }
        if (db_row('SELECT id FROM wc_folder WHERE uid=? AND parent=? AND name=?', array((int)$u['id'], $parent, $name))) {
            api_back('同级已存在同名文件夹', true);
        }
        $id = db_insert('wc_folder', array('uid' => (int)$u['id'], 'name' => $name, 'parent' => $parent, 'add_time' => time()));
        api_back('文件夹「' . e($name) . '」已创建', false);

    case 'folder_rename':
        $d = own_folder(rqint('fid'));
        $name = clean_name(post('name'), $d['name']);
        db_exec('UPDATE wc_folder SET name=? WHERE id=?', array($name, (int)$d['id']));
        api_back('文件夹已改名');

    case 'folder_del':
        $d = own_folder(rqint('fid'));
        $files = db_col('SELECT id FROM wc_file WHERE uid=? AND folder=?', array((int)$u['id'], (int)$d['id']));
        $sub = db_col('SELECT id FROM wc_folder WHERE uid=? AND parent=?', array((int)$u['id'], (int)$d['id']));
        if ($sub) { api_back('请先清空子文件夹', true); }
        $n = 0;
        foreach ($files as $id) { file_soft_delete(array($id), (int)$u['id']); $n++; }
        db_exec('DELETE FROM wc_folder WHERE id=?', array((int)$d['id']));
        api_back('文件夹已删除，其中 ' . $n . ' 个文件进入回收站');

    case 'folder_zip':
        $fid = rqint('fid');
        if ($fid > 0) { $d = own_folder($fid); $name = $d['name']; } else { $d = null; $name = '我的文件'; }
        if (!rate_limit('zip:' . (int)$u['id'], 10, 60)) { api_back('打包过于频繁，请稍后再试', true); }
        $zip = new ZipStream(ASCII_fallback($name) . '.zip');
        $n = zip_add_folder($zip, (int)$u['id'], $fid, $fid > 0 ? $name : '');
        $zip->add_data("打包时间：" . date('Y-m-d H:i:s') . "\r\n共 " . $n . " 个文件\r\n来源：" . SITE_URL, 'README.txt');
        $cnt = $zip->finish();
        log_write('file', '打包下载文件夹', $name . ' 共' . $n . '个文件', (int)$u['id'], $u['nick']);
        exit;

    case 'batch_zip':
        $ids = intarr(isset($_POST['file']) ? $_POST['file'] : array());
        if (!$ids) { api_back('未选择文件', true); }
        $zip = new ZipStream('wenchen_' . date('Ymd_His') . '.zip');
        $n = 0;
        foreach ($ids as $id) {
            $f = db_row('SELECT * FROM wc_file WHERE id=? AND uid=? AND del_time=0', array($id, (int)$u['id']));
            if (!$f) { continue; }
            $abs = file_abs($f);
            if ($abs !== '') { $zip->add_file($abs, $f['name'], (int)$f['add_time']); $n++; }
        }
        if ($n === 0) { api_back('所选文件无法打包', true); }
        $zip->finish();
        exit;

    /* ---------- 分享 ---------- */
    case 'share':
    case 'share_create':
        $id = rqint('id') > 0 ? rqint('id') : postint('id');
        $f = own_file($id);
        if ((int)$u['verified'] === 0) { api_back('邮箱未验证，不能生成外链', true); }
        if ((int)$f['del_time'] > 0) { api_back('回收站中的文件不能分享', true); }
        if (!setting('share_open', '1')) { api_back('站点已关闭外链分享功能', true); }
        $opt = array(
            'days' => postint('days'),
            'pwd' => post('pwd'),
            'max_down' => postint('max_down'),
            'direct' => post('direct') === '1',
            'pub' => post('pub') === '1' && setting('index_show_share', '1'),
        );
        if ($opt['pwd'] !== '' && (strlen($opt['pwd']) < 4 || strlen($opt['pwd']) > 16)) { api_back('访问密码需 4-16 位', true); }
        $old = db_row('SELECT * FROM wc_share WHERE file_id=? AND status=1', array((int)$f['id']));
        if ($old && !post('new')) {
            api_back('该文件已有外链：<a href="' . e(share_page_url($old['code'])) . '" target="_blank">' . e(share_page_url($old['code'])) . '</a>');
        }
        $s = share_create((int)$u['id'], (int)$f['id'], 0, $opt);
        log_write('share', '创建文件外链', $f['name'], (int)$u['id'], $u['nick']);
        if (is_ajax()) {
            json_ok(array('code' => $s['code'], 'url' => share_page_url($s['code']), 'direct' => (int)$s['direct'] ? share_direct_url($s['code']) : ''));
        }
        alert('外链创建成功：<br><input type="text" size="50" readonly value="' . e(share_page_url($s['code'])) . '" onclick="this.select()">'
            . '<br>直链地址：<input type="text" size="50" readonly value="' . e(share_direct_url($s['code'])) . '" onclick="this.select()">',
            url('files.php'), '分享成功');

    case 'share_folder':
        $fid = postint('fid') > 0 ? postint('fid') : rqint('fid');
        $d = own_folder($fid);
        if ((int)$u['verified'] === 0) { api_back('邮箱未验证，不能生成外链', true); }
        $s = share_create((int)$u['id'], 0, $fid, array(
            'days' => postint('days'), 'pwd' => post('pwd'), 'max_down' => postint('max_down'), 'direct' => post('direct') === '1',
        ));
        api_back('文件夹外链已创建：<a href="' . e(share_page_url($s['code'])) . '" target="_blank">' . e(share_page_url($s['code'])) . '</a>');

    case 'share_edit':
        $s = db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(post('code'), (int)$u['id']));
        if (!$s) { api_back('分享不存在', true); }
        $days = postint('days');
        $data = array(
            'expire' => $days > 0 ? time() + $days * 86400 : (post('keep_expire') === '1' ? (int)$s['expire'] : 0),
            'max_down' => postint('max_down'),
            'direct' => post('direct') === '1' ? 1 : 0,
            'pub' => post('pub') === '1' && setting('index_show_share', '1') ? 1 : 0,
        );
        $pwd = post('pwd');
        if ($pwd === '-') { $data['pwd'] = ''; }
        elseif ($pwd !== '') {
            if (strlen($pwd) < 4 || strlen($pwd) > 16) { api_back('访问密码需 4-16 位（留空则清除请填 -）', true); }
            $data['pwd'] = password_hash($pwd, PASSWORD_DEFAULT);
            KV::del('shareauth:' . $s['code']);
        }
        db_update('wc_share', $data, 'code=?', array($s['code']));
        api_back('外链设置已保存');

    case 'share_del':
        $s = db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(rqstr('code'), (int)$u['id']));
        if (!$s) { api_back('分享不存在', true); }
        db_exec('DELETE FROM wc_share WHERE code=?', array($s['code']));
        db_exec('DELETE FROM wc_share_log WHERE code=?', array($s['code']));
        KV::del('shareauth:' . $s['code']);
        log_write('share', '删除外链', $s['code'], (int)$u['id'], $u['nick']);
        api_back('外链记录已删除（文件本身保留）');

    case 'share_close':
        $s = db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(rqstr('code'), (int)$u['id']));
        if (!$s) { api_back('分享不存在', true); }
        db_exec('UPDATE wc_share SET status=0 WHERE code=?', array($s['code']));
        api_back('外链已关闭');

    case 'share_open':
        $s = db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(rqstr('code'), (int)$u['id']));
        if (!$s) { api_back('分享不存在', true); }
        db_exec('UPDATE wc_share SET status=1 WHERE code=?', array($s['code']));
        api_back('外链已重新开启');

    case 'share_reset':
        $s = db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(rqstr('code'), (int)$u['id']));
        if (!$s) { api_back('分享不存在', true); }
        $new = new_share_code();
        db_exec('UPDATE wc_share SET code=?, down=0, view=0 WHERE code=?', array($new, $s['code']));
        KV::del('shareauth:' . $s['code']);
        api_back('短码已重置为：<a href="' . e(share_page_url($new)) . '" target="_blank">' . e(share_page_url($new)) . '</a>');

    /* ---------- URL 转存 ---------- */
    case 'fetch':
        if ((int)$u['verified'] === 0) { api_back('邮箱未验证，不能使用 URL 转存', true); }
        $r = remote_fetch(post('url'));
        if (is_string($r)) { api_back($r, true); }
        $chk = quota_check($u, $r['size']);
        if ($chk !== true) { @unlink($r['tmp']); api_back($chk, true); }
        $res = file_commit($r['tmp'], (int)$u['id'], postint('folder'), $r['name'], $r['size']);
        if (is_string($res)) { api_back($res, true); }
        if (is_ajax()) { json_ok(array('file' => file_json($res))); }
        alert('转存成功：' . e($r['name']) . '（' . format_size($r['size']) . '）', url('files.php?fid=' . postint('folder')), '转存完成');

    /* ---------- 秒传探测 ---------- */
    case 'dedup':
        if ((int)$u['verified'] === 0) { json_err('邮箱未验证，不能上传', array('code' => 'unverified')); }
        $sha1 = post('sha1');
        $size = postint('size');
        $folder = postint('folder');
        if (!preg_match('/^[0-9a-f]{40}$/i', (string)$sha1)) { json_err('SHA1 参数不正确'); }
        $r = file_dedup_commit((int)$u['id'], $folder, post('name'), $sha1, $size);
        if ($r) { json_ok(array('fast' => 1, 'file' => file_json($r))); }
        json_ok(array('fast' => 0));

    /* ---------- 站内信 ---------- */
    case 'notice_read':
        $id = rqint('id');
        db_exec('UPDATE wc_notice SET is_read=1 WHERE id=? AND uid=?', array($id, (int)$u['id']));
        api_back('已标记为已读');

    case 'notice_read_all':
        db_exec('UPDATE wc_notice SET is_read=1 WHERE uid=?', array((int)$u['id']));
        api_back('全部消息已标记为已读');

    case 'notice_del':
        db_exec('DELETE FROM wc_notice WHERE uid=? AND id=?', array((int)$u['id'], rqint('id')));
        api_back('消息已删除');

    default:
        api_back('未知操作：' . e($do), true);
}

/** 给前端用的文件 JSON 摘要 */
function file_json($f)
{
    return array(
        'id' => (int)$f['id'],
        'name' => $f['name'],
        'size' => (int)$f['size'],
        'size_text' => format_size($f['size']),
        'kind' => $f['kind'],
        'ext' => $f['ext'],
        'time' => date('Y-m-d H:i:s', (int)$f['add_time']),
        'thumb' => $f['thumb'] !== '' ? url('img.php?f=' . (int)$f['id']) : '',
        'preview' => url('preview.php?id=' . (int)$f['id']),
        'download' => url('api.php?do=download&id=' . (int)$f['id']),
    );
}
