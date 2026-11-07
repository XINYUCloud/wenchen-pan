<?php
/**
 * 上传接口（AJAX）：单文件 XHR、秒传探测、分片 init/put/status/merge/abort
 * 请求头 X-CSRF 必须等于会话 token
 */
require_once __DIR__ . '/core/bootstrap.php';
require_login();
$u = auth_user();

if (!csrf_check(true)) { json_err('页面校验失败，请刷新页面重试', array('code' => 'csrf')); }
if ((int)$u['verified'] === 0) { json_err('邮箱未验证，不能上传文件', array('code' => 'unverified')); }
if (!setting('upload_open', '1')) { json_err('站点已暂停上传服务', array('code' => 'closed')); }

$do = post('do') !== '' ? post('do') : get('do');
$folder = postint('folder');
if ($folder > 0 && !folder_check_owner($folder, (int)$u['id'])) { $folder = 0; }

if (!rate_limit('up:' . (int)$u['id'], setting_int('limit_upload', 600), 60)) {
    json_err('上传请求过于频繁，请稍后再试', array('code' => 'limit'));
}

switch ($do) {

    /* 单文件（未分片）XHR 上传，字段名 file */
    case 'one':
        $files = upload_normalize();
        if (!$files) { json_err('没有收到文件'); }
        $res = upload_run($u, $folder);
        $r = $res[0];
        if (empty($r['ok'])) { json_err($r['msg']); }
        json_ok(array('fast' => 0, 'file' => file_json_out($r['file'])));

    /* 秒传探测 */
    case 'fast':
        $sha1 = post('sha1');
        $size = postint('size');
        $name = post('name');
        if (!preg_match('/^[0-9a-f]{40}$/i', (string)$sha1) || $size <= 0) { json_err('参数不正确'); }
        $chk = quota_check($u, $size);
        if ($chk !== true) { json_err($chk); }
        $f = file_dedup_commit((int)$u['id'], $folder, $name, strtolower($sha1), $size);
        if (!$f) { json_ok(array('fast' => 0)); }
        json_ok(array('fast' => 1, 'file' => file_json_out($f)));

    /* 分片：初始化会话 */
    case 'init':
        $name = post('name');
        $size = postint('size');
        $sha1 = strtolower(post('sha1'));
        $chunk = postint('chunk');
        $total = $chunk > 0 ? (int)ceil($size / $chunk) : 0;
        if ($size <= 0 || !$total) { json_err('文件参数不正确'); }
        $chk = quota_check($u, $size);
        if ($chk !== true) { json_err($chk); }
        $r = chunk_init((int)$u['id'], $name, $size, preg_match('/^[0-9a-f]{40}$/', $sha1) ? $sha1 : '', $total, $folder, $chunk);
        if (is_string($r)) { json_err($r); }
        json_ok(array(
            'upload_id' => $r['upload_id'],
            'have' => $r['have'],
            'reuse' => $r['reuse'],
            'total' => $total,
            'chunk' => min($chunk, chunk_limit_bytes()),
        ));

    /* 分片：上传一片（字段名 chunk，另带 upload_id / i） */
    case 'put':
        $upload_id = post('upload_id');
        $i = postint('i');
        if (!preg_match('/^[0-9a-f]{40}$/', (string)$upload_id)) { json_err('会话编号无效'); }
        $files = upload_normalize();
        if (!$files) { json_err('没有收到分片'); }
        $f = $files[0];
        if ((int)$f['error'] !== UPLOAD_ERR_OK) { json_err(upload_err_msg((int)$f['error'])); }
        if (!is_uploaded_file($f['tmp_name'])) { json_err('非法上传来源'); }
        dir_ensure(tmp_path());
        $dest = tmp_path('pc_' . bin2hex(random_bytes(8)));
        if (!@move_uploaded_file($f['tmp_name'], $dest)) { json_err('临时目录不可写'); }
        $r = chunk_put($upload_id, $i, $dest);
        if (is_string($r)) { @unlink($dest); json_err($r); }
        json_ok(array('i' => $i, 'have' => chunk_have($upload_id)));

    /* 分片：查询进度 */
    case 'status':
        $upload_id = post('upload_id');
        $c = chunk_owner($upload_id);
        if (!$c || (int)$c['uid'] !== (int)$u['id']) { json_err('会话不存在'); }
        json_ok(array('have' => chunk_have($upload_id), 'total' => (int)$c['total'], 'status' => (int)$c['status']));

    /* 分片：合并入库 */
    case 'merge':
        $upload_id = post('upload_id');
        $c = chunk_owner($upload_id);
        if (!$c || (int)$c['uid'] !== (int)$u['id']) { json_err('会话不存在'); }
        $r = chunk_merge($upload_id, $u);
        if (is_array($r) && isset($r['partial'])) {
            json_err('还缺少 ' . ((int)$r['need'] - (int)$r['have']) . ' 个分片，正在重传', array('partial' => 1, 'have' => chunk_have($upload_id)));
        }
        if (is_string($r)) { json_err($r); }
        json_ok(array('file' => file_json_out($r)));

    /* 分片：放弃 */
    case 'abort':
        $upload_id = post('upload_id');
        $c = chunk_owner($upload_id);
        if ($c && (int)$c['uid'] === (int)$u['id']) { chunk_clean($upload_id); }
        json_ok(array('msg' => '已取消该上传会话'));

    default:
        json_err('未知上传动作：' . e($do));
}

/** 与 api.php 中的 file_json 保持一致的返回结构 */
function file_json_out($f)
{
    return array(
        'id' => (int)$f['id'],
        'name' => $f['name'],
        'size' => (int)$f['size'],
        'size_text' => format_size($f['size']),
        'kind' => $f['kind'],
        'ext' => $f['ext'],
        'sha1' => $f['sha1'],
        'time' => date('Y-m-d H:i:s', (int)$f['add_time']),
        'thumb' => $f['thumb'] !== '' ? url('img.php?f=' . (int)$f['id']) : '',
        'preview' => url('preview.php?id=' . (int)$f['id']),
        'download' => url('api.php?do=download&id=' . (int)$f['id']),
        'share' => url('files.php?act=share&id=' . (int)$f['id']),
    );
}
