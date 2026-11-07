<?php
/** 后台公共件：权限、双皮肤外壳、菜单、动作校验 */
require_once dirname(__DIR__) . '/core/bootstrap.php';

/** @return array 菜单定义 */
function cp_menu()
{
    $reportOpen = wc_db_count('wc_report', 'status=0');
    return array(
        array('index.php', 'home', '控制台', ''),
        array('users.php', 'user', '用户管理', ''),
        array('files.php', 'folder', '文件管理', ''),
        array('shares.php', 'link', '外链管理', ''),
        array('announces.php', 'bell', '公告管理', ''),
        array('reports.php', 'warn', '举报处理', $reportOpen > 0 ? '<span class="badge">' . $reportOpen . '</span>' : ''),
        array('antiscan.php', 'bug', '防爬虫', ''),
        array('security.php', 'shield', '安全中心', ''),
        array('logs.php', 'list', '日志审计', ''),
        array('settings.php', 'tools', '站点设置', ''),
        array('mail.php', 'mail', '邮件配置', ''),
        array('tools.php', 'refresh', '系统工具', ''),
    );
}

function cp_header($title, $active = 'index.php')
{
    $u = wc_auth_user();
    $mdui = wc_ui_skin() === 'mdui';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"' . ($mdui ? ' class="' . wc_ui_theme_class() . '"' : '') . '><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="' . (wc_ui_theme() === 'light' ? 'light' : 'dark light') . '">'
        . '<title>' . wc_e($title) . ' - 管理后台 ' . wc_e(wc_setting('site_name', SITE_NAME)) . '</title>';
    if ($mdui) {
        echo '<link rel="stylesheet" href="' . wc_e(wc_ui_mdui_url('mdui.css')) . '">'
            . '<link rel="stylesheet" href="' . wc_e(wc_url('assets/mdui-dark.css?v=' . WC_VERSION)) . '">'
            . '<script src="' . wc_e(wc_ui_mdui_url('mdui.global.js')) . '"></script>';
    } else {
        echo '<link rel="stylesheet" href="' . wc_e(wc_url('assets/admin.css?v=' . WC_VERSION)) . '">'
            . '<link rel="stylesheet" href="' . wc_e(wc_url('assets/style.css?v=' . WC_VERSION)) . '">';
    }
    echo '<script src="' . wc_e(wc_url('assets/js/common.js?v=' . WC_VERSION)) . '"></script>'
        . '<script src="' . wc_e(wc_url('assets/js/ui.js?v=' . WC_VERSION)) . '"></script>'
        . '<script>window.WC_BASE=' . json_encode(rtrim(SITE_URL, '/') . '/') . ';window.WC_CSRF=' . json_encode(wc_csrf_token()) . ';</script>'
        . '</head><body class="' . ($mdui ? 'wc-mdui cp' : 'cp') . '" data-skin="' . wc_e(wc_ui_skin()) . '">';

    if ($mdui) {
        echo '<mdui-layout class="wcLayout"><mdui-top-app-bar class="wcBar" id="wcBar">'
            . '<mdui-button-icon id="wcMenuBtn" aria-label="切换导航"><i class="ic ic-menu"></i></mdui-button-icon>'
            . '<mdui-top-app-bar-title><a class="wcLogo" href="' . wc_e(wc_url('admin/index.php')) . '">' . wc_e(wc_setting('site_name', SITE_NAME)) . ' · 后台</a></mdui-top-app-bar-title>'
            . '<div class="wcBarR"><span class="mini">' . wc_e($u['nick'] !== '' ? $u['nick'] : $u['email']) . '（' . wc_e($u['role'] === 'super' ? '超管' : '管理员') . '）</span>'
            . '<mdui-button-icon id="wcThemeBtn" aria-label="切换深浅色"><i class="ic ic-moon"></i></mdui-button-icon>'
            . '<a class="wcLinkBtn" href="' . wc_e(wc_url()) . '" target="_blank">前台</a>'
            . '<a class="wcLinkBtn" href="' . wc_e(wc_url('logout.php')) . '">退出</a></div></mdui-top-app-bar>'
            . '<mdui-navigation-drawer id="wcDrawer" class="wcDrawer" open><div class="wcDrawerIn"><mdui-list class="wcNav">';
        foreach (cp_menu() as $m) {
            $on = basename($_SERVER['SCRIPT_NAME']) === $m[0] ? ' active' : '';
            echo '<mdui-list-item href="' . wc_e(wc_url('admin/' . $m[0])) . '"' . $on . '><i class="ic ic-' . wc_e($m[1]) . '" slot="icon"></i>' . wc_e($m[2]) . ' ' . $m[3] . '</mdui-list-item>';
        }
        echo '</mdui-list><mdui-divider></mdui-divider><div class="box"><div class="boxBd stat">存储后端：'
            . wc_e(WcKV::backend() === 'redis' ? 'Redis' : 'MySQL 降级') . '<br>防爬：' . wc_e(guard_enabled() ? '开启' : '关闭') . '</div></div>'
            . '</div></mdui-navigation-drawer><mdui-layout-main class="wcMain"><div class="wcContent" id="wcContent">';
        wc_flash_show();
        return;
    }

    echo '<div class="cpTop"><b>' . wc_e(wc_setting('site_name', SITE_NAME)) . '</b><span class="cpVer">管理后台 v' . WC_VERSION . '</span>'
        . '<span class="cpRight">管理员：' . wc_e($u['nick'] !== '' ? $u['nick'] : $u['email']) . '（' . wc_e($u['role'] === 'super' ? '超级管理员' : '管理员') . '）'
        . '　<a href="' . wc_e(wc_url()) . '" target="_blank">查看前台</a>　'
        . '<a href="' . wc_e(wc_url('logout.php')) . '">退出</a></span><div class="clr"></div></div>';
    echo '<div class="cpWrap"><table class="cpLay"><tr><td class="cpMenu"><div class="menu"><div class="menuHd">功能导航</div><ul>';
    foreach (cp_menu() as $m) {
        $on = basename($_SERVER['SCRIPT_NAME']) === $m[0] ? ' class="on"' : '';
        echo '<li><a href="' . wc_e(wc_url('admin/' . $m[0])) . '"' . $on . '>' . wc_e($m[2]) . ' ' . $m[3] . '</a></li>';
    }
    echo '<li><span>存储后端：' . wc_e(WcKV::backend() === 'redis' ? 'Redis' : 'MySQL 降级') . '</span></li>';
    echo '</ul></div></td><td class="cpMain">';
    wc_flash_show();
}

