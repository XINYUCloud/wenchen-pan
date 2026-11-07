<?php
/** 在线预览：图片灯箱 / 视频 / 音频 / 文本代码 / PDF / 其他给下载 */
require_once __DIR__ . '/core/bootstrap.php';
wc_require_login();
$u = wc_auth_user();

$id = wc_getint('id');
$f = wc_db_row('SELECT * FROM wc_file WHERE id=?', array($id));
if (!$f) { wc_alert('文件不存在。', wc_url('files.php'), '提示'); }
$isOwner = (int)$f['uid'] === (int)$u['id'];
if (!$isOwner && !wc_is_admin()) { wc_alert('无权查看这个文件。', wc_url('files.php'), '提示'); }
if ((int)$f['del_time'] > 0 && !wc_is_admin()) { wc_alert('文件在回收站中，请先还原。', wc_url('trash.php'), '提示'); }

$abs = wc_file_abs($f);
$inlineUrl = wc_url('api.php?do=download&id=' . (int)$f['id'] . '&a=inline');
$dlUrl = wc_url('api.php?do=download&id=' . (int)$f['id']);
if ($abs !== '' && !$isOwner) { wc_db_exec('UPDATE wc_file SET view=view+1 WHERE id=?', array((int)$f['id'])); }

wc_page_head('预览：' . wc_cut_str($f['name'], 24));
wc_crumbs(array(array('我的文件', wc_url('files.php')), '在线预览'));
wc_box_start(wc_e(wc_cut_str($f['name'], 40)),
    '<a href="' . wc_e(wc_url('files.php?act=share&id=' . (int)$f['id'])) . '">生成外链</a> | '
    . '<a href="' . wc_e($dlUrl) . '">下载</a> | '
    . '<a href="' . wc_e(wc_url('files.php?act=info&id=' . (int)$f['id'])) . '">详情</a>');

echo '<div class="pvName"><b>' . wc_e($f['name']) . '</b> <span class="mini">'
    . wc_e(wc_kind_name($f['kind'])) . ' · ' . wc_e(wc_format_size($f['size'])) . ' · ' . wc_e(date('Y-m-d H:i', (int)$f['add_time']))
    . ($isOwner ? '' : ' · 来自 #' . (int)$f['uid'] . ' 号用户（管理员审阅）') . '</span></div>';

if ($abs === '') {
    wc_tip('实体文件已丢失，可能由管理员清理过磁盘。', 'err');
} elseif ($f['kind'] === 'image' && $f['ext'] !== 'svg') {
    echo '<div class="pvImgBox"><img src="' . wc_e($inlineUrl) . '" alt="' . wc_e($f['name']) . '" class="pvImg" '
        . 'onclick="this.classList.toggle(\'pvFull\')"></div>'
        . '<div class="mini" style="text-align:center">点击图片可切换放大 / 适应窗口</div>';
} elseif ($f['kind'] === 'video') {
    echo '<div class="pvVideo"><video src="' . wc_e($inlineUrl) . '" controls preload="metadata" class="pvVid"></video></div>'
        . '<div class="mini" style="text-align:center">支持 Range 拖动进度；若浏览器不支持该编码请下载观看</div>';
} elseif ($f['kind'] === 'audio') {
    echo '<div class="pvAudio"><audio src="' . wc_e($inlineUrl) . '" controls preload="metadata"></audio></div>';
} elseif ($f['ext'] === 'pdf') {
    echo '<iframe src="' . wc_e($inlineUrl) . '" class="pvFrame" title="PDF 预览"></iframe>';
} elseif (in_array($f['ext'], array('txt', 'md', 'csv', 'log', 'json', 'xml', 'ini', 'yml', 'sql', 'js', 'css', 'html', 'htm', 'php'), true)) {
    $raw = file_get_contents($abs, false, null, 0, 256 * 1024);
    $enc = mb_detect_encoding((string)$raw, array('UTF-8', 'GB18030', 'BIG5', 'ASCII'), true);
    if ($enc !== false && $enc !== 'UTF-8') { $raw = mb_convert_encoding((string)$raw, 'UTF-8', $enc); }
    echo '<pre class="txtView">' . wc_e($raw) . (filesize($abs) > 256 * 1024 ? "\n\n……（仅显示前 256KB）" : '') . '</pre>'
        . '<div class="mini">编码检测：' . wc_e($enc ?: '未知') . '，' . wc_e(function_exists('mb_strlen') ? number_format(mb_substr_count((string)$raw, "\n") + 1) : '-') . ' 行</div>';
} else {
    echo '<div class="pvNone"><div class="bigIcon">' . wc_file_icon($f['kind'], $f['ext']) . '</div>'
        . '<p>该格式（.' . wc_e($f['ext']) . '）暂不支持浏览器内预览，'
        . ($f['ext'] === 'svg' ? '出于安全考虑 SVG 不允许直接渲染，' : '')
        . '请 <a href="' . wc_e($dlUrl) . '">下载后打开</a>。</p></div>';
}
echo '<div class="pvShare">';
$sh = wc_db_row('SELECT * FROM wc_share WHERE file_id=? AND status=1 ORDER BY id DESC LIMIT 1', array((int)$f['id']));
if ($sh) {
    echo '<span class="mini">外链：</span><input type="text" readonly size="42" value="' . wc_e(wc_share_page_url($sh['code'])) . '" onclick="this.select()"> '
        . '<a href="' . wc_e(wc_share_page_url($sh['code'])) . '" target="_blank">打开</a>';
} else {
    echo '<a class="mybtn" href="' . wc_e(wc_url('files.php?act=share&id=' . (int)$f['id'])) . '">为这个文件创建外链</a>';
}
echo '</div>';
wc_box_end();
wc_page_foot();
