<?php
/**
 * 页面外壳：2005 传统门户风（表格布局、蓝灰渐变标题条、宋体 12px）
 * 皮肤固定为经典版；SEO / OG / 统计代码在此统一输出
 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_TEMPLATE')) { return; }
define('WC_CORE_TEMPLATE', 1);

function wc_css($name = 'style')
{
    return wc_url('assets/' . $name . '.css?v=' . WC_VERSION);
}

function wc_page_head($title = '', $opts = array())
{
    $side = isset($opts['side']) ? (bool)$opts['side'] : true;
    $site = wc_setting('site_name', SITE_NAME);
    $pageTitle = wc_seo_title($title);
    $desc = mb_substr(strip_tags(wc_seo_description()), 0, 220);
    $kw = trim((string)wc_setting('seo_keywords', ''));
    $robots = isset($opts['robots']) ? $opts['robots'] : wc_seo_robots(!empty($opts['share']));
    $self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
        . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
        . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
    $canonical = isset($opts['canonical']) ? $opts['canonical'] : rtrim(SITE_URL, '/') . (strpos($self, '?') !== false ? '' : $self);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>' . "\n" . '<html><head><meta charset="utf-8">'
        . '<meta http-equiv="X-UA-Compatible" content="IE=edge"><meta name="renderer" content="webkit">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . wc_e($pageTitle) . '</title>'
        . '<meta name="description" content="' . wc_e($desc) . '">'
        . ($kw !== '' ? '<meta name="keywords" content="' . wc_e($kw) . '">' : '')
        . '<meta name="robots" content="' . wc_e($robots) . '">'
        . '<link rel="canonical" href="' . wc_e($canonical) . '">'
        . '<meta property="og:type" content="' . (empty($opts['og']) ? 'website' : 'article') . '">'
        . '<meta property="og:site_name" content="' . wc_e($site) . '">'
        . '<meta property="og:title" content="' . wc_e(isset($opts['og']['title']) ? $opts['og']['title'] : $pageTitle) . '">'
        . '<meta property="og:description" content="' . wc_e(isset($opts['og']['description']) ? $opts['og']['description'] : $desc) . '">'
        . '<meta property="og:url" content="' . wc_e($canonical) . '">'
        . (isset($opts['og']['image']) ? '<meta property="og:image" content="' . wc_e($opts['og']['image']) . '">' : '')
        . '<meta name="twitter:card" content="summary">'
        . '<link rel="stylesheet" href="' . wc_e(wc_css()) . '">'
        . '<link rel="icon" href="' . wc_e(wc_url('assets/favicon.ico')) . '">'
        . '<script src="' . wc_e(wc_url('assets/js/common.js?v=' . WC_VERSION)) . '"></script>'
        . '<script>window.WC_BASE=' . json_encode(rtrim(SITE_URL, '/') . '/') . ';window.WC_CSRF=' . json_encode(wc_csrf_token()) . ';</script>';
    if (!empty($opts['js'])) {
        foreach ((array)$opts['js'] as $j) { echo '<script src="' . wc_e(wc_url($j . (strpos($j, '?') === false ? '?v=' . WC_VERSION : ''))) . '"></script>'; }
    }
    echo '</head><body>';

    echo '<div class="topLine"></div>';
    echo '<div class="hd">'
        . '<a class="logo" href="' . wc_e(wc_url()) . '"><b>' . wc_e($site) . '</b><span>' . wc_e(wc_setting('site_slogan', '图片 · 视频 · 文档 外链云盘')) . '</span></a>'
        . '<div class="hdR">' . wc_head_user_line() . '</div>'
        . '<div class="clr"></div></div>';
    echo wc_nav_html();
    echo '<div class="wrap">' . ($side ? '<table class="lay"><tr><td class="side">' : '<table class="lay wide"><tr><td class="main">');
    if ($side) {
        echo wc_side_html() . '</td><td class="main">';
    }
}

function wc_head_user_line()
{
    $u = wc_auth_user();
    $out = '';
    if ($u) {
        $unread = wc_notice_unread((int)$u['id']);
        $out = '您好，<a href="' . wc_url('user.php') . '">' . wc_e($u['nick'] !== '' ? $u['nick'] : $u['email']) . '</a>'
            . ' | <a href="' . wc_url('files.php') . '">我的文件</a>'
            . ' | <a href="' . wc_url('notice.php') . '">消息' . ($unread > 0 ? '<b class="red">(' . $unread . ')</b>' : '') . '</a>'
            . (wc_is_admin() ? ' | <a href="' . wc_url('admin/index.php') . '"><b>管理后台</b></a>' : '')
            . ' | <a href="' . wc_url('logout.php') . '">退出</a>';
    } else {
        $out = '<a href="' . wc_url('login.php') . '">登录</a> | <a href="' . wc_url('register.php') . '"><b>免费注册</b></a> | <a href="' . wc_url('help.php') . '">帮助</a>';
    }
    return $out;
}

function wc_nav_items()
{
    return array(
        array('home', '首页', wc_url()),
        array('upload', '上传文件', wc_url('upload.php')),
        array('folder', '我的文件', wc_url('files.php')),
        array('link', '我的分享', wc_url('shares.php')),
        array('bell', '公告中心', wc_url('announce.php')),
        array('user', '用户中心', wc_url('user.php')),
        array('chart', '下载排行', wc_url('rank.php')),
        array('help', '帮助中心', wc_url('help.php')),
    );
}

function wc_nav_html()
{
    $now = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
    $html = '<div class="nav"><div class="navIn">';
    foreach (wc_nav_items() as $it) {
        $file = parse_url($it[2], PHP_URL_PATH);
        $file = $file === null || $file === '/' ? 'index.php' : basename($file);
        $act = ($file === $now) ? ' class="on"' : '';
        $html .= '<a href="' . wc_e($it[2]) . '"' . $act . '>' . wc_e($it[1]) . '</a>';
    }
    $html .= '<form class="navSearch" method="get" action="' . wc_e(wc_url('search.php')) . '">'
        . '<input type="text" name="q" size="14" maxlength="40" value="' . wc_e(wc_get('q')) . '" placeholder="搜索我的文件">'
        . '<input type="submit" value="搜索"></form>';
    $html .= '</div></div>';
    return $html;
}

function wc_side_html()
{
    $u = wc_auth_user();
    $html = '';
    if ($u) {
        $used = (int)$u['used'];
        $quota = (int)$u['quota'];
        $pct = $quota > 0 ? min(100, round($used / $quota * 100, 1)) : 0;
        $html .= '<div class="box"><div class="boxHd">账号信息</div><div class="boxBd">'
            . '<div class="uFace">' . wc_user_face($u, 48) . '</div>'
            . '<div class="uName">' . wc_e($u['nick'] !== '' ? $u['nick'] : $u['email']) . '</div>'
            . '<div class="uMail">' . wc_e($u['email']) . '</div>'
            . '<div class="bar"><i style="width:' . $pct . '%"></i></div>'
            . '<div class="uStat">空间 ' . wc_format_size($used) . ' / ' . wc_format_size($quota) . '（' . $pct . '%）</div>'
            . ($u['verified'] == 0 ? '<div class="warn">邮箱未验证，<a href="' . wc_url('user.php') . '">点此重发验证信</a></div>' : '')
            . '<div class="uMenu"><a href="' . wc_url('upload.php') . '">『上传文件』</a><br><a href="' . wc_url('files.php') . '">文件管理</a> '
            . '<a href="' . wc_url('shares.php') . '">我的分享</a><br><a href="' . wc_url('trash.php') . '">回收站</a> '
            . '<a href="' . wc_url('user.php') . '">账号设置</a></div>'
            . '</div></div>';
    } else {
        $html .= '<div class="box"><div class="boxHd">用户登录</div><div class="boxBd">'
            . '<form method="post" action="' . wc_e(wc_url('login.php')) . '">' . wc_csrf_field()
            . '<input type="hidden" name="do" value="1">'
            . '邮箱<br><input type="text" name="email" size="20" maxlength="60"><br>'
            . '密码<br><input type="password" name="pass" size="20" maxlength="40"><br>'
            . '<input type="submit" value="登录"> <label><input type="checkbox" name="remember" value="1">记住我</label>'
            . '<br><a href="' . wc_url('register.php') . '">免费注册</a> | <a href="' . wc_url('forgot.php') . '">找回密码</a></form>'
            . '</div></div>';
    }
    $ann = wc_db_rows('SELECT id,title,add_time,top FROM wc_announce WHERE status=1 ORDER BY top DESC,add_time DESC LIMIT 6');
    if ($ann) {
        $html .= '<div class="box"><div class="boxHd">最新公告</div><div class="boxBd list"><ul>';
        foreach ($ann as $a) {
            $html .= '<li>' . ($a['top'] ? '<span class="tagTop">顶</span>' : '') . '<a href="' . wc_e(wc_url('announce.php?id=' . (int)$a['id'])) . '" title="' . wc_e($a['title']) . '">' . wc_e(wc_cut_str($a['title'], 18)) . '</a></li>';
        }
        $html .= '</ul></div></div>';
    }
    $html .= '<div class="box"><div class="boxHd">站点统计</div><div class="boxBd stat">' . wc_stat_mini() . '</div></div>';
    return $html;
}

function wc_stat_mini()
{
    static $cached = null;
    if ($cached === null) {
        $cached = array(
            'users' => wc_db_count('wc_user'),
            'files' => (int)wc_db_one('SELECT COUNT(*) FROM wc_file WHERE del_time=0'),
            'bytes' => (int)wc_db_one('SELECT COALESCE(SUM(size),0) FROM wc_file WHERE del_time=0'),
            'share' => (int)wc_db_one('SELECT COUNT(*) FROM wc_share WHERE status=1'),
        );
    }
    $c = $cached;
    $t = wc_db_row('SELECT * FROM wc_stat WHERE d=?', array(date('Y-m-d')));
    return '注册用户：<b>' . number_format($c['users']) . '</b><br>文件总数：<b>' . number_format($c['files']) . '</b><br>'
        . '占用空间：<b>' . wc_format_size($c['bytes']) . '</b><br>有效分享：<b>' . number_format($c['share']) . '</b><br>'
        . '今日上传：<b>' . ($t ? number_format((int)$t['upload_cnt']) : '0') . '</b> 份 / 今日下载：<b>'
        . ($t ? number_format((int)$t['down_cnt']) : '0') . '</b> 次';
}

function wc_user_face($u, $size = 48)
{
    $size = (int)$size;
    // 1) 用户上传的头像优先
    if (!empty($u['avatar'])) {
        if (strpos($u['avatar'], 'http') === 0) {
            return '<img src="' . wc_e($u['avatar']) . '" width="' . $size . '" height="' . $size . '" class="face" alt="头像">';
        }
        return '<img src="' . wc_e(wc_avatar_url((int)$u['id'])) . '" width="' . $size . '" height="' . $size . '" class="face" alt="头像">';
    }
    // 2) Gravatar：镜像 → 官方源 → 昵称首字占位（前端 onerror 逐级降级）
    $seed = mb_substr((string)($u['nick'] !== '' ? $u['nick'] : $u['email']), 0, 1);
    $seed = $seed === '' ? '文' : $seed;
    $fb = '<span class="face faceTxt avFb" style="display:none;width:' . $size . 'px;height:' . $size
        . 'px;line-height:' . $size . 'px;font-size:' . round($size * 0.5) . 'px">' . wc_e($seed) . '</span>';
    if (!wc_setting('gravatar_enable', '1') || empty($u['email'])) {
        return '<span class="face faceTxt" style="width:' . $size . 'px;height:' . $size . 'px;line-height:' . $size . 'px;font-size:' . round($size * 0.5) . 'px">' . wc_e($seed) . '</span>';
    }
    $src = wc_gravatar_url($u['email'], $size);
    $alt = wc_gravatar_backup_url($u['email'], $size);
    return '<span class="avWrap" style="width:' . $size . 'px;height:' . $size . 'px">'
        . '<img src="' . wc_e($src) . '" data-alt="' . wc_e($alt) . '" data-letter="1" width="' . $size . '" height="' . $size
        . '" class="face avImg" alt="头像" onerror="wcAvatarFail(this)">' . $fb . '</span>';
}

function wc_page_foot($extra = '')
{
    $site = wc_setting('site_name', SITE_NAME);
    $icp = wc_setting('site_icp', '');
    echo '</td></tr></table>';
    echo '<div class="foot">'
        . '<div class="footLinks"><a href="' . wc_e(wc_url()) . '">首页</a> | <a href="' . wc_e(wc_url('announce.php')) . '">公告</a> | '
        . '<a href="' . wc_e(wc_url('help.php')) . '">帮助</a> | <a href="' . wc_e(wc_url('terms.php')) . '">服务条款</a> | '
        . '<a href="' . wc_e(wc_url('report.php')) . '">违规举报</a></div>'
        . '<div class="footCopy">' . wc_e($site) . ' &copy; ' . date('Y') . '　'
        . ($icp !== '' ? '<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener">' . wc_e($icp) . '</a>' : '')
        . '<br>Powered by <b>文谶云盘</b> v' . WC_VERSION . '　页面执行 ' . sprintf('%.3f', microtime(true) - WC_START) . 's'
        . ($extra !== '' ? '　' . $extra : '') . '</div>'
        . '</div>';
    echo '<div class="botLine"></div>';
    echo guard_honeypot_link();
    $stats = wc_seo_stats_code();
    if ($stats !== '') { echo "\n<!-- 站点统计代码 -->\n" . $stats . "\n"; }
    echo '</body></html>';
}

function wc_box_start($title, $right = '')
{
    echo '<div class="box"><div class="boxHd">' . $title
        . ($right !== '' ? '<span class="boxRt">' . $right . '</span>' : '') . '</div><div class="boxBd">';
}

function wc_box_end()
{
    echo '</div></div>';
}

function wc_crumbs($items)
{
    $t = array();
    foreach ($items as $it) {
        $t[] = is_array($it) && isset($it[1]) ? '<a href="' . wc_e($it[1]) . '">' . wc_e($it[0]) . '</a>' : wc_e($it);
    }
    echo '<div class="crumb">当前位置：' . implode(' &gt; ', $t) . '</div>';
}

function wc_tip($msg, $class = 'ok')
{
    echo '<div class="tip tip' . ucfirst($class) . '">' . $msg . '</div>';
}

function wc_file_icon($kind, $ext = '')
{
    return '<span class="fIcon fI_' . wc_e($kind) . '" title="' . wc_e(wc_kind_name($kind)) . '">' . wc_e(wc_kind_icon($kind, $ext)) . '</span>';
}

function wc_file_thumb_html($f, $size = 60)
{
    if (!empty($f['thumb']) && wc_thumb_exists($f)) {
        return '<img src="' . wc_e(wc_thumb_url($f)) . '" width="' . $size . '" height="' . $size . '" class="thmb" alt="缩略图" loading="lazy">';
    }
    return wc_file_icon($f['kind'], $f['ext']);
}

/** 表单行生成器，保持后台/前台样式统一 */
function wc_form_row($label, $control, $note = '')
{
    echo '<tr><th>' . $label . '</th><td>' . $control
        . ($note !== '' ? '<div class="note">' . $note . '</div>' : '') . '</td></tr>';
}

function wc_form_submit($value = '保存设置', $token = true)
{
    echo '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="' . wc_e($value) . '"> '
        . '<input type="button" class="btn" value="返回" onclick="history.back()"></td></tr>';
}
