<?php
/**
 * 蜜罐地址：正常用户永远不会访问，页面里以隐藏链接存在
 * 一旦被请求，core/guard.php 会记录并按设置封禁该 IP
 */
require_once __DIR__ . '/core/bootstrap.php';

// 走到这里说明防爬已关闭或该 IP 在白名单内，返回 404 即可
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
header('X-Honeypot: 1');
echo '<meta charset="utf-8"><div style="font:13px SimSun;padding:40px"><b>404 页面不存在</b><br><a href="/">返回首页</a></div>';
