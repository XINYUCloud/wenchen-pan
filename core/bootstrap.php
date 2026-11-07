<?php
/**
 * 文谶云盘 —— HTML + PHP + MySQL + Redis 多格式外链云盘
 * Copyright (C) 2026 文谶 (fohok.xin)
 * 本程序在 GNU 通用公共许可证 v3（或更高版本）条款下分发，详见仓库根目录 LICENSE。
 *
 * 全站入口引导：所有业务页面第一行 require 本文件
 * 用法： require_once __DIR__ . '/core/bootstrap.php';
 */
if (defined('WC_BOOTED')) { return; }
define('WC_BOOTED', 1);
define('WC_PAN', 1);
define('WC_START', microtime(true));
define('WC_VERSION', '1.0');
define('WC_ROOT', dirname(__DIR__));

$wcCfg = WC_ROOT . '/config/config.php';
if (!is_file($wcCfg)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "尚未安装：请运行 install/index.php，或复制 config/config.sample.php 为 config/config.php\n");
        exit(1);
    }
    $dir = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'));
    $pre = (basename($dir) === 'admin') ? '../' : '';
    header('Content-Type: text/html; charset=utf-8');
    echo '<meta charset="utf-8"><div style="font:12px SimSun;padding:40px"><b>系统尚未安装</b><br>'
        . '请先运行安装向导：<a href="' . $pre . 'install/index.php">继续安装</a></div>';
    exit;
}

require_once $wcCfg;
require_once WC_ROOT . '/core/db.php';
require_once WC_ROOT . '/core/kv.php';
require_once WC_ROOT . '/core/functions.php';
require_once WC_ROOT . '/core/auth.php';
require_once WC_ROOT . '/core/mailer.php';
require_once WC_ROOT . '/core/thumb.php';
require_once WC_ROOT . '/core/storage.php';
require_once WC_ROOT . '/core/zip.php';
require_once WC_ROOT . '/core/upload.php';
require_once WC_ROOT . '/core/template.php';
require_once WC_ROOT . '/core/security.php';
require_once WC_ROOT . '/core/guard.php';

date_default_timezone_set(defined('TIMEZONE') ? TIMEZONE : 'Asia/Shanghai');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');
if (!DEBUG) { ini_set('error_log', DATA_DIR . '/logs/php_error.log'); }
set_exception_handler('wc_exception_handler');

function wc_exception_handler($e)
{
    $msg = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    if (is_dir(DATA_DIR)) { @file_put_contents(DATA_DIR . '/logs/app.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND); }
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg . "\n"); exit(1); }
    if (DEBUG) { die($msg); }
    wc_alert('系统发生异常，请稍后重试或联系管理员。', wc_url());
}

// 安全响应头（CSP / HSTS / Referrer / Permissions / 隐藏版本）
sec_headers();

wc_dir_ensure(DATA_DIR);
wc_dir_ensure(DATA_DIR . '/logs');
wc_dir_ensure(wc_storage_path());
wc_dir_ensure(wc_thumb_path());
wc_dir_ensure(wc_tmp_path());

wc_session_start_kv();

// 防爬虫引擎（页面/文件/接口三种口径分别限流，见 core/guard.php）
guard_run(defined('WC_REQ_KIND') ? WC_REQ_KIND : 'page');

// 会话指纹绑定（UA / IP 段），异常则强制重新登录
sec_bind_check();

// 自动维护（最长 30 分钟一次，无需 crontab）
if (wc_setting('cron_auto', '1')) { wc_maybe_cron(); }

// IP 黑名单（已登录的管理员不受限，避免把自己锁在门外）
if (wc_ip_blocked() && !wc_is_admin()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<meta charset="utf-8"><div style="font:13px SimSun;padding:40px"><b>403 禁止访问</b><br>'
        . '你的 IP（' . wc_e(wc_ip()) . '）在站点黑名单中，如有疑问请联系管理员。</div>';
    exit;
}

// 闭站维护开关：仅放行登录/验证/外链下载与后台
if (wc_setting('site_close', '0') === '1') {
    $script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
    $inAdmin = strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false;
    $gateAllow = array('s.php', 'd.php', 'img.php', 'login.php', 'logout.php', 'captcha.php', 'verify.php', 'forgot.php', 'index.php', 'robots.php', 'sitemap.php');
    if (!$inAdmin && !in_array($script, $gateAllow, true) && !wc_is_admin()) {
        wc_alert('本站维护中，暂停访问。' . wc_e(wc_setting('site_close_msg', '')), wc_url('login.php'));
    }
    if ($script === 'index.php' && !wc_is_admin() && !wc_auth_uid()) {
        // 游客直接看闭站提示页
        if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>维护中</title><link rel="stylesheet" href="' . wc_e(wc_css()) . '"></head>'
            . '<body><div class="tipbox"><div class="tithd">站点维护</div><div class="titbd">'
            . wc_e(wc_setting('site_close_msg', '系统维护中，请稍后再来。'))
            . '</div><div class="tiFot"><a class="mybtn" href="' . wc_e(wc_url('login.php')) . '">管理员登录</a></div></div></body></html>';
        exit;
    }
}

/** 后台权限：仅 admin/super，且受 IP 白名单约束 */
function wc_require_admin($soft = false)
{
    if (!sec_admin_ip_allowed()) {
        // 已登录且会话有效的管理员放行，避免把自己锁在门外
        if (!(wc_is_admin() && wc_setting('admin_ip_allow_session', '1'))) {
            if ($soft) { return false; }
            http_response_code(403);
            wc_alert('管理后台仅限指定 IP 访问，你的 IP（' . wc_e(wc_ip()) . '）不在白名单内。', wc_url());
        }
    }
    if (wc_is_admin()) { return true; }
    if ($soft) { return false; }
    $u = wc_auth_user();
    if (!$u) { wc_go('admin/login.php?back=' . urlencode(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/admin/index.php')); }
    wc_alert('你没有进入管理后台的权限。当前账号：' . wc_e($u['email']), wc_url());
}

/** 仅超级管理员可操作 */
function wc_require_super($soft = false)
{
    if (wc_auth_role() === 'super') { return true; }
    if ($soft) { return false; }
    wc_alert('该操作仅限超级管理员。', isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : wc_url());
}

/** 当前页面所在目录（后台内部用） */
function wc_self_dir()
{
    return strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false ? '../' : '';
}
