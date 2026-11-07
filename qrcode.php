<?php
/** 二维码：302 跳转到可配置的二维码接口（默认公共 API，可在后台换成自建服务或关闭） */
require_once __DIR__ . '/core/bootstrap.php';

$text = get('t') !== '' ? get('t') : post('t');
$text = mb_substr((string)$text, 0, 1000);
if ($text === '' || !preg_match('#^https?://#i', $text)) {
    http_response_code(400);
    exit('二维码内容必须是一个 http/https 地址');
}
if (!setting('qr_enable', '1')) {
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}
$api = trim(setting('qr_api', 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='));
if ($api === '') { $api = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='; }
$target = $api . rawurlencode($text);
if (!preg_match('#^https?://#i', $target)) { http_response_code(500); exit('后台二维码接口配置不正确'); }
header('Cache-Control: private, max-age=3600');
header('Location: ' . $target);