function cp_panel($title, $right = '')
{
    echo '<div class="cpPanel box"><div class="cpHd boxHd">' . $title
        . ($right !== '' ? '<span class="cpRt boxRt">' . $right . '</span>' : '') . '</div><div class="cpBd boxBd">';
}

function cp_panel_end()
{
    echo '</div></div>';
}

function cp_foot()
{
    $mdui = wc_ui_skin() === 'mdui';
    if ($mdui) {
        echo '</div></mdui-layout-main></mdui-layout>';
    } else {
        echo '</td></tr></table></div>';
    }
    echo '<div class="' . ($mdui ? 'foot' : 'cpFt') . '">' . wc_e(wc_setting('site_name', SITE_NAME)) . ' 管理后台 · 文谶云盘 v' . WC_VERSION
        . '　页面执行 ' . sprintf('%.3f', microtime(true) - WC_START) . 's　内存 ' . round(memory_get_usage(true) / 1048576, 1) . 'M'
        . '　<a href="' . wc_e(wc_url('admin/index.php')) . '">控制台</a>'
        . '　<a href="javascript:;" id="wcSkinBtn">' . ($mdui ? '经典皮肤' : '深色新版') . '</a></div>';
    echo '<div id="wcDialogHost"></div><div id="wcSnackHost"></div>';
    echo '</body></html>';
}

/** 后台动作统一入口：GET 带 k=CSRF token，或 POST 带 _token */
function cp_action()
{
    if (wc_is_post()) {
        wc_csrf_check();
        return wc_post('do');
    }
    $do = wc_get('do');
    if ($do !== '') {
        if (wc_get('k') !== wc_csrf_token()) { wc_flash('操作校验失败，请刷新页面重试。', 'err'); wc_go('admin/' . basename($_SERVER['SCRIPT_NAME'])); }
        return $do;
    }
    return '';
}

function cp_back($msg, $bad = false)
{
    wc_flash($msg, $bad ? 'err' : 'ok');
    $to = wc_get('back') !== '' ? wc_get('back') : wc_post('back');
    if ($to === '' || !preg_match('#^[A-Za-z0-9_./?&=%-]*$#', $to)) {
        $to = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php');
    }
    wc_go('admin/' . ltrim($to, '/'));
}

function cp_url($file, $params = array())
{
    return wc_url('admin/' . $file . ($params ? '?' . http_build_query($params) : ''));
}

/** 当前管理员昵称 */
function wc_admin_nick()
{
    $a = wc_auth_user();
    return $a ? ($a['nick'] !== '' ? $a['nick'] : $a['email']) : '';
}

function wc_admin_name()
{
    return wc_admin_nick();
}

/** 分页控件（后台） */
function cp_page($total, $page, $size, $base)
{
    $sep = strpos($base, '?') === false ? '?' : '&';
    return wc_paginate($total, $page, $size, $base . $sep . 'p={p}');
}

/** 行内动作链接：自动附带 CSRF token；确认走 ui.js 的对话框 */
function cp_act($do, $label, $params = array(), $confirm = '', $cls = '')
{
    $q = array_merge(array('do' => $do, 'k' => wc_csrf_token()), $params);
    $href = '?' . http_build_query($q);
    $cf = $confirm !== '' ? ' data-confirm="' . wc_e($confirm) . '"' : '';
    return '<a href="' . $href . '"' . $cf . ' class="' . $cls . '">' . $label . '</a>';
}
