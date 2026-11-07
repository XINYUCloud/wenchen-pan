<?php
/**
 * 上传接口（AJAX）：单文件 XHR、秒传探测、分片 init/put/status/merge/abort
 * 请求头 X-CSRF 必须等于会话 token
 */
define('WC_REQ_KIND', 'api');
require_once __DIR__ . '/core/bootstrap.php';
wc_require_login();
$u = wc_auth_user();

if (!wc_csrf_check(true)) { wc_json_err('页面校验失败，请刷新页面重试', array('code' => 'csrf')); }
if ((int)$u['verified'] === 0) { wc_json_err('邮箱未验证，不能上传文件', array('code' => 'unverified')); }
if (!wc_setting('upload_open', '1')) { wc_json_err('站点已暂停上传服务', array('code' => 'closed')); }

$do = wc_post('do') !== '' ? wc_post('do') : wc_get('do');
$folder = wc_postint('folder');
if ($folder > 0 && !wc_folder_check_owner($folder, (int)$u['id'])) { $folder = 0; }

if (!wc_rate_limit('up:' . (int)$u['id'], wc_setting_int('limit_upload', 600), 60)) {
    wc_json_err('上传请求过于频繁，请稍后再试', array('code' => 'limit'));
}

switch ($do) {

    /* 单文件（未分片）XHR 上传，字段名 file */
    case 'one':
        $files = wc_upload_normalize();
        if (!$files) { wc_json_err('没有收到文件'); }
        $res = wc_upload_run($u, $folder);
        $r = $res[0];
        if (empty($r['ok'])) { wc_json_err($r['msg']); }
        wc_json_ok(array('fast' => 0, 'file' => wc_file_json_out($r['file'])));

    /* 秒传探测 */
    case 'fast':
        $sha1 = wc_post('sha1');
        $size = wc_postint('size');
        $name = wc_post('name');
        if (!preg_match('/^[0-9a-f]{40}$/i', (string)$sha1) || $size <= 0) { wc_json_err('参数不正确'); }
        $chk = wc_quota_check($u, $size);
        if ($chk !== true) { wc_json_err($chk); }
        $f = wc_file_dedup_commit((int)$u['id'], $folder, $name, strtolower($sha1), $size);
        if (!$f) { wc_json_ok(array('fast' => 0)); }
        wc_json_ok(array('fast' => 1, 'file' => wc_file_json_out($f)));

    /* 分片：初始化会话 */
    case 'init':
        $name = wc_post('name');
        $size = wc_postint('size');
        $sha1 = strtolower(wc_post('sha1'));
        $chunk = wc_postint('chunk');
        $total = $chunk > 0 ? (int)ceil($size / $chunk) : 0;
        if ($size <= 0 || !$total) { wc_json_err('文件参数不正确'); }
        $chk = wc_quota_check($u, $size);
        if ($chk !== true) { wc_json_err($chk); }
        $r = wc_chunk_init((int)$u['id'], $name, $size, preg_match('/^[0-9a-f]{40}$/', $sha1) ? $sha1 : '', $total, $folder, $chunk);
        if (is_string($r)) { wc_json_err($r); }
        wc_json_ok(array(
            'upload_id' => $r['upload_id'],
            'have' => $r['have'],
            'reuse' => $r['reuse'],
            'total' => $total,
            'chunk' => min($chunk, wc_chunk_limit_bytes()),
        ));

    /* 分片：上传一片（字段名 chunk，另带 upload_id / i） */
    case 'put':
        $upload_id = wc_post('upload_id');
        $i = wc_postint('i');
        if (!preg_match('/^[0-9a-f]{40}$/', (string)$upload_id)) { wc_json_err('会话编号无效'); }
        $files = wc_upload_normalize();
        if (!$files) { wc_json_err('没有收到分片'); }
        $f = $files[0];
        if ((int)$f['error'] !== UPLOAD_ERR_OK) { wc_json_err(wc_upload_err_msg((int)$f['error'])); }
        if (!is_uploaded_file($f['tmp_name'])) { wc_json_err('非法上传来源'); }
        wc_dir_ensure(wc_tmp_path());
        $dest = wc_tmp_path('pc_' . bin2hex(random_bytes(8)));
        if (!@move_uploaded_file($f['tmp_name'], $dest)) { wc_json_err('临时目录不可写'); }
        $r = wc_chunk_put($upload_id, $i, $dest);
        if (is_string($r)) { @unlink($dest); wc_json_err($r); }
        wc_json_ok(array('i' => $i, 'have' => wc_chunk_have($upload_id)));

    /* 分片：查询进度 */
    case 'status':
        $upload_id = wc_post('upload_id');
        $c = wc_chunk_owner($upload_id);
        if (!$c || (int)$c['uid'] !== (int)$u['id']) { wc_json_err('会话不存在'); }
        wc_json_ok(array('have' => wc_chunk_have($upload_id), 'total' => (int)$c['total'], 'status' => (int)$c['status']));

    /* 分片：合并入库 */
    case 'merge':
        $upload_id = wc_post('upload_id');
        $c = wc_chunk_owner($upload_id);
        if (!$c || (int)$c['uid'] !== (int)$u['id']) { wc_json_err('会话不存在'); }
        $r = wc_chunk_merge($upload_id, $u);
        if (is_array($r) && isset($r['partial'])) {
            wc_json_err('还缺少 ' . ((int)$r['need'] - (int)$r['have']) . ' 个分片，正在重传', array('partial' => 1, 'have' => wc_chunk_have($upload_id)));
        }
        if (is_string($r)) { wc_json_err($r); }
        wc_json_ok(array('file' => wc_file_json_out($r)));

    /* 分片：放弃 */
    case 'abort':
        $upload_id = wc_post('upload_id');
        $c = wc_chunk_owner($upload_id);
        if ($c && (int)$c['uid'] === (int)$u['id']) { wc_chunk_clean($upload_id); }
        wc_json_ok(array('msg' => '已取消该上传会话'));

    default:
        wc_json_err('未知上传动作：' . wc_e($do));
}

/** 与 api.php 中的 file_json 保持一致的返回结构 */
function wc_file_json_out($f)
{
    return array(
        'id' => (int)$f['id'],
        'name' => $f['name'],
        'size' => (int)$f['size'],
        'size_text' => wc_format_size($f['size']),
        'kind' => $f['kind'],
        'ext' => $f['ext'],
        'sha1' => $f['sha1'],
        'time' => date('Y-m-d H:i:s', (int)$f['add_time']),
        'thumb' => $f['thumb'] !== '' ? wc_url('img.php?f=' . (int)$f['id']) : '',
        'preview' => wc_url('preview.php?id=' . (int)$f['id']),
        'download' => wc_url('api.php?do=download&id=' . (int)$f['id']),
        'share' => wc_url('files.php?act=share&id=' . (int)$f['id']),
    );
}
