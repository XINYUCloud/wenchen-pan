<?php
/**
 * 外链展示页：访客无需登录即可查看/下载分享的文件或文件夹
 * 地址 /s/短码 或 s.php?c=短码
 * 子动作：do=down 下载（&f=文件id 可下载文件夹内单个文件）
 *        do=text 在线查看文本 / do=pwd 提交访问密码 / do=save 登录用户转存
 */
require_once __DIR__ . '/core/bootstrap.php';

$code = get('c') !== '' ? get('c') : get('s');
$code = preg_replace('/[^A-Za-z0-9]/', '', (string)$code);
if ($code === '') { alert('外链地址格式不正确（短码缺失）。', url()); }

$s = share_find($code);
$state = share_state($s);
if ($state !== true) {
    page_head('链接已失效', array('side' => false));
    box_start('访问提示');
    tip('抱歉，该外链无法访问：<b>' . e($state) . '</b>', 'err');
    echo '<p>如果你是分享者，可在 <a href="' . e(url('shares.php')) . '">我的分享</a> 中重新开启，或重置短码。</p>'
        . '<p><a class="mybtn" href="' . e(url()) . '">返回首页</a></p>';
    box_end();
    page_foot();
    exit;
}

$isFolder = (int)$s['folder_id'] > 0;
$file = null;
$folder = null;
$folderBytes = 0;
$folderCnt = 0;
if ($isFolder) {
    $folder = db_row('SELECT * FROM wc_folder WHERE id=?', array((int)$s['folder_id']));
    if (!$folder) { alert('该文件夹已不存在，链接失效。', url()); }
    $folderCnt = (int)db_one('SELECT COUNT(*) FROM wc_file WHERE uid=? AND folder=? AND del_time=0', array((int)$s['uid'], (int)$folder['id']));
    $folderBytes = (int)db_one('SELECT COALESCE(SUM(size),0) FROM wc_file WHERE uid=? AND folder=? AND del_time=0', array((int)$s['uid'], (int)$folder['id']));
} else {
    $file = db_row('SELECT * FROM wc_file WHERE id=?', array((int)$s['file_id']));
    if (!$file) { alert('文件不存在或已被彻底删除，链接失效。', url()); }
}

/* ---------- 访问密码 ---------- */
$needPwd = $s['pwd'] !== '';
$authKey = 'shareauth:' . $code;
$authed = !$needPwd || KV::get($authKey, '') === session_id();
if ($needPwd && !$authed && is_post() && post('do') === 'pwd') {
    if (!rate_limit('sharepwd:' . $code . ':' . ip(), 15, 300)) {
        $pwErr = '密码尝试次数过多，请 5 分钟后再试';
    } elseif (password_verify((string)post('pwd'), $s['pwd'])) {
        KV::set($authKey, session_id(), 7200);
        go('s.php?c=' . rawurlencode($code));
    } else {
        $pwErr = '访问密码不正确';
    }
}
if ($needPwd && !$authed) {
    page_head('需要访问密码', array('side' => false));
    box_start('该外链受密码保护');
    if (!empty($pwErr)) { tip($pwErr, 'err'); }
    echo '<form method="post" action="' . e(url('s.php?c=' . rawurlencode($code))) . '">' . csrf_field()
        . '<input type="hidden" name="do" value="pwd">'
        . '请输入 4-16 位访问密码：<input type="password" name="pwd" size="16" maxlength="16" autofocus>'
        . ' <input type="submit" class="btn" value="提取文件"></form>'
        . '<div class="mini">验证通过后 2 小时内本浏览器免输密码。地址中的短码请勿公开转发。</div>';
    box_end();
    page_foot();
    exit;
}

/* ---------- 子动作 ---------- */
$subFileId = getint('f');
$target = $file;
if ($isFolder && $subFileId > 0) {
    $target = db_row('SELECT * FROM wc_file WHERE id=? AND uid=? AND folder=? AND del_time=0', array($subFileId, (int)$s['uid'], (int)$folder['id']));
    if (!$target) { alert('该文件不在此分享文件夹内。', url('s.php?c=' . rawurlencode($code))); }
}

