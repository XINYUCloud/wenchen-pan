<?php
/** 上传：传统 multipart + 分片断点续传 + 秒传 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_UPLOAD')) { return; }
define('WC_CORE_UPLOAD', 1);

function wc_upload_max_bytes()
{
    $a = wc_parse_size(wc_setting('max_size', '200 MB'));
    $b = wc_parse_size(ini_get('upload_max_filesize') ?: '0');
    $c = wc_parse_size(ini_get('post_max_size') ?: '0');
    $v = $a > 0 ? $a : PHP_INT_MAX;
    if ($b > 0) { $v = min($v, $b); }
    if ($c > 0) { $v = min($v, $c); }
    return $v;
}

function wc_upload_limit_hint()
{
    $php = wc_parse_size(ini_get('upload_max_filesize') ?: '0');
    $site = wc_parse_size(wc_setting('max_size', '200 MB'));
    $v = min($site > 0 ? $site : PHP_INT_MAX, $php > 0 ? $php : PHP_INT_MAX);
    return wc_format_size($v);
}

/** 传统表单上传：$_FILES 归一化 */
function wc_upload_normalize()
{
    $raw = isset($_FILES['files']) ? $_FILES['files'] : (isset($_FILES['Filedata']) ? $_FILES['Filedata'] : (isset($_FILES['file']) ? $_FILES['file'] : null));
    if (!$raw) { return array(); }
    $out = array();
    if (!is_array($raw['name'])) {
        if ($raw['error'] === UPLOAD_ERR_NO_FILE) { return array(); }
        $out[] = $raw;
        return $out;
    }
    foreach (array_keys($raw['name']) as $i) {
        if ((int)$raw['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
        $out[] = array(
            'name' => $raw['name'][$i], 'type' => $raw['type'][$i], 'tmp_name' => $raw['tmp_name'][$i],
            'error' => (int)$raw['error'][$i], 'size' => (int)$raw['size'][$i],
        );
    }
    return $out;
}

function wc_upload_err_msg($code)
{
    $m = array(
        UPLOAD_ERR_INI_SIZE => '文件超过服务器 upload_max_filesize 限制，请改用分片上传或调大 PHP 参数',
        UPLOAD_ERR_FORM_SIZE => '文件超过表单声明的大小',
        UPLOAD_ERR_PARTIAL => '文件只上传了一部分，请重试',
        UPLOAD_ERR_NO_FILE => '没有选择文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录（PHP upload_tmp_dir）',
        UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
        UPLOAD_ERR_EXTENSION => '上传被 PHP 扩展中断',
    );
    return isset($m[$code]) ? $m[$code] : '上传失败（错误码 ' . $code . '）';
}

/**
 * 逐个处理$_FILES，返回结果数组
 * 每项：array('name'=>, 'ok'=>1/0, 'msg'=>, 'file'=>记录)
 */
function wc_upload_run($u, $folder)
{
    wc_dir_ensure(wc_tmp_path());
    $files = wc_upload_normalize();
    $results = array();
    $maxSingle = wc_upload_max_bytes();
    foreach ($files as $f) {
        $name = wc_clean_name($f['name'], 'unnamed');
        if ((int)$f['error'] !== UPLOAD_ERR_OK) {
            $results[] = array('name' => $name, 'ok' => 0, 'msg' => wc_upload_err_msg((int)$f['error']));
            continue;
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            $results[] = array('name' => $name, 'ok' => 0, 'msg' => '非法的上传来源');
            continue;
        }
        $size = (int)filesize($f['tmp_name']);
        if ($size <= 0) { $results[] = array('name' => $name, 'ok' => 0, 'msg' => '空文件已忽略'); continue; }
        if ($size > $maxSingle) {
            $results[] = array('name' => $name, 'ok' => 0, 'msg' => '超过单文件上限 ' . wc_format_size($maxSingle) . '，可改用分片上传');
            continue;
        }
        $chk = wc_quota_check($u, $size);
        if ($chk !== true) { $results[] = array('name' => $name, 'ok' => 0, 'msg' => $chk); continue; }
        $dest = wc_tmp_path('up_' . bin2hex(random_bytes(8)));
        if (!@move_uploaded_file($f['tmp_name'], $dest)) {
            $results[] = array('name' => $name, 'ok' => 0, 'msg' => '临时目录不可写，请检查 data/tmp 权限');
            continue;
        }
        $r = wc_file_commit($dest, (int)$u['id'], (int)$folder, $name, $size);
        if (is_string($r)) {
            @unlink($dest);
            $results[] = array('name' => $name, 'ok' => 0, 'msg' => $r);
            continue;
        }
        $results[] = array('name' => $name, 'ok' => 1, 'file' => $r, 'size' => $size, 'kind' => $r['kind']);
    }
    return $results;
}

/* ---------- 分片上传 ---------- */
function wc_chunk_limit_bytes()
{
    $site = wc_parse_size(wc_setting('chunk_max_size', '8 MB'));
    $php = wc_parse_size(ini_get('upload_max_filesize') ?: '0');
    $v = $site > 0 ? $site : 8388608;
    if ($php > 0) { $v = min($v, $php); }
    return max(65536, $v);
}

function wc_chunk_init($uid, $name, $size, $sha1, $total, $folder, $chunk = 0)
{
    $size = (int)$size; $total = (int)$total;
    $chunk = (int)$chunk > 0 ? min((int)$chunk, wc_chunk_limit_bytes()) : wc_chunk_limit_bytes();
    if ($size <= 0 || $total <= 0 || $total > 20000) { return '分片参数不正确'; }
    if ($total * $chunk < $size) { return '分片总数与分片大小不匹配'; }
    $chk = wc_quota_check(wc_auth_user(), $size);
    if ($chk !== true) { return $chk; }
    $name = wc_clean_name($name);
    $ext = wc_ext_of($name);
    $why = '';
    if ($ext !== '' && !wc_ext_allowed($ext, $why)) { return $why; }
    $upload_id = sha1($uid . '|' . $name . '|' . $size . '|' . $sha1 . '|' . microtime(true));
    $exist = wc_db_row('SELECT * FROM wc_chunk WHERE uid=? AND sha1=? AND size=? AND total=? AND status=0 AND add_time>?', array((int)$uid, $sha1, $size, $total, time() - 86400 * 2));
    wc_dir_ensure(wc_tmp_path('chunk'));
    if ($exist) {
        WcKV::set('chunksize:' . $exist['upload_id'], (string)$chunk, 86400 * 2);
        return array('upload_id' => $exist['upload_id'], 'have' => wc_chunk_have($exist['upload_id']), 'reuse' => 1);
    }
    wc_db_insert('wc_chunk', array('upload_id' => $upload_id, 'uid' => (int)$uid, 'sha1' => $sha1, 'name' => $name, 'size' => $size, 'total' => $total, 'folder' => (int)$folder, 'status' => 0, 'add_time' => time()));
    WcKV::set('chunksize:' . $upload_id, (string)$chunk, 86400 * 2);
    return array('upload_id' => $upload_id, 'have' => array(), 'reuse' => 0);
}

function wc_chunk_owner($upload_id)
{
    return wc_db_row('SELECT * FROM wc_chunk WHERE upload_id=?', array(trim((string)$upload_id)));
}

function wc_chunk_put($upload_id, $i, $tmpAbs)
{
    $c = wc_chunk_owner($upload_id);
    if (!$c) { return '上传会话不存在，请重新初始化'; }
    if ((int)$c['uid'] !== wc_auth_uid()) { return '无权操作该上传会话'; }
    if ((int)$c['status'] === 1) { return '该文件已完成合并'; }
    $i = (int)$i;
    if ($i < 0 || $i >= (int)$c['total']) { return '分片序号越界'; }
    $size = (int)filesize($tmpAbs);
    if ($size <= 0) { return '空分片'; }
    $chunk = (int)WcKV::get('chunksize:' . $upload_id, (string)wc_chunk_limit_bytes());
    if ($chunk <= 0 || $chunk > wc_chunk_limit_bytes()) { $chunk = wc_chunk_limit_bytes(); }
    $last = (int)$c['total'] - 1;
    if ($i < $last && $size !== $chunk) { return '分片大小与初始化声明不一致（应 ' . $chunk . ' 字节）'; }
    if ($i === $last) {
        $tail = (int)$c['size'] - $chunk * $last;
        if ($tail > 0 && $size !== $tail) { return '末片大小应为 ' . $tail . ' 字节'; }
    }
    wc_dir_ensure(wc_tmp_path('chunk'));
    $rel = 'chunk/' . $upload_id . '_' . $i;
    $dest = wc_tmp_path($rel);
    if (!@rename($tmpAbs, $dest)) {
        if (!@copy($tmpAbs, $dest)) { return '分片保存失败，请检查 data/tmp 权限'; }
        @unlink($tmpAbs);
    }
    wc_db_exec('REPLACE INTO wc_chunk_part (upload_id,i,path,size,add_time) VALUES (?,?,?,?,?)', array($upload_id, $i, $rel, $size, time()));
    return true;
}

function wc_chunk_have($upload_id)
{
    return array_map('intval', wc_db_col('SELECT i FROM wc_chunk_part WHERE upload_id=? ORDER BY i', array($upload_id)));
}

/** 合并分片并入库 */
function wc_chunk_merge($upload_id, $u)
{
    $c = wc_chunk_owner($upload_id);
    if (!$c) { return '上传会话不存在'; }
    if ((int)$c['uid'] !== (int)$u['id']) { return '无权操作该上传会话'; }
    $parts = wc_db_rows('SELECT * FROM wc_chunk_part WHERE upload_id=? ORDER BY i', array($upload_id));
    if (count($parts) !== (int)$c['total']) {
        return array('partial' => 1, 'have' => count($parts), 'need' => (int)$c['total']);
    }
    wc_dir_ensure(wc_tmp_path());
    $final = wc_tmp_path('mg_' . $upload_id);
    $out = @fopen($final, 'wb');
    if (!$out) { return '临时目录不可写'; }
    $size = 0;
    $ctx = hash_init('sha1');
    foreach ($parts as $p) {
        $in = @fopen(wc_tmp_path($p['path']), 'rb');
        if (!$in) { fclose($out); @unlink($final); return '分片丢失，请重传：第 ' . (int)$p['i'] . ' 片'; }
        while (!feof($in)) {
            $buf = fread($in, 1048576);
            if ($buf === false || $buf === '') { break; }
            fwrite($out, $buf);
            hash_update($ctx, $buf);
            $size += strlen($buf);
        }
        fclose($in);
        @unlink(wc_tmp_path($p['path']));
    }
    fclose($out);
    $real = hash_final($ctx);
    if ((int)$c['total'] > 0) { wc_db_exec('DELETE FROM wc_chunk_part WHERE upload_id=?', array($upload_id)); }
    wc_db_exec('UPDATE wc_chunk SET status=1 WHERE upload_id=?', array($upload_id));
    if ($c['sha1'] !== '' && strcasecmp($c['sha1'], $real) !== 0) {
        @unlink($final);
        return '文件校验失败（SHA1 不一致），请重新上传';
    }
    if ($size !== (int)$c['size']) {
        @unlink($final);
        return '合并后大小 ' . wc_format_size($size) . ' 与声明 ' . wc_format_size((int)$c['size']) . ' 不一致';
    }
    $r = wc_file_commit($final, (int)$u['id'], (int)$c['folder'], $c['name'], $size, $real);
    if (is_string($r)) { @unlink($final); return $r; }
    wc_db_exec('DELETE FROM wc_chunk WHERE upload_id=?', array($upload_id));
    WcKV::del('chunksize:' . $upload_id);
    return $r;
}

function wc_chunk_clean($upload_id)
{
    foreach (wc_db_col('SELECT path FROM wc_chunk_part WHERE upload_id=?', array($upload_id)) as $p) {
        if (strpos($p, '..') === false) { @unlink(wc_tmp_path($p)); }
    }
    wc_db_exec('DELETE FROM wc_chunk_part WHERE upload_id=?', array($upload_id));
    wc_db_exec('UPDATE wc_chunk SET status=2 WHERE upload_id=?', array($upload_id));
    WcKV::del('chunksize:' . $upload_id);
}
