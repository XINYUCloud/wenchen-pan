<?php
/**
 * 前台动作接口：所有增删改、分享、下载、秒传、URL 转存都走这里
 * GET 类破坏性动作需带 k=CSRF token；AJAX 返回 JSON，普通表单跳转回原页
 */
define('WC_REQ_KIND', 'api');
require_once __DIR__ . '/core/bootstrap.php';
wc_require_login();
$u = wc_auth_user();
$do = wc_get('do') !== '' ? wc_get('do') : wc_post('do');
$back = wc_get('back') !== '' ? wc_get('back') : wc_post('back');
if ($back === '' || !preg_match('#^[A-Za-z0-9_./?&=%-]+$#', $back)) { $back = 'files.php'; }

if (!wc_is_post()) {
    if ($do !== 'download' && $do !== 'folder_zip' && wc_get('k') !== wc_csrf_token()) {
        wc_json_err('请求校验失败，请刷新页面重试', array('code' => 'csrf'));
    }
} else {
    wc_csrf_check();
}

/** 回到来源页并带提示 */
function wc_api_back($msg, $bad = false)
{
    global $back, $u;
    if (wc_is_ajax()) {
        $r = array('ok' => $bad ? 0 : 1, 'msg' => $msg, 'url' => wc_url($back));
        wc_json_out($r);
    }
    wc_alert($msg, wc_url($back), $bad ? '操作失败' : '操作成功', false);
    // 提示页会自动跳转
    exit;
}

function wc_own_file($id)
{
    global $u;
    $f = wc_db_row('SELECT * FROM wc_file WHERE id=? AND uid=?', array((int)$id, (int)$u['id']));
    if (!$f) { wc_api_back('文件不存在或不属于你', true); }
    return $f;
}

function wc_own_folder($id)
{
    global $u;
    $d = wc_db_row('SELECT * FROM wc_folder WHERE id=? AND uid=?', array((int)$id, (int)$u['id']));
    if (!$d) { wc_api_back('文件夹不存在或不属于你', true); }
    return $d;
}

