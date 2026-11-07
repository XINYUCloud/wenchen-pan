<?php
/** 上传页：传统表单 + 现代多队列/拖拽/粘贴/分片 + URL 转存 */
require_once __DIR__ . '/core/bootstrap.php';
wc_require_login();
$u = wc_auth_user();
$unverified = (int)$u['verified'] === 0;
$fid = wc_getint('fid');
if ($fid > 0 && !wc_folder_check_owner($fid, (int)$u['id'])) { $fid = 0; }

$results = null;
if (wc_is_post() && wc_post('do') === 'form') {
    wc_csrf_check();
    if ($unverified) { wc_alert('邮箱未验证，暂时不能上传文件。请先到用户中心重新收取验证邮件。', wc_url('user.php'), '无法上传'); }
    if (!wc_setting('upload_open', '1')) { wc_alert('站点已暂停上传服务。', wc_url('files.php'), '无法上传'); }
    $results = wc_upload_run($u, wc_postint('folder'));
}

wc_page_head('上传文件');
wc_crumbs(array(array('我的文件', wc_url('files.php')), '上传'));

if ($unverified) {
    wc_box_start('账号状态');
    wc_tip('你的邮箱 <b>' . wc_e($u['email']) . '</b> 尚未验证，验证后才能上传与分享。', 'warn');
    echo '<form method="post" action="' . wc_e(wc_url('user.php')) . '">' . wc_csrf_field()
        . '<input type="hidden" name="do" value="resend"><input type="submit" class="btn" value="重新发送验证邮件"></form>';
    wc_box_end();
}

if ($results !== null) {
    wc_box_start('上传结果');
    $okN = 0;
    echo '<table class="tb"><tr class="hd"><td width="220">文件</td><td>结果</td></tr>';
    foreach ($results as $r) {
        if (!empty($r['ok'])) {
            $okN++;
            $f = $r['file'];
            echo '<tr><td>' . wc_file_icon($f['kind'], $f['ext']) . ' ' . wc_e(wc_cut_str($r['name'], 34)) . '</td><td class="green">成功，' . wc_e(wc_format_size($r['size']))
                . '　<a href="' . wc_e(wc_url('api.php?do=download&id=' . (int)$f['id'])) . '">下载</a> '
                . '<a href="' . wc_e(wc_url('files.php?act=share&id=' . (int)$f['id'])) . '">生成外链</a> '
                . '<a href="' . wc_e(wc_url('preview.php?id=' . (int)$f['id'])) . '" target="_blank">预览</a></td></tr>';
        } else {
            echo '<tr><td>' . wc_e(wc_cut_str($r['name'], 34)) . '</td><td class="red">失败：' . wc_e($r['msg']) . '</td></tr>';
        }
    }
    echo '</table><p>成功 ' . $okN . ' / ' . count($results) . ' 个文件。'
        . '<a href="' . wc_e(wc_url('files.php?fid=' . $fid)) . '">前往文件管理</a></p>';
    wc_box_end();
}

$folders = wc_folder_tree((int)$u['id'], 0);
$maxS = wc_upload_max_bytes();
$chunkOn = wc_setting('chunk_enable', '1') && !$unverified;

wc_box_start('上传文件', '<a href="' . wc_e(wc_url('files.php?fid=' . $fid)) . '">返回文件管理</a>');
echo '<div class="upTo">目标目录：<select name="targetFolder" id="targetFolder" onchange="wcSetFolder(this.value)">'
    . '<option value="0"' . ($fid === 0 ? ' selected' : '') . '>根目录</option>';
foreach ($folders as $k) {
    echo '<option value="' . (int)$k['id'] . '"' . ($fid === (int)$k['id'] ? ' selected' : '') . '>'
        . str_repeat('　', (int)$k['depth']) . '└ ' . wc_e($k['name']) . '</option>';
}
echo '</select></div>';

