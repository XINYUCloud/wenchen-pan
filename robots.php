<?php
/** robots.txt 动态输出，跟随后台 SEO 与闭站开关 */
require_once __DIR__ . '/core/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
$base = rtrim(SITE_URL, '/');

if (setting('seo_robots_file', '1') !== '1') {
    echo "# 后台已关闭 robots.txt 输出\n";
    exit;
}
if (setting('site_close', '0') === '1') {
    echo "User-agent: *\nDisallow: /\n";
    exit;
}

$lines = array('User-agent: *');
$disallow = array('/', '/data/', '/core/', '/config/', '/install/', '/admin/', '/api.php', '/chunk.php',
    '/upload.php', '/files.php', '/trash.php', '/shares.php', '/user.php', '/notice.php', '/preview.php',
    '/img.php', '/d.php', '/captcha.php', '/login.php', '/logout.php', '/register.php', '/verify.php', '/forgot.php',
    '/api', '/.git', '/.env');
foreach ($disallow as $d) { $lines[] = 'Disallow: ' . $d; }
$lines[] = 'Disallow: /*?fid=';
$lines[] = 'Disallow: /*?q=';
if (setting('seo_index_share', '0')) {
    $lines[] = 'Allow: /s/';
} else {
    $lines[] = 'Disallow: /s/';
    $lines[] = 'Disallow: /d/';
}
$lines[] = 'Crawl-delay: 5';
$lines[] = '';
if (setting('seo_sitemap', '1')) { $lines[] = 'Sitemap: ' . $base . '/sitemap.xml'; }
$lines[] = '# 文谶云盘 自动生成，规则可在后台「站点设置 → SEO 优化」调整';
echo implode("\n", $lines) . "\n";