switch ($do) {

    /* ---------- 下载 ---------- */
    case 'download':
        $f = wc_own_file(wc_rqint('id'));
        if ((int)$f['del_time'] > 0) { wc_api_back('文件在回收站中，请先还原', true); }
        $abs = wc_file_abs($f);
        if ($abs === '') { wc_api_back('实体文件缺失，请联系管理员', true); }
        if (!wc_rate_limit('dl:' . (int)$u['id'] . ':' . wc_ip(), wc_setting_int('limit_download_ip', 300), 60)) {
            wc_api_back('下载过于频繁，请稍后再试', true);
        }
        wc_db_exec('UPDATE wc_file SET `down`=`down`+1, traffic=traffic+? WHERE id=?', array((int)$f['size'], (int)$f['id']));
        wc_stat_touch('down_cnt');
        wc_stat_touch('down_bytes', (int)$f['size']);
        $disp = wc_get('a') === 'inline' ? 'inline' : 'attachment';
        if ($disp === 'inline' && in_array($f['ext'], array('svg', 'html', 'htm'), true)) {
            wc_api_back('出于安全考虑，该类型不支持浏览器内直接打开，请下载后查看', true);
        }
        $bytes = wc_stream_file($abs, $f['name'], $disp);
        if ($bytes > 0 && $bytes < (int)$f['size']) {
            wc_db_exec('UPDATE wc_file SET `down`=`down`-1 WHERE id=?', array((int)$f['id']));
        }
        exit;

    /* ---------- 收藏 ---------- */
    case 'star':
    case 'unstar':
        $f = wc_own_file(wc_rqint('id'));
        wc_db_exec('UPDATE wc_file SET star=? WHERE id=?', array($do === 'star' ? 1 : 0, (int)$f['id']));
        wc_api_back($do === 'star' ? '已加入收藏' : '已取消收藏');

    /* ---------- 重命名 / 说明 / 移动 / 复制 ---------- */
    case 'rename':
        $f = wc_own_file(wc_rqint('id'));
        $name = wc_clean_name(wc_post('name'), $f['name']);
        wc_db_exec('UPDATE wc_file SET name=? WHERE id=?', array($name, (int)$f['id']));
        wc_db_exec('UPDATE wc_share SET title=? WHERE file_id=?', array($name . '（改名后）', (int)$f['id']));
        wc_api_back('已重命名为：' . wc_e($name));

    case 'note':
        $f = wc_own_file(wc_rqint('id'));
        $note = mb_substr(wc_clean_name(wc_post('note'), ''), 0, 200);
        $tags = mb_substr(preg_replace('/[<>&"\'\\\\]/', '', wc_post('tags')), 0, 200);
        wc_db_exec('UPDATE wc_file SET note=?, tags=? WHERE id=?', array($note, $tags, (int)$f['id']));
        wc_api_back('说明与标签已保存');

    case 'move':
        $f = wc_own_file(wc_rqint('id'));
        $to = wc_postint('fid');
        if ($to > 0) { wc_own_folder($to); }
        if ($to === (int)$f['folder']) { wc_api_back('文件已在该目录中', true); }
        wc_db_exec('UPDATE wc_file SET folder=? WHERE id=?', array($to, (int)$f['id']));
        wc_api_back('已移动 ' . wc_e($f['name']));

    case 'copy':
        $f = wc_own_file(wc_rqint('id'));
        $chk = wc_quota_check($u, (int)$f['size']);
        if ($chk !== true) { wc_api_back($chk, true); }
        $id = wc_db_insert('wc_file', array(
            'uid' => (int)$u['id'], 'folder' => (int)$f['folder'], 'name' => $f['name'], 'ext' => $f['ext'],
            'kind' => $f['kind'], 'size' => (int)$f['size'], 'sha1' => $f['sha1'], 'path' => $f['path'],
            'tags' => $f['tags'], 'note' => $f['note'], 'add_time' => time(),
        ));
        wc_db_exec('UPDATE wc_user SET used=used+? WHERE id=?', array((int)$f['size'], (int)$u['id']));
        wc_api_back('已复制一份，新文件编号 #' . $id);

    /* ---------- 删除 / 还原 / 彻底删除 ---------- */
    case 'del':
        $ids = isset($_REQUEST['file']) && is_array($_REQUEST['file']) ? wc_intarr($_REQUEST['file']) : array(wc_rqint('id'));
        $n = wc_file_soft_delete($ids, (int)$u['id']);
        wc_api_back('已将 ' . $n . ' 个文件放入回收站');

    /* ---------- 直链复制对话框数据 ---------- */
    case 'links':
        $f = wc_own_file(wc_rqint('id'));
        $s = wc_db_row('SELECT * FROM wc_share WHERE file_id=? AND uid=? AND status=1 ORDER BY id DESC LIMIT 1', array((int)$f['id'], (int)$u['id']));
        if (!$s) { $s = wc_share_create((int)$u['id'], (int)$f['id'], 0, array('days' => 0, 'direct' => 1)); }
        if (!(int)$s['direct']) { wc_db_exec('UPDATE wc_share SET direct=1 WHERE code=?', array($s['code'])); }
        $ttl = wc_rqint('ttl');
        $direct = $ttl > 0 ? wc_sign_link($s['code'], $ttl) : wc_share_direct_url($s['code']);
        $page = wc_share_page_url($s['code']);
        $name = $f['name'];
        $variants = array(
            'page' => $page,
            'direct' => $direct,
            'img' => '<img src="' . $direct . '" alt="' . wc_e($name) . '">',
            'video' => '<video src="' . $direct . '" controls preload="metadata"></video>',
            'audio' => '<audio src="' . $direct . '" controls></audio>',
            'md' => ($f['kind'] === 'image' ? '![' . $name . '](' . $direct . ')' : '[' . $name . '](' . $page . ')'),
            'bbcode' => ($f['kind'] === 'image' ? '[img]' . $direct . '[/img]' : '[url=' . $page . ']' . $name . '[/url]'),
            'css' => 'background-image: wc_url("' . $direct . '");',
            'poster' => $f['kind'] === 'video' ? $direct . (strpos($direct, '?') === false ? '?' : '&') . 'poster=1' : '',
            'thumb' => $f['kind'] === 'image' ? $direct . (strpos($direct, '?') === false ? '?' : '&') . 'w=300&h=300&crop=1&q=80' : '',
            'qr' => wc_url('qrcode.php?t=' . rawurlencode($page)),
        );
        wc_json_ok(array('file' => array('id' => (int)$f['id'], 'name' => $name, 'kind' => $f['kind'], 'ext' => $f['ext'], 'size' => wc_format_size($f['size'])),
            'code' => $s['code'], 'ttl' => $ttl, 'variants' => $variants));

    /* ---------- 批量操作（前台文件管理页表单） ---------- */
    case 'batch':
        $op = wc_post('batch');
        $ids = wc_intarr(isset($_POST['file']) ? $_POST['file'] : array());
        $to = wc_postint('tofid');
        if ($op === '' || $op === '0') { wc_api_back('请先选择批量操作', true); }
        if (!$ids) { wc_api_back('请先勾选文件', true); }

        if ($op === 'move') {
            if (wc_post('tofid') === '') { wc_api_back('请选择目标文件夹（根目录请选「根目录」）', true); }
            if ($to > 0) { wc_own_folder($to); }
            $n = 0;
            foreach ($ids as $i) {
                $f = wc_db_row('SELECT id FROM wc_file WHERE id=? AND uid=?', array($i, (int)$u['id']));
                if ($f) { wc_db_exec('UPDATE wc_file SET folder=? WHERE id=?', array($to, $i)); $n++; }
            }
            wc_api_back('已移动 ' . $n . ' 个文件');
        }
        if ($op === 'del') {
            $n = wc_file_soft_delete($ids, (int)$u['id']);
            wc_api_back('已将 ' . $n . ' 个文件放入回收站');
        }
        if ($op === 'star' || $op === 'unstar') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $n = wc_db_exec('UPDATE wc_file SET star=? WHERE uid=? AND id IN (' . $ph . ')',
                array_merge(array($op === 'star' ? 1 : 0, (int)$u['id']), $ids));
            wc_api_back('已更新 ' . $n . ' 个文件的收藏状态');
        }
        if ($op === 'share') {
            if ((int)$u['verified'] === 0) { wc_api_back('邮箱未验证，不能生成外链', true); }
            $made = array();
            foreach ($ids as $i) {
                $f = wc_db_row('SELECT * FROM wc_file WHERE id=? AND uid=? AND del_time=0', array($i, (int)$u['id']));
                if (!$f) { continue; }
                $s = wc_share_create((int)$u['id'], (int)$f['id'], 0, array('days' => wc_postint('days'), 'direct' => wc_post('direct') === '1'));
                $made[] = '<a href="' . wc_e(wc_share_page_url($s['code'])) . '" target="_blank">' . wc_e(wc_cut_str($f['name'], 24)) . '</a>';
                if (count($made) >= 20) { break; }
            }
            wc_api_back('已生成 ' . count($made) . ' 条外链：<br>' . implode('<br>', $made));
        }
        if ($op === 'zip') {
            $zip = new WcZipStream('wenchen_' . date('Ymd_His') . '.zip');
            $n = wc_zip_add_files($zip, $ids);
            if ($n === 0) { wc_api_back('所选文件无法打包', true); }
            $zip->finish();
            exit;
        }
        wc_api_back('不支持的批量操作：' . wc_e($op), true);

    case 'batch_del':
        $n = wc_file_soft_delete($_POST['file'], (int)$u['id']);
        wc_api_back('已将 ' . $n . ' 个文件放入回收站');

    case 'restore':
        if (wc_rqint('all') === 1) {
            $ids = wc_db_col('SELECT id FROM wc_file WHERE uid=? AND del_time>0', array((int)$u['id']));
        } else {
            $ids = isset($_REQUEST['file']) && is_array($_REQUEST['file']) ? wc_intarr($_REQUEST['file']) : array(wc_rqint('id'));
        }
        $n = wc_file_restore($ids, (int)$u['id']);
        wc_user_sync_used((int)$u['id']);
        wc_api_back('已还原 ' . $n . ' 个文件');

    case 'purge':
    case 'batch_trash':
        $batch = wc_post('batch');
        if (wc_rqint('all') === 1) {
            $ids = wc_db_col('SELECT id FROM wc_file WHERE uid=? AND del_time>0', array((int)$u['id']));
        } elseif ($do === 'batch_trash') {
            if (!in_array($batch, array('restore', 'purge'), true)) { wc_api_back('请选择批量操作', true); }
            $ids = wc_intarr(isset($_POST['file']) ? $_POST['file'] : array());
            if ($batch === 'restore') {
                $n = wc_file_restore($ids, (int)$u['id']);
                wc_user_sync_used((int)$u['id']);
                wc_api_back('已还原 ' . $n . ' 个文件');
            }
        } else {
            $ids = isset($_REQUEST['file']) && is_array($_REQUEST['file']) ? wc_intarr($_REQUEST['file']) : array(wc_rqint('id'));
        }
        if (!$ids) { wc_api_back('未选择文件', true); }
        $n = 0;
        foreach ($ids as $id) {
            $f = wc_db_row('SELECT id,size FROM wc_file WHERE id=? AND uid=?', array((int)$id, (int)$u['id']));
            if ($f) { wc_file_purge((int)$f['id']); $n++; }
        }
        wc_user_sync_used((int)$u['id']);
        wc_log_write('file', '彻底删除 ' . $n . ' 个文件', '', (int)$u['id'], $u['nick']);
        wc_api_back('已彻底删除 ' . $n . ' 个文件，空间已释放');

    /* ---------- 文件夹 ---------- */
    case 'folder_add':
        $name = wc_clean_name(wc_post('name'), '');
        if ($name === '') { wc_api_back('请填写文件夹名称', true); }
        $parent = wc_postint('parent') > 0 ? wc_postint('parent') : wc_rqint('fid');
        if ($parent > 0) { wc_own_folder($parent); }
        if (wc_folder_depth($parent) >= 8) { wc_api_back('目录层级最多 8 层', true); }
        if (wc_db_row('SELECT id FROM wc_folder WHERE uid=? AND parent=? AND name=?', array((int)$u['id'], $parent, $name))) {
            wc_api_back('同级已存在同名文件夹', true);
        }
        $id = wc_db_insert('wc_folder', array('uid' => (int)$u['id'], 'name' => $name, 'parent' => $parent, 'add_time' => time()));
        wc_api_back('文件夹「' . wc_e($name) . '」已创建', false);

    case 'folder_rename':
        $d = wc_own_folder(wc_rqint('fid'));
        $name = wc_clean_name(wc_post('name'), $d['name']);
        wc_db_exec('UPDATE wc_folder SET name=? WHERE id=?', array($name, (int)$d['id']));
        wc_api_back('文件夹已改名');

    case 'folder_del':
        $d = wc_own_folder(wc_rqint('fid'));
        $files = wc_db_col('SELECT id FROM wc_file WHERE uid=? AND folder=?', array((int)$u['id'], (int)$d['id']));
        $sub = wc_db_col('SELECT id FROM wc_folder WHERE uid=? AND parent=?', array((int)$u['id'], (int)$d['id']));
        if ($sub) { wc_api_back('请先清空子文件夹', true); }
        $n = 0;
        foreach ($files as $id) { wc_file_soft_delete(array($id), (int)$u['id']); $n++; }
        wc_db_exec('DELETE FROM wc_folder WHERE id=?', array((int)$d['id']));
        wc_api_back('文件夹已删除，其中 ' . $n . ' 个文件进入回收站');

    case 'folder_zip':
        $fid = wc_rqint('fid');
        if ($fid > 0) { $d = wc_own_folder($fid); $name = $d['name']; } else { $d = null; $name = '我的文件'; }
        if (!wc_rate_limit('zip:' . (int)$u['id'], 10, 60)) { wc_api_back('打包过于频繁，请稍后再试', true); }
        $zip = new WcZipStream(wc_ASCII_fallback($name) . '.zip');
        $n = wc_zip_add_folder($zip, (int)$u['id'], $fid, $fid > 0 ? $name : '');
        $zip->add_data("打包时间：" . date('Y-m-d H:i:s') . "\r\n共 " . $n . " 个文件\r\n来源：" . SITE_URL, 'README.txt');
        $cnt = $zip->finish();
        wc_log_write('file', '打包下载文件夹', $name . ' 共' . $n . '个文件', (int)$u['id'], $u['nick']);
        exit;

    case 'batch_zip':
        $ids = wc_intarr(isset($_POST['file']) ? $_POST['file'] : array());
        if (!$ids) { wc_api_back('未选择文件', true); }
        $zip = new WcZipStream('wenchen_' . date('Ymd_His') . '.zip');
        $n = 0;
        foreach ($ids as $id) {
            $f = wc_db_row('SELECT * FROM wc_file WHERE id=? AND uid=? AND del_time=0', array($id, (int)$u['id']));
            if (!$f) { continue; }
            $abs = wc_file_abs($f);
            if ($abs !== '') { $zip->add_file($abs, $f['name'], (int)$f['add_time']); $n++; }
        }
        if ($n === 0) { wc_api_back('所选文件无法打包', true); }
        $zip->finish();
        exit;

    /* ---------- 分享 ---------- */
    case 'share':
    case 'share_create':
        $id = wc_rqint('id') > 0 ? wc_rqint('id') : wc_postint('id');
        $f = wc_own_file($id);
        if ((int)$u['verified'] === 0) { wc_api_back('邮箱未验证，不能生成外链', true); }
        if ((int)$f['del_time'] > 0) { wc_api_back('回收站中的文件不能分享', true); }
        if (!wc_setting('share_open', '1')) { wc_api_back('站点已关闭外链分享功能', true); }
        $opt = array(
            'days' => wc_postint('days'),
            'pwd' => wc_post('pwd'),
            'max_down' => wc_postint('max_down'),
            'direct' => wc_post('direct') === '1',
            'pub' => wc_post('pub') === '1' && wc_setting('index_show_share', '1'),
        );
        if ($opt['pwd'] !== '' && (strlen($opt['pwd']) < 4 || strlen($opt['pwd']) > 16)) { wc_api_back('访问密码需 4-16 位', true); }
        $old = wc_db_row('SELECT * FROM wc_share WHERE file_id=? AND status=1', array((int)$f['id']));
        if ($old && !wc_post('new')) {
            wc_api_back('该文件已有外链：<a href="' . wc_e(wc_share_page_url($old['code'])) . '" target="_blank">' . wc_e(wc_share_page_url($old['code'])) . '</a>');
        }
        $s = wc_share_create((int)$u['id'], (int)$f['id'], 0, $opt);
        wc_log_write('share', '创建文件外链', $f['name'], (int)$u['id'], $u['nick']);
        if (wc_is_ajax()) {
            wc_json_ok(array('code' => $s['code'], 'url' => wc_share_page_url($s['code']), 'direct' => (int)$s['direct'] ? wc_share_direct_url($s['code']) : ''));
        }
        wc_alert('外链创建成功：<br><input type="text" size="50" readonly value="' . wc_e(wc_share_page_url($s['code'])) . '" onclick="this.select()">'
            . '<br>直链地址：<input type="text" size="50" readonly value="' . wc_e(wc_share_direct_url($s['code'])) . '" onclick="this.select()">',
            wc_url('files.php'), '分享成功');

    case 'share_folder':
        $fid = wc_postint('fid') > 0 ? wc_postint('fid') : wc_rqint('fid');
        $d = wc_own_folder($fid);
        if ((int)$u['verified'] === 0) { wc_api_back('邮箱未验证，不能生成外链', true); }
        $s = wc_share_create((int)$u['id'], 0, $fid, array(
            'days' => wc_postint('days'), 'pwd' => wc_post('pwd'), 'max_down' => wc_postint('max_down'), 'direct' => wc_post('direct') === '1',
        ));
        wc_api_back('文件夹外链已创建：<a href="' . wc_e(wc_share_page_url($s['code'])) . '" target="_blank">' . wc_e(wc_share_page_url($s['code'])) . '</a>');

    case 'share_edit':
        $s = wc_db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(wc_post('code'), (int)$u['id']));
        if (!$s) { wc_api_back('分享不存在', true); }
        $days = wc_postint('days');
        $data = array(
            'expire' => $days > 0 ? time() + $days * 86400 : (wc_post('keep_expire') === '1' ? (int)$s['expire'] : 0),
            'max_down' => wc_postint('max_down'),
            'direct' => wc_post('direct') === '1' ? 1 : 0,
            'pub' => wc_post('pub') === '1' && wc_setting('index_show_share', '1') ? 1 : 0,
        );
        $pwd = wc_post('pwd');
        if ($pwd === '-') { $data['pwd'] = ''; }
        elseif ($pwd !== '') {
            if (strlen($pwd) < 4 || strlen($pwd) > 16) { wc_api_back('访问密码需 4-16 位（留空则清除请填 -）', true); }
            $data['pwd'] = password_hash($pwd, PASSWORD_DEFAULT);
            WcKV::del('shareauth:' . $s['code']);
        }
        wc_db_update('wc_share', $data, 'code=?', array($s['code']));
        wc_api_back('外链设置已保存');

    case 'share_del':
        $s = wc_db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(wc_rqstr('code'), (int)$u['id']));
        if (!$s) { wc_api_back('分享不存在', true); }
        wc_db_exec('DELETE FROM wc_share WHERE code=?', array($s['code']));
        wc_db_exec('DELETE FROM wc_share_log WHERE code=?', array($s['code']));
        WcKV::del('shareauth:' . $s['code']);
        wc_log_write('share', '删除外链', $s['code'], (int)$u['id'], $u['nick']);
        wc_api_back('外链记录已删除（文件本身保留）');

    case 'share_close':
        $s = wc_db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(wc_rqstr('code'), (int)$u['id']));
        if (!$s) { wc_api_back('分享不存在', true); }
        wc_db_exec('UPDATE wc_share SET status=0 WHERE code=?', array($s['code']));
        wc_api_back('外链已关闭');

    case 'share_open':
        $s = wc_db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(wc_rqstr('code'), (int)$u['id']));
        if (!$s) { wc_api_back('分享不存在', true); }
        wc_db_exec('UPDATE wc_share SET status=1 WHERE code=?', array($s['code']));
        wc_api_back('外链已重新开启');

    case 'share_reset':
        $s = wc_db_row('SELECT * FROM wc_share WHERE code=? AND uid=?', array(wc_rqstr('code'), (int)$u['id']));
        if (!$s) { wc_api_back('分享不存在', true); }
        $new = wc_new_share_code();
        wc_db_exec('UPDATE wc_share SET code=?, down=0, view=0 WHERE code=?', array($new, $s['code']));
        WcKV::del('shareauth:' . $s['code']);
        wc_api_back('短码已重置为：<a href="' . wc_e(wc_share_page_url($new)) . '" target="_blank">' . wc_e(wc_share_page_url($new)) . '</a>');

    /* ---------- URL 转存 ---------- */
    case 'fetch':
        if ((int)$u['verified'] === 0) { wc_api_back('邮箱未验证，不能使用 URL 转存', true); }
        $r = wc_remote_fetch(wc_post('url'));
        if (is_string($r)) { wc_api_back($r, true); }
        $chk = wc_quota_check($u, $r['size']);
        if ($chk !== true) { @unlink($r['tmp']); wc_api_back($chk, true); }
        $res = wc_file_commit($r['tmp'], (int)$u['id'], wc_postint('folder'), $r['name'], $r['size']);
        if (is_string($res)) { wc_api_back($res, true); }
        if (wc_is_ajax()) { wc_json_ok(array('file' => wc_file_json($res))); }
        wc_alert('转存成功：' . wc_e($r['name']) . '（' . wc_format_size($r['size']) . '）', wc_url('files.php?fid=' . wc_postint('folder')), '转存完成');

    /* ---------- 秒传探测 ---------- */
    case 'dedup':
        if ((int)$u['verified'] === 0) { wc_json_err('邮箱未验证，不能上传', array('code' => 'unverified')); }
        $sha1 = wc_post('sha1');
        $size = wc_postint('size');
        $folder = wc_postint('folder');
        if (!preg_match('/^[0-9a-f]{40}$/i', (string)$sha1)) { wc_json_err('SHA1 参数不正确'); }
        $r = wc_file_dedup_commit((int)$u['id'], $folder, wc_post('name'), $sha1, $size);
        if ($r) { wc_json_ok(array('fast' => 1, 'file' => wc_file_json($r))); }
        wc_json_ok(array('fast' => 0));

    /* ---------- 站内信 ---------- */
    case 'notice_read':
        $id = wc_rqint('id');
        wc_db_exec('UPDATE wc_notice SET is_read=1 WHERE id=? AND uid=?', array($id, (int)$u['id']));
        wc_api_back('已标记为已读');

    case 'notice_read_all':
        wc_db_exec('UPDATE wc_notice SET is_read=1 WHERE uid=?', array((int)$u['id']));
        wc_api_back('全部消息已标记为已读');

    case 'notice_del':
        wc_db_exec('DELETE FROM wc_notice WHERE uid=? AND id=?', array((int)$u['id'], wc_rqint('id')));
        wc_api_back('消息已删除');

    default:
        wc_api_back('未知操作：' . wc_e($do), true);
}

/** 给前端用的文件 JSON 摘要 */
function wc_file_json($f)
{
    return array(
        'id' => (int)$f['id'],
        'name' => $f['name'],
        'size' => (int)$f['size'],
        'size_text' => wc_format_size($f['size']),
        'kind' => $f['kind'],
        'ext' => $f['ext'],
        'time' => date('Y-m-d H:i:s', (int)$f['add_time']),
        'thumb' => $f['thumb'] !== '' ? wc_url('img.php?f=' . (int)$f['id']) : '',
        'preview' => wc_url('preview.php?id=' . (int)$f['id']),
        'download' => wc_url('api.php?do=download&id=' . (int)$f['id']),
    );
}