/* 现代上传区 */
echo '<div class="upArea" id="upArea"' . ($unverified ? ' data-lock="1"' : '') . '>'
    . '<div class="dropZone" id="dropZone"><b>把文件拖到这里</b>（也可以点击右侧按钮选择）'
    . '<br><span class="mini">支持多选、Ctrl+V 粘贴截图；超过 ' . wc_e(wc_format_size($maxS)) . ' 自动改用分片断点续传</span></div>'
    . '<div class="upCtl">'
    . '<input type="button" class="btn" id="pickBtn" value="选择文件">'
    . '<input type="button" class="btn" id="startBtn" value="开始上传">'
    . '<input type="button" class="btn" id="clearBtn" value="清空列表">'
    . '<label><input type="checkbox" id="optFast" ' . (wc_setting('dedupe', '1') ? 'checked' : '') . '> 尝试秒传（先算 SHA1）</label>'
    . '<label><input type="checkbox" id="optChunk" ' . ($chunkOn ? 'checked' : '') . (wc_setting('chunk_enable', '1') ? '' : ' disabled') . '> 大文件分片上传</label>'
    . '</div>'
    . '<table class="tb queue"><tbody id="queueBody"><tr class="qEmpty"><td colspan="6" align="center">队列里没有文件</td></tr></tbody></table>'
    . '<input type="file" id="pickInput" name="files[]" multiple style="display:none">'
    . '</div>';

/* 传统表单（兼容不支持 JS 的浏览器） */
echo '<form method="post" action="' . wc_e(wc_url('upload.php')) . '" enctype="multipart/form-data" class="legacyForm">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="form"><input type="hidden" name="folder" value="' . (int)$fid . '" id="legacyFolder">'
    . '<div class="legacy"><b>传统上传（无脚本环境可用）</b>：'
    . '<input type="file" name="files[]" size="30"> <input type="file" name="files[]" size="30"> '
    . '<input type="submit" class="btn" value="上传">'
    . '<div class="note">单次最多 2 个文件，且受服务器 upload_max_filesize 限制；大文件请用上方分片上传。</div></div></form>';

echo '<div class="upTips">'
    . '<b>上传限制</b><br>'
    . '· 允许类型：' . wc_e(implode('、', array_map('wc_kind_name', wc_setting_arr('allow_kinds', array('image', 'video', 'audio', 'doc', 'archive', 'code', 'other'))))) . '<br>'
    . '· 单文件上限：' . wc_e(wc_format_size($maxS)) . '（站点 ' . wc_e(wc_setting('max_size', '200 MB')) . ' / PHP ' . wc_e(ini_get('upload_max_filesize')) . '）<br>'
    . '· 我的剩余空间：<b>' . wc_e(wc_format_size(max(0, (int)$u['quota'] - (int)$u['used']))) . '</b>／总 ' . wc_e(wc_format_size($u['quota'])) . '<br>'
    . '· 上传的文件默认私密，只有自己可见；需要分享请生成外链。<br>'
    . wc_e(wc_setting('upload_notice', ''))
    . '</div>';
wc_box_end();

/* URL 转存 */
if (wc_setting('url_fetch', '1') && !$unverified) {
    wc_box_start('URL 转存（把网络文件存入我的网盘）');
    echo '<form method="post" action="' . wc_e(wc_url('api.php')) . '" class="frm">' . wc_csrf_field()
        . '<input type="hidden" name="do" value="fetch"><input type="hidden" name="folder" value="' . (int)$fid . '">'
        . '<table class="frmTb"><tr><th>文件地址</th><td><input type="text" name="url" size="60" maxlength="500" placeholder="https://example.com/file.zip" required>'
        . ' <input type="submit" class="btn" value="转存"></td></tr>'
        . '<tr><th>&nbsp;</th><td class="note">仅支持 http/https，禁止内网地址，超时 ' . (int)wc_setting('fetch_timeout', '120') . ' 秒。</td></tr></table></form>';
    wc_box_end();
}

echo '<script>window.WC_UPLOAD=' . json_encode(array(
    'folder' => $fid,
    'maxSingle' => $maxS,
    'chunkSize' => wc_chunk_limit_bytes(),
    'chunkEnable' => $chunkOn ? 1 : 0,
    'quotaLeft' => max(0, (int)$u['quota'] - (int)$u['used']),
    'api' => wc_url('chunk.php'),
    'uploadApi' => wc_url('upload.php'),
    'csrf' => wc_csrf_token(),
    'lock' => $unverified ? 1 : 0,
)) . ';</script>';
echo '<script src="' . wc_e(wc_url('assets/js/sha1.js?v=' . WC_VERSION)) . '"></script>'
    . '<script src="' . wc_e(wc_url('assets/js/upload.js?v=' . WC_VERSION)) . '"></script>';
wc_page_foot();