if (get('do') === 'down') {
    if (!rate_limit('sharedl:' . ip(), setting_int('limit_download_ip', 80), 60)) {
        alert('下载过于频繁，请稍后再试。', url('s.php?c=' . rawurlencode($code)));
    }
    if ($isFolder && $target === null) {
        $zip = new ZipStream($folder['name'] . '.zip');
        $n = zip_add_folder($zip, (int)$s['uid'], (int)$folder['id'], $folder['name']);
        if ($n === 0) { alert('文件夹里没有可下载的文件。', url('s.php?c=' . rawurlencode($code))); }
        $zip->finish();
        db_exec('UPDATE wc_share SET `down`=`down`+1 WHERE code=?', array($code));
        stat_touch('down_cnt');
        share_log($code, '打包下载', 0);
        exit;
    }
    if (!$target) { alert('缺少下载目标。', url('s.php?c=' . rawurlencode($code))); }
    $abs = file_abs($target);
    if ($abs === '') { share_log($code, '实体缺失'); alert('实体文件已丢失，请联系分享者或管理员。', url()); }
    $disp = (get('a') === 'inline' && in_array($target['kind'], array('image', 'video', 'audio'), true)) ? 'inline' : 'attachment';
    $bytes = stream_file($abs, $target['name'], $disp);
    if ($bytes > 0) {
        db_exec('UPDATE wc_file SET `down`=`down`+1, traffic=traffic+? WHERE id=?', array((int)$bytes, (int)$target['id']));
        db_exec('UPDATE wc_share SET `down`=`down`+1 WHERE code=?', array($code));
        stat_touch('down_cnt');
        stat_touch('down_bytes', $bytes);
        share_log($code, '下载', $bytes);
    } else {
        share_log($code, '下载中断', 0);
    }
    exit;
}

if (get('do') === 'text' && $target) {
    $abs = file_abs($target);
    if ($abs === '' || !in_array($target['ext'], array('txt', 'md', 'csv', 'log', 'json', 'xml', 'ini', 'yml', 'sql'), true)) {
        alert('该文件不支持在线查看文本。', url('s.php?c=' . rawurlencode($code)));
    }
    header('Content-Type: text/html; charset=utf-8');
    $raw = file_get_contents($abs, false, null, 0, 512 * 1024);
    $enc = mb_detect_encoding($raw, array('UTF-8', 'GB18030', 'BIG5'), true);
    if ($enc !== 'UTF-8') { $raw = mb_convert_encoding((string)$raw, 'UTF-8', $enc ?: 'UTF-8'); }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . e($target['name']) . '</title>'
        . '<link rel="stylesheet" href="' . e(css()) . '"></head><body style="padding:0">'
        . '<div class="box"><div class="boxHd">' . e($target['name'])
        . ' <span class="boxRt"><a href="' . e(url('s.php?c=' . rawurlencode($code) . '&do=down' . ($subFileId ? '&f=' . $subFileId : ''))) . '">下载原文件</a>'
        . '　<a href="' . e(url('s.php?c=' . rawurlencode($code))) . '">返回</a></span></div>'
        . '<div class="boxBd"><pre class="txtView">' . e($raw)
        . (filesize($abs) > 512 * 1024 ? "\n\n……（仅显示前 512KB，请下载完整文件）" : '') . '</pre></div></div></body></html>';
    share_log($code, '在线看文本', 0);
    exit;
}

if (is_post() && post('do') === 'save') {
    if (auth_uid() === 0) { go('login.php?back=' . urlencode('s.php?c=' . rawurlencode($code))); }
    csrf_check();
    if (!$target) { alert('转存目标不存在。', url('s.php?c=' . rawurlencode($code))); }
    $uu = auth_user();
    if ((int)$uu['verified'] === 0) { alert('你的邮箱未验证，不能转存。', url('user.php')); }
    $chk = quota_check($uu, (int)$target['size']);
    if ($chk !== true) { alert((string)$chk, url('s.php?c=' . rawurlencode($code))); }
    $new = db_insert('wc_file', array(
        'uid' => auth_uid(), 'folder' => 0, 'name' => $target['name'], 'ext' => $target['ext'], 'kind' => $target['kind'],
        'size' => (int)$target['size'], 'sha1' => $target['sha1'], 'path' => $target['path'],
        'tags' => '转存自外链', 'add_time' => time(),
    ));
    db_exec('UPDATE wc_user SET used=used+? WHERE id=?', array((int)$target['size'], auth_uid()));
    log_write('file', '转存外链文件', $target['name'] . ' code=' . $code, auth_uid(), $uu['nick']);
    alert('转存成功，文件已放入你的网盘根目录（编号 #' . (int)$new . '）。', url('files.php'), '转存完成');
}

