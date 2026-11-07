<?php
/** 后台公共件：权限、皮肤、菜单、动作校验 */
require_once dirname(__DIR__) . '/core/bootstrap.php';

/** @return array 菜单定义 */
function cp_menu()
{
    $reportOpen = db_count('wc_report', 'status=0');
    return array(
        array('index.php', '控制台', ''),
        array('users.php', '用户管理', ''),
        array('files.php', '文件管理', ''),
        array('shares.php', '外链管理', ''),
        array('announces.php', '公告管理', ''),
        array('reports.php', '举报处理', $reportOpen > 0 ? '<span class="badge">' . $reportOpen . '</span>' : ''),
        array('logs.php', '日志审计', ''),
        array('settings.php', '站点设置', ''),
        array('mail.php', '邮件配置', ''),
        array('tools.php', '系统工具', ''),
    );
}

function cp_header($title, $active = 'index.php')
{
    $u = auth_user();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' - 管理后台 ' . e(setting('site_name', SITE_NAME)) . '</title>'
        . '<link rel="stylesheet" href="' . e(url('assets/admin.css?v=' . WC_VERSION)) . '">'
        . '<link rel="stylesheet" href="' . e(url('assets/style.css?v=' . WC_VERSION)) . '">'
        . '<script src="' . e(url('assets/js/common.js?v=' . WC_VERSION)) . '"></script></head><body class="cp">';
    echo '<div class="cpTop"><b>' . e(setting('site_name', SITE_NAME)) . '</b><span class="cpVer">管理后台 v' . WC_VERSION . '</span>'
        . '<span class="cpRight">管理员：' . e($u['nick'] !== '' ? $u['nick'] : $u['email']) . '（' . e($u['role'] === 'super' ? '超级管理员' : '管理员') . '）'
        . '　<a href="' . e(url()) . '" target="_blank">查看前台</a>　'
        . '<a href="' . e(url('logout.php')) . '">退出</a></span><div class="clr"></div></div>';
    echo '<div class="cpWrap"><table class="cpLay"><tr><td class="cpMenu"><div class="menu"><div class="menuHd">功能导航</div><ul>';
    foreach (cp_menu() as $m) {
        $on = basename($_SERVER['SCRIPT_NAME']) === $m[0] ? ' class="on"' : '';
        echo '<li><a href="' . e(url('admin/' . $m[0])) . '"' . $on . '>' . e($m[1]) . ' ' . $m[2] . '</a></li>';
    }
    echo '<li><span>存储后端：' . e(KV::backend() === 'redis' ? 'Redis' : 'MySQL 降级') . '</span></li>';
    echo '</ul></div></td><td class="cpMain">';
    flash_show();
}

function cp_panel($title, $right = '')
{
    echo '<div class="cpPanel"><div class="cpHd">' . $title
        . ($right !== '' ? '<span class="cpRt">' . $right . '</span>' : '') . '</div><div class="cpBd">';
}

function cp_panel_end()
{
    echo '</div></div>';
}

function cp_foot()
{
    echo '</td></tr></table></div>';
    echo '<div class="cpFt">' . e(setting('site_name', SITE_NAME)) . ' 管理后台 · 文谶云盘 v' . WC_VERSION
        . '　页面执行 ' . sprintf('%.3f', microtime(true) - WC_START) . 's　内存 ' . round(memory_get_usage(true) / 1048576, 1) . 'M'
        . '　<a href="' . e(url('admin/index.php')) . '">控制台</a></div>';
    echo '</body></html>';
}

/** 后台动作统一入口：GET 带 k=CSRF token，或 POST 带 _token */
function cp_action()
{
    if (is_post()) {
        csrf_check();
        return post('do');
    }
    $do = get('do');
    if ($do !== '') {
        if (get('k') !== csrf_token()) { flash('操作校验失败，请刷新页面重试。', 'err'); go('admin/' . basename($_SERVER['SCRIPT_NAME'])); }
        return $do;
    }
    return '';
}

function cp_back($msg, $bad = false)
{
    flash($msg, $bad ? 'err' : 'ok');
    $to = get('back') !== '' ? get('back') : post('back');
    if ($to === '' || !preg_match('#^[A-Za-z0-9_./?&=%-]*$#', $to)) {
        $to = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php');
    }
    go('admin/' . ltrim($to, '/'));
}

function cp_url($file, $params = array())
{
    return url('admin/' . $file . ($params ? '?' . http_build_query($params) : ''));
}

/** 当前管理员昵称 */
function admin_nick()
{
    $a = auth_user();
    return $a ? ($a['nick'] !== '' ? $a['nick'] : $a['email']) : '';
}

function admin_name()
{
    return admin_nick();
}

/** 分页控件（后台） */
function cp_page($total, $page, $size, $base)
{
    $sep = strpos($base, '?') === false ? '?' : '&';
    return paginate($total, $page, $size, $base . $sep . 'p={p}');
}

/** 行内动作链接：自动附带 CSRF token */
function cp_act($do, $label, $params = array(), $confirm = '', $cls = '')
{
    $q = array_merge(array('do' => $do, 'k' => csrf_token()), $params);
    $href = '?' . http_build_query($q);
    $on = $confirm !== '' ? ' onclick="return confirm(\'' . str_replace("'", "\\'", $confirm) . '\')"' : '';
    return '<a href="' . $href . '"' . $on . ' class="' . $cls . '">' . $label . '</a>';
}
