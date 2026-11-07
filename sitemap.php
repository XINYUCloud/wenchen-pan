<?php
/**
 * sitemap.xml 动态输出：首页 + 公告 + 固定内容页 + 用户勾选公开索引的外链
 * 结果缓存 10 分钟；后台「SEO 优化」可关闭
 */
require_once __DIR__ . '/core/bootstrap.php';

if (!wc_setting('seo_sitemap', '1')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('sitemap 已在后台关闭');
}

$cacheKey = 'sitemap:xml';
$cached = WcKV::get($cacheKey, '');
if ($cached !== '') {
    header('Content-Type: application/xml; charset=utf-8');
    echo $cached;
    exit;
}

$base = rtrim(SITE_URL, '/');
$urls = array();
$add = function ($loc, $lastmod = 0, $freq = 'weekly', $prio = '0.6') use (&$urls, $base) {
    $u = array('loc' => $base . $loc, 'lastmod' => $lastmod > 0 ? date('Y-m-d', $lastmod) : '', 'freq' => $freq, 'prio' => $prio);
    foreach ($urls as $old) { if ($old['loc'] === $u['loc']) { return; } }
    $urls[] = $u;
};

$add('/', time(), 'daily', '1.0');
$add('/announce.php', time(), 'daily', '0.7');
$add('/rank.php', time(), 'daily', '0.6');
$add('/help.php', time(), 'monthly', '0.4');
$add('/terms.php', time(), 'yearly', '0.3');
$add('/report.php', time(), 'monthly', '0.2');
$add('/register.php', time(), 'monthly', '0.5');

foreach (wc_db_rows('SELECT id,edit_time,add_time FROM wc_announce WHERE status=1 ORDER BY id DESC LIMIT 500') as $a) {
    $add('/announce.php?id=' . (int)$a['id'], (int)($a['edit_time'] ?: $a['add_time']), 'monthly', '0.5');
}

if (wc_setting('seo_index_share', '0')) {
    $days = wc_setting_int('seo_sitemap_days', 90);
    $where = "s.status=1 AND s.pub=1 AND s.file_id>0 AND f.del_time=0 AND (s.expire=0 OR s.expire>" . time() . ")";
    if ($days > 0) { $where .= ' AND s.add_time>' . (time() - $days * 86400); }
    $rows = wc_db_rows('SELECT s.code,s.add_time FROM wc_share s LEFT JOIN wc_file f ON f.id=s.file_id WHERE ' . $where . ' ORDER BY s.id DESC LIMIT 5000');
    foreach ($rows as $r) {
        $add('/s/' . rawurlencode($r['code']), (int)$r['add_time'], 'weekly', '0.4');
    }
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_QUOTES) . '</loc>'
        . ($u['lastmod'] !== '' ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '')
        . '<changefreq>' . $u['freq'] . '</changefreq>'
        . '<priority>' . $u['prio'] . '</priority></url>' . "\n";
}
$xml .= '</urlset>';

WcKV::set($cacheKey, $xml, 600);
header('Content-Type: application/xml; charset=utf-8');
header('X-Sitemap-Urls: ' . count($urls));
echo $xml;