/* 浏览量 */
db_exec('UPDATE wc_share SET view=view+1 WHERE code=?', array($code));
if ($file) { db_exec('UPDATE wc_file SET view=view+1 WHERE id=?', array((int)$file['id'])); }
share_log($code, '浏览', 0);

$ownerShow = $s['nick'] !== '' ? $s['nick'] : (mb_substr((string)$s['email'], 0, 3) . '***' . substr(strstr((string)$s['email'], '@'), 0, 20));

/* ---------- 页面 ---------- */
$ogTitle = $file ? $file['name'] : ($folder['name'] . ' 文件夹');
$ogDesc = $file
    ? kind_name($file['kind']) . ' 文件，大小 ' . format_size($file['size']) . '，来自 ' . setting('site_name', SITE_NAME) . ' 外链分享'
    : '文件夹分享，共 ' . $folderCnt . ' 个文件，合计 ' . format_size($folderBytes) . '，可在 ' . setting('site_name', SITE_NAME) . ' 打包下载';
$ogImg = ($file && $file['kind'] === 'image' && $absMain !== '') ? url('d.php?c=' . rawurlencode($code) . '&a=inline') : '';
page_head($ogTitle . ' - 外链下载', array(
    'side' => true, 'share' => true, 'canonical' => share_page_url($code),
    'og' => array('title' => $ogTitle, 'description' => $ogDesc, 'image' => $ogImg),
));

box_start('文件分享', '<a href="' . e(url('report.php?code=' . rawurlencode($code))) . '">举报此链接</a>');
echo '<table class="shareTb"><tr><td class="shareLeft">';
$absMain = $target ? file_abs($target) : '';
if ($file) {
    if ($file['kind'] === 'image' && $absMain !== '' && (int)$file['size'] <= parse_size(setting('hot_preview_max', '20 MB'))) {
        echo '<a href="' . e(url('d.php?c=' . rawurlencode($code) . '&a=inline')) . '" target="_blank">'
            . '<img src="' . e(url('d.php?c=' . rawurlencode($code) . '&a=inline')) . '" alt="' . e($file['name']) . '" class="shareImg"></a>';
    } elseif ($file['kind'] === 'video' && $absMain !== '') {
        echo '<video src="' . e(url('d.php?c=' . rawurlencode($code) . '&a=inline')) . '" controls preload="metadata" class="shareVideo"></video>';
    } elseif ($file['kind'] === 'audio' && $absMain !== '') {
        echo '<audio src="' . e(url('d.php?c=' . rawurlencode($code) . '&a=inline')) . '" controls preload="metadata" class="shareAudio"></audio>';
    } else {
        echo '<div class="bigIcon">' . file_icon($file['kind'], $file['ext']) . '</div>';
    }
} else {
    echo '<div class="bigIcon"><span class="fIcon fI_dir">夹</span></div>';
}
echo '</td><td class="shareRight">'
    . '<div class="shareName">' . e($file ? $file['name'] : $folder['name'] . '/（文件夹分享）') . '</div>'
    . '<div class="shareMeta">'
    . '类型：' . e($file ? kind_name($file['kind']) . '（.' . e($file['ext']) . '）' : '文件夹') . '<br>'
    . '大小：' . e($file ? format_size($file['size']) : format_size($folderBytes) . ' ／ ' . $folderCnt . ' 个文件') . '<br>'
    . '分享者：' . e($ownerShow) . '<br>'
    . '浏览：' . (int)$s['view'] . ' 次　下载：' . (int)$s['down'] . ' 次<br>'
    . '有效期：' . ($s['expire'] > 0 ? e(date('Y-m-d H:i', (int)$s['expire'])) . '（剩余约 ' . max(0, (int)ceil(($s['expire'] - time()) / 86400)) . ' 天）' : '永久有效') . '<br>'
    . ($s['max_down'] > 0 ? '下载上限：' . (int)$s['max_down'] . ' 次<br>' : '')
    . ($needPwd ? '访问密码：已开启<br>' : '')
    . '</div><div class="shareBtn">'
    . '<a class="bigBtn" href="' . e(url('s.php?c=' . rawurlencode($code) . '&do=down' . ($isFolder && $subFileId ? '&f=' . $subFileId : ''))) . '">'
    . ($isFolder && !$subFileId ? '打包下载 ZIP' : '下载此文件') . '</a> ';
