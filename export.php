<?php
/**
 * 批量直链导出：选中文件或整个文件夹 → TXT / Markdown / HTML 相册 / 图片墙 / JSON
 * 仅导出自己拥有的文件；签名直链按所选有效期生成
 */
define('WC_REQ_KIND', 'api');
require_once __DIR__ . '/core/bootstrap.php';
wc_require_login();
$u = wc_auth_user();

$fmt = wc_get('fmt') !== '' ? wc_get('fmt') : wc_post('fmt');
if (!in_array($fmt, array('txt', 'md', 'html', 'wall', 'json'), true)) { $fmt = 'txt'; }
$ttl = wc_getint('ttl') > 0 ? wc_getint('ttl') : wc_postint('ttl');
$ids = wc_intarr(isset($_REQUEST['file']) ? $_REQUEST['file'] : array());
$fid = wc_getint('fid') > 0 ? wc_getint('fid') : wc_postint('fid');

$rows = array();
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = wc_db_rows('SELECT * FROM wc_file WHERE uid=? AND del_time=0 AND id IN (' . $ph . ') ORDER BY name',
        array_merge(array((int)$u['id']), $ids));
} else {
    // fid=0 表示根目录
    if ($fid > 0 && !wc_folder_check_owner($fid, (int)$u['id'])) { wc_alert('无权导出该文件夹。', wc_url('files.php')); }
    $rows = wc_db_rows('SELECT * FROM wc_file WHERE uid=? AND folder=? AND del_time=0 ORDER BY name', array((int)$u['id'], $fid));
}
if (!$rows) { wc_alert('没有可导出的文件。', wc_url('files.php')); }

/** 为每个文件确保有一条可用外链（没有则创建） */
$items = array();
foreach ($rows as $f) {
    $s = wc_db_row('SELECT * FROM wc_share WHERE file_id=? AND uid=? AND status=1 ORDER BY id DESC LIMIT 1', array((int)$f['id'], (int)$u['id']));
    if (!$s) {
        $s = wc_share_create((int)$u['id'], (int)$f['id'], 0, array('days' => 0, 'direct' => 1));
    } elseif (!(int)$s['direct']) {
        wc_db_exec('UPDATE wc_share SET direct=1 WHERE code=?', array($s['code']));
        $s['direct'] = 1;
    }
    $direct = $ttl > 0 ? wc_sign_link($s['code'], $ttl) : wc_share_direct_url($s['code']);
    $items[] = array('file' => $f, 'share' => $s, 'direct' => $direct, 'page' => wc_share_page_url($s['code']));
}

$site = wc_setting('site_name', SITE_NAME);
$stamp = date('Ymd_His');
$extMap = array('txt' => 'txt', 'md' => 'md', 'html' => 'html', 'wall' => 'html', 'json' => 'json');
$mimeMap = array('txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8',
    'html' => 'text/html; charset=utf-8', 'wall' => 'text/html; charset=utf-8', 'json' => 'application/json; charset=utf-8');

$body = '';
if ($fmt === 'txt') {
    foreach ($items as $it) {
        $body .= $it['file']['name'] . "\t" . $it['direct'] . "\t" . wc_format_size($it['file']['size']) . "\n";
    }
} elseif ($fmt === 'md') {
    $body = '# ' . $site . ' 直链导出（' . date('Y-m-d H:i') . '）' . "\n\n";
    foreach ($items as $it) {
        $f = $it['file'];
        if ($f['kind'] === 'image') {
            $body .= '![' . $f['name'] . '](' . $it['direct'] . ")\n\n";
        } else {
            $body .= '- [' . $f['name'] . '](' . $it['page'] . ') · ' . wc_format_size($f['size']) . "\n";
        }
    }
} elseif ($fmt === 'json') {
    $arr = array();
    foreach ($items as $it) {
        $arr[] = array('name' => $it['file']['name'], 'size' => (int)$it['file']['size'], 'kind' => $it['file']['kind'],
            'ext' => $it['file']['ext'], 'page' => $it['page'], 'direct' => $it['direct'],
            'poster' => $it['file']['kind'] === 'video' ? $it['direct'] . (strpos($it['direct'], '?') === false ? '?' : '&') . 'poster=1' : '',
            'sha1' => $it['file']['sha1']);
    }
    $body = json_encode(array('site' => $site, 'exported_at' => date('c'), 'count' => count($arr), 'items' => $arr), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    $isWall = $fmt === 'wall';
    $body = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . wc_e($site) . ' 相册导出</title><style>'
        . 'body{margin:0;font:14px/1.7 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#0f1115;color:#e6e9ef}'
        . '.wrap{max-width:1100px;margin:0 auto;padding:28px 18px}h1{font-size:20px}p.meta{color:#8b95a3;font-size:12px}'
        . '.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}'
        . '.card{background:#171a21;border:1px solid #262b36;border-radius:14px;overflow:hidden}'
        . '.card img{width:100%;height:170px;object-fit:cover;display:block;background:#0b0d11}'
        . '.card .t{padding:10px 12px;font-size:13px;word-break:break-all}.card .s{padding:0 12px 12px;color:#8b95a3;font-size:12px}'
        . 'a{color:#7fb0ff;text-decoration:none}ul{padding-left:20px}'
        . '</style></head><body><div class="wrap"><h1>' . wc_e($site) . ' · 导出相册</h1>'
        . '<p class="meta">共 ' . count($items) . ' 个文件 · ' . wc_e(date('Y-m-d H:i')) . ($ttl > 0 ? ' · 直链有效期 ' . (int)($ttl / 3600) . ' 小时' : '') . '</p>';
    if ($isWall) {
        $body .= '<div class="grid">';
        foreach ($items as $it) {
            $f = $it['file'];
            $thumb = $f['kind'] === 'image' ? $it['direct'] . (strpos($it['direct'], '?') === false ? '?' : '&') . 'w=440&h=340&crop=1&q=80' : '';
            $body .= '<div class="card">' . ($thumb !== '' ? '<a href="' . wc_e($it['page']) . '" target="_blank"><img src="' . wc_e($thumb) . '" alt="' . wc_e($f['name']) . '" loading="lazy"></a>' : '')
                . '<div class="t"><a href="' . wc_e($it['page']) . '" target="_blank">' . wc_e($f['name']) . '</a></div>'
                . '<div class="s">' . wc_e(wc_kind_name($f['kind'])) . ' · ' . wc_e(wc_format_size($f['size'])) . '</div></div>';
        }
        $body .= '</div>';
    } else {
        $body .= '<ul>';
        foreach ($items as $it) {
            $f = $it['file'];
            $body .= '<li><a href="' . wc_e($it['page']) . '" target="_blank">' . wc_e($f['name']) . '</a> · ' . wc_e(wc_format_size($f['size']))
                . ' · <a href="' . wc_e($it['direct']) . '">直链</a></li>';
        }
        $body .= '</ul>';
    }
    $body .= '</div></body></html>';
}

wc_log_write('file', '批量直链导出', $fmt . ' 共 ' . count($items) . ' 个', (int)$u['id'], $u['nick']);
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: ' . $mimeMap[$fmt]);
header('Content-Disposition: attachment; filename="links_' . $stamp . '.' . $extMap[$fmt] . '"');
header('Content-Length: ' . strlen($body));
echo $body;
