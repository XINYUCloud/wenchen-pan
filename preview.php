<?php
/** 在线预览：图片灯箱 / 视频 / 音频 / 文本代码 / PDF / 其他给下载 */
require_once __DIR__ . '/core/bootstrap.php';
require_login();
$u = auth_user();

$id = getint('id');
$f = db_row('SELECT * FROM wc_file WHERE id=?', array($id));
if (!$f) { alert('文件不存在。', url('files.php'), '提示'); }
$isOwner = (int)$f['uid'] === (int)$u['id'];
if (!$isOwner && !is_admin()) { alert('无权查看这个文件。', url('files.php'), '提示'); }
if ((int)$f['del_time'] > 0 && !is_admin()) { alert('文件在回收站中，请先还原。', url('trash.php'), '提示'); }

$abs = file_abs($f);
$inlineUrl = url('api.php?do=download&id=' . (int)$f['id'] . '&a=inline');
$dlUrl = url('api.php?do=download&id=' . (int)$f['id']);
if ($abs !== '' && !$isOwner) { db_exec('UPDATE wc_file SET view=view+1 WHERE id=?', array((int)$f['id'])); }

page_head('预览：' . cut_str($f['name'], 24));
crumbs(array(array('我的文件', url('files.php')), '在线预览'));
box_start(e(cut_str($f['name'], 40)),
    '<a href="' . e(url('files.php?act=share&id=' . (int)$f['id'])) . '">生成外链</a> | '
    . '<a href="' . e($dlUrl) . '">下载</a> | '
    . '<a href="' . e(url('files.php?act=info&id=' . (int)$f['id'])) . '">详情</a>');

echo '<div class="pvName"><b>' . e($f['name']) . '</b> <span class="mini">'
    . e(kind_name($f['kind'])) . ' · ' . e(format_size($f['size'])) . ' · ' . e(date('Y-m-d H:i', (int)$f['add_time']))
    . ($isOwner ? '' : ' · 来自 #' . (int)$f['uid'] . ' 号用户（管理员审阅）') . '</span></div>';

if ($abs === '') {
    tip('实体文件已丢失，可能由管理员清理过磁盘。', 'err');
} elseif ($f['kind'] === 'image' && $f['ext'] !== 'svg') {
    echo '<div class="pvImgBox"><img src="' . e($inlineUrl) . '" alt="' . e($f['name']) . '" class="pvImg" '
        . 'onclick="this.classList.toggle(\'pvFull\')"></div>'
        . '<div class="mini" style="text-align:center">点击图片可切换放大 / 适应窗口</div>';
} elseif ($f['kind'] === 'video') {
    echo '<div class="pvVideo"><video src="' . e($inlineUrl) . '" controls preload="metadata" class="pvVid"></video></div>'
        . '<div class="mini" style="text-align:center">支持 Range 拖动进度；若浏览器不支持该编码请下载观看</div>';
} elseif ($f['kind'] === 'audio') {
    echo '<div class="pvAudio"><audio src="' . e($inlineUrl) . '" controls preload="metadata"></audio></div>';
} elseif ($f['ext'] === 'pdf') {
    echo '<iframe src="' . e($inlineUrl) . '" class="pvFrame" title="PDF 预览"></iframe>';
} elseif (in_array($f['ext'], array('txt', 'md', 'csv', 'log', 'json', 'xml', 'ini', 'yml', 'sql', 'js', 'css', 'html', 'htm', 'php'), true)) {
    $raw = file_get_contents($abs, false, null, 0, 256 * 1024);
    $enc = mb_detect_encoding((string)$raw, array('UTF-8', 'GB18030', 'BIG5', 'ASCII'), true);
    if ($enc !== false && $enc !== 'UTF-8') { $raw = mb_convert_encoding((string)$raw, 'UTF-8', $enc); }
    echo '<pre class="txtView">' . e($raw) . (filesize($abs) > 256 * 1024 ? "\n\n……（仅显示前 256KB）" : '') . '</pre>'
        . '<div class="mini">编码检测：' . e($enc ?: '未知') . '，' . e(function_exists('mb_strlen') ? number_format(mb_substr_count((string)$raw, "\n") + 1) : '-') . ' 行</div>';
} else {
    echo '<div class="pvNone"><div class="bigIcon">' . file_icon($f['kind'], $f['ext']) . '</div>'
        . '<p>该格式（.' . e($f['ext']) . '）暂不支持浏览器内预览，'
        . ($f['ext'] === 'svg' ? '出于安全考虑 SVG 不允许直接渲染，' : '')
        . '请 <a href="' . e($dlUrl) . '">下载后打开</a>。</p></div>';
}
echo '<div class="pvShare">';
$sh = db_row('SELECT * FROM wc_share WHERE file_id=? AND status=1 ORDER BY id DESC LIMIT 1', array((int)$f['id']));
if ($sh) {
    echo '<span class="mini">外链：</span><input type="text" readonly size="42" value="' . e(share_page_url($sh['code'])) . '" onclick="this.select()"> '
        . '<a href="' . e(share_page_url($sh['code'])) . '" target="_blank">打开</a>';
} else {
    echo '<a class="mybtn" href="' . e(url('files.php?act=share&id=' . (int)$f['id'])) . '">为这个文件创建外链</a>';
}
echo '</div>';
box_end();
page_foot();