if ($file && in_array($file['kind'], array('image', 'video', 'audio'), true)) {
    echo '<a class="bigBtn2" href="' . e(url('d.php?c=' . rawurlencode($code) . '&a=inline')) . '" target="_blank">在线预览</a> ';
}
if ($file && in_array($file['ext'], array('txt', 'md', 'csv', 'log', 'json', 'xml', 'sql', 'ini'), true)) {
    echo '<a class="bigBtn2" href="' . e(url('s.php?c=' . rawurlencode($code) . '&do=text')) . '" target="_blank">查看文本</a> ';
}
echo '<form method="post" action="' . e(url('s.php?c=' . rawurlencode($code))) . '" class="inl">' . csrf_field()
    . '<input type="hidden" name="do" value="save"><input type="submit" class="btn" value="转存到我的网盘"></form>'
    . '</div>'
    . '<div class="shareUrlBox">分享地址：<input type="text" readonly size="40" value="' . e(share_page_url($code)) . '" onclick="this.select()">'
    . (setting('qr_enable', '1') ? '<br><img src="' . e(url('qrcode.php?t=' . rawurlencode(share_page_url($code)))) . '" width="150" height="150" alt="二维码" class="qr">' : '')
    . '</div>'
    . ($s['direct'] ? '<div class="shareUrlBox">直链地址：<input type="text" readonly size="40" value="' . e(share_direct_url($code)) . '" onclick="this.select()"></div>' : '')
    . '</td></tr></table>';
box_end();

if ($isFolder) {
    $subFiles = db_rows('SELECT * FROM wc_file WHERE uid=? AND folder=? AND del_time=0 ORDER BY name LIMIT 500', array((int)$s['uid'], (int)$folder['id']));
    box_start('文件夹内容（' . count($subFiles) . ' 个文件）', '<a class="btn" href="' . e(url('s.php?c=' . rawurlencode($code) . '&do=down')) . '">全部打包下载</a>');
    if (!$subFiles) {
        echo '<div class="empty">这个文件夹目前是空的。</div>';
    } else {
        echo '<table class="tb list"><tr class="hd"><td width="40"></td><td>名称</td><td width="80">类型</td>'
            . '<td width="90" align="right">大小</td><td width="120">操作</td></tr>';
        foreach ($subFiles as $f) {
            echo '<tr><td>' . file_icon($f['kind'], $f['ext']) . '</td><td>' . e($f['name']) . '</td>'
                . '<td>' . e(kind_name($f['kind'])) . '</td><td align="right">' . e(format_size($f['size'])) . '</td>'
                . '<td><a href="' . e(url('s.php?c=' . rawurlencode($code) . '&do=down&f=' . (int)$f['id'])) . '">下载</a> '
                . (in_array($f['ext'], array('txt', 'md', 'csv', 'log', 'json', 'xml', 'sql'), true) ? '<a href="' . e(url('s.php?c=' . rawurlencode($code) . '&do=text&f=' . (int)$f['id'])) . '">文本</a>' : '')
                . '</td></tr>';
        }
        echo '</table>';
    }
    box_end();
}

$other = db_rows('SELECT * FROM wc_share WHERE uid=? AND status=1 AND code<>? AND file_id>0 ORDER BY `down` DESC LIMIT 8', array((int)$s['uid'], $code));
if ($other && setting('share_page_view', '1')) {
    box_start('该用户的其他分享');
    echo '<ul class="linkList">';
    foreach ($other as $o) {
        echo '<li><a href="' . e(share_page_url($o['code'])) . '">' . e(cut_str($o['title'], 40)) . '</a> <span class="mini">下载 ' . (int)$o['down'] . ' 次</span></li>';
    }
    echo '</ul>';
    box_end();
}
page_foot();
