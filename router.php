<?php
/**
 * PHP 内置服务器路由（本机 php -S 用），同时提供 /s/短码 与 /d/短码 伪静态。
 * 宝塔/Apache 用根目录 .htaccess，Nginx 用 nginx.conf.example 里的 rewrite。
 * 用法：php -S 127.0.0.1:8093 -t . router.php
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode((string)$uri);
$root = __DIR__;

/* 静态资源与已存在的文件直接返回 */
$file = $root . $uri;
if ($uri !== '/' && is_file($file) && strpos($uri, '/data/') !== 0) {
    return false;
}

/* 禁止直接访问内部目录 */
if (preg_match('#^/(data|core|config|install/[^i])#i', $uri)) {
    if (strpos($uri, '/install/index.php') === 0 || $uri === '/install/' || $uri === '/install') {
        // 放行安装向导
    } else {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('403 Forbidden');
    }
}

if ($uri === '/robots.txt') {
    $_SERVER['SCRIPT_NAME'] = '/robots.php';
    require $root . '/robots.php';
    return true;
}
if ($uri === '/sitemap.xml') {
    $_SERVER['SCRIPT_NAME'] = '/sitemap.php';
    require $root . '/sitemap.php';
    return true;
}

if (preg_match('#^/s/([A-Za-z0-9]+)/?$#', $uri, $m)) {
    $_GET['c'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/s.php';
    require $root . '/s.php';
    return true;
}
if (preg_match('#^/d/([A-Za-z0-9]+)/?$#', $uri, $m)) {
    $_GET['c'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/d.php';
    require $root . '/d.php';
    return true;
}

if ($uri === '/' || $uri === '') {
    require $root . '/index.php';
    return true;
}

/* 其它未知路径：404 */
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<meta charset="utf-8"><div style="font:13px SimSun;padding:40px"><b>404 页面不存在</b><br>'
    . '地址：' . htmlspecialchars($uri) . '<br><a href="/">返回首页</a> · <a href="/install/index.php">安装向导</a></div>';
return true;
