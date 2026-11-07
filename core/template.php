<?php
/** 页面外壳：2005 传统门户风格（表格布局、蓝灰渐变标题条、宋体 12px） */
if (!defined('WC_PAN')) { exit('No direct access'); }

function css($name = 'style')
{
    return url('assets/' . $name . '.css?v=' . WC_VERSION);
}

function page_head($title = '', $opts = array())
{
    $side = isset($opts['side']) ? (bool)$opts['side'] : true;
    $site = setting('site_name', SITE_NAME);
    $pageTitle = seo_title($title);
    $desc = mb_substr(strip_tags(seo_description()), 0, 220);
    $kw = trim((string)setting('seo_keywords', ''));
    $robots = isset($opts['robots']) ? $opts['robots'] : seo_robots(!empty($opts['share']));
    $self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
        . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
        . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
    $canonical = isset($opts['canonical']) ? $opts['canonical'] : rtrim(SITE_URL, '/') . (strpos($self, '?') !== false ? '' : $self);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>' . "\n" . '<html><head><meta charset="utf-8">'
        . '<meta http-equiv="X-UA-Compatible" content="IE=edge"><meta name="renderer" content="webkit">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($pageTitle) . '</title>'
        . '<meta name="description" content="' . e($desc) . '">'
        . ($kw !== '' ? '<meta name="keywords" content="' . e($kw) . '">' : '')
        . '<meta name="robots" content="' . e($robots) . '">'
        . '<link rel="canonical" href="' . e($canonical) . '">'
        . '<meta property="og:type" content="' . (empty($opts['og']) ? 'website' : 'article') . '">'
        . '<meta property="og:site_name" content="' . e($site) . '">'
        . '<meta property="og:title" content="' . e(isset($opts['og']['title']) ? $opts['og']['title'] : $pageTitle) . '">'
        . '<meta property="og:description" content="' . e(isset($opts['og']['description']) ? $opts['og']['description'] : $desc) . '">'
        . '<meta property="og:url" content="' . e($canonical) . '">'
        . (isset($opts['og']['image']) ? '<meta property="og:image" content="' . e($opts['og']['image']) . '">' : '')
        . '<meta name="twitter:card" content="summary">'
        . '<link rel="stylesheet" href="' . e(css()) . '">'
        . '<link rel="icon" href="' . e(url('assets/favicon.ico')) . '">'
        . '<script src="' . e(url('assets/js/common.js?v=' . WC_VERSION)) . '"></script>';
    if (!empty($opts['js'])) {
        foreach ((array)$opts['js'] as $j) { echo '<script src="' . e(url($j . (strpos($j, '?') === false ? '?v=' . WC_VERSION : ''))) . '"></script>'; }
    }
    echo '</head><body>';
    echo '<div class="topLine"></div>';
    echo '<div class="hd">'
        . '<a class="logo" href="' . e(url()) . '"><b>' . e($site) . '</b><span>' . e(setting('site_slogan', '图片 · 视频 · 文档 外链云盘')) . '</span></a>'
        . '<div class="hdR">' . head_user_line() . '</div>'
        . '<div class="clr"></div></div>';
    echo nav_html();
    echo '<div class="wrap">' . ($side ? '<table class="lay"><tr><td class="side">' : '<table class="lay wide"><tr><td class="main">');
    if ($side) {
        echo side_html() . '</td><td class="main">';
    }
}

function head_user_line()
{
    $u = auth_user();
    $out = '';
    if ($u) {
        $unread = notice_unread((int)$u['id']);
        $out = '您好，<a href="' . url('user.php') . '">' . e($u['nick'] !== '' ? $u['nick'] : $u['email']) . '</a>'
            . ' | <a href="' . url('files.php') . '">我的文件</a>'
            . ' | <a href="' . url('notice.php') . '">消息' . ($unread > 0 ? '<b class="red">(' . $unread . ')</b>' : '') . '</a>'
            . (is_admin() ? ' | <a href="' . url('admin/index.php') . '"><b>管理后台</b></a>' : '')
            . ' | <a href="' . url('logout.php') . '">退出</a>';
    } else {
        $out = '<a href="' . url('login.php') . '">登录</a> | <a href="' . url('register.php') . '"><b>免费注册</b></a> | <a href="' . url('help.php') . '">帮助</a>';
    }
    return $out;
}

function nav_html()
{
    $now = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
    $items = array(
        array('首页', url()),
        array('上传文件', url('upload.php')),
        array('我的文件', url('files.php')),
        array('我的分享', url('shares.php')),
        array('公告中心', url('announce.php')),
        array('用户中心', url('user.php')),
        array('下载排行', url('rank.php')),
        array('帮助中心', url('help.php')),
    );
    $html = '<div class="nav"><div class="navIn">';
    foreach ($items as $it) {
        $file = parse_url($it[1], PHP_URL_PATH);
        $file = $file === null || $file === '/' ? 'index.php' : basename($file);
        $act = ($file === $now) ? ' class="on"' : '';
        $html .= '<a href="' . e($it[1]) . '"' . $act . '>' . e($it[0]) . '</a>';
    }
    $html .= '<form class="navSearch" method="get" action="' . e(url('search.php')) . '">'
        . '<input type="text" name="q" size="14" maxlength="40" value="' . e(get('q')) . '" placeholder="搜索我的文件">'
        . '<input type="submit" value="搜索"></form>';
    $html .= '</div></div>';
    return $html;
}

function side_html()
{
    $u = auth_user();
    $html = '';
    if ($u) {
        $used = (int)$u['used'];
        $quota = (int)$u['quota'];
        $pct = $quota > 0 ? min(100, round($used / $quota * 100, 1)) : 0;
        $html .= '<div class="box"><div class="boxHd">账号信息</div><div class="boxBd">'
            . '<div class="uFace">' . user_face($u, 48) . '</div>'
            . '<div class="uName">' . e($u['nick'] !== '' ? $u['nick'] : $u['email']) . '</div>'
            . '<div class="uMail">' . e($u['email']) . '</div>'
            . '<div class="bar"><i style="width:' . $pct . '%"></i></div>'
            . '<div class="uStat">空间 ' . format_size($used) . ' / ' . format_size($quota) . '（' . $pct . '%）</div>'
            . ($u['verified'] == 0 ? '<div class="warn">邮箱未验证，<a href="' . url('user.php') . '">点此重发验证信</a></div>' : '')
            . '<div class="uMenu"><a href="' . url('upload.php') . '">『上传文件』</a><br><a href="' . url('files.php') . '">文件管理</a> '
            . '<a href="' . url('shares.php') . '">我的分享</a><br><a href="' . url('trash.php') . '">回收站</a> '
            . '<a href="' . url('user.php') . '">账号设置</a></div>'
            . '</div></div>';
    } else {
        $html .= '<div class="box"><div class="boxHd">用户登录</div><div class="boxBd">'
            . '<form method="post" action="' . e(url('login.php')) . '">' . csrf_field()
            . '<input type="hidden" name="do" value="1">'
            . '邮箱<br><input type="text" name="email" size="20" maxlength="60"><br>'
            . '密码<br><input type="password" name="pass" size="20" maxlength="40"><br>'
            . '<input type="submit" value="登录"> <label><input type="checkbox" name="remember" value="1">记住我</label>'
            . '<br><a href="' . url('register.php') . '">免费注册</a> | <a href="' . url('forgot.php') . '">找回密码</a></form>'
            . '</div></div>';
    }
    $ann = db_rows('SELECT id,title,add_time,top FROM wc_announce WHERE status=1 ORDER BY top DESC,add_time DESC LIMIT 6');
    if ($ann) {
        $html .= '<div class="box"><div class="boxHd">最新公告</div><div class="boxBd list">'
            . '<ul>';
        foreach ($ann as $a) {
            $html .= '<li>' . ($a['top'] ? '<span class="tagTop">顶</span>' : '') . '<a href="' . e(url('announce.php?id=' . (int)$a['id'])) . '" title="' . e($a['title']) . '">' . e(cut_str($a['title'], 18)) . '</a></li>';
        }
        $html .= '</ul></div></div>';
    }
    $st = stat_mini();
    $html .= '<div class="box"><div class="boxHd">站点统计</div><div class="boxBd stat">' . $st . '</div></div>';
    return $html;
}

function stat_mini()
{
    static $cached = null;
    if ($cached === null) {
        $cached = array(
            'users' => db_count('wc_user'),
            'files' => (int)db_one('SELECT COUNT(*) FROM wc_file WHERE del_time=0'),
            'bytes' => (int)db_one('SELECT COALESCE(SUM(size),0) FROM wc_file WHERE del_time=0'),
            'share' => (int)db_one('SELECT COUNT(*) FROM wc_share WHERE status=1'),
        );
    }
    $c = $cached;
    $t = db_row('SELECT * FROM wc_stat WHERE d=?', array(date('Y-m-d')));
    return '注册用户：<b>' . number_format($c['users']) . '</b><br>文件总数：<b>' . number_format($c['files']) . '</b><br>'
        . '占用空间：<b>' . format_size($c['bytes']) . '</b><br>有效分享：<b>' . number_format($c['share']) . '</b><br>'
        . '今日上传：<b>' . ($t ? number_format((int)$t['upload_cnt']) : '0') . '</b> 份 / 今日下载：<b>'
        . ($t ? number_format((int)$t['down_cnt']) : '0') . '</b> 次';
}

function user_face($u, $size = 48)
{
    $size = (int)$size;
    // 1) 用户上传的头像优先
    if (!empty($u['avatar'])) {
        if (strpos($u['avatar'], 'http') === 0) {
            return '<img src="' . e($u['avatar']) . '" width="' . $size . '" height="' . $size . '" class="face" alt="头像">';
        }
        return '<img src="' . e(avatar_url((int)$u['id'])) . '" width="' . $size . '" height="' . $size . '" class="face" alt="头像">';
    }
    // 2) Gravatar：镜像 → 官方源 → 字母占位，逐级降级
    $seed = mb_substr((string)($u['nick'] !== '' ? $u['nick'] : $u['email']), 0, 1);
    $seed = $seed === '' ? '文' : $seed;
    $fb = '<span class="face faceTxt avFb" style="display:none;width:' . $size . 'px;height:' . $size
        . 'px;line-height:' . $size . 'px;font-size:' . round($size * 0.5) . 'px">' . e($seed) . '</span>';
    if (!setting('gravatar_enable', '1') || empty($u['email'])) {
        return '<span class="face faceTxt" style="width:' . $size . 'px;height:' . $size . 'px;line-height:' . $size . 'px;font-size:' . round($size * 0.5) . 'px">' . e($seed) . '</span>';
    }
    $src = gravatar_url($u['email'], $size);
    $alt = gravatar_backup_url($u['email'], $size);
    return '<span class="avWrap" style="width:' . $size . 'px;height:' . $size . 'px">'
        . '<img src="' . e($src) . '" data-alt="' . e($alt) . '" data-letter="1" width="' . $size . '" height="' . $size
        . '" class="face avImg" alt="头像" onerror="wcAvatarFail(this)">' . $fb . '</span>';
}

function page_foot($extra = '')
{
    $site = setting('site_name', SITE_NAME);
    $icp = setting('site_icp', '');
    echo '</td></tr></table>';
    echo '<div class="foot">'
        . '<div class="footLinks"><a href="' . e(url()) . '">首页</a> | <a href="' . e(url('announce.php')) . '">公告</a> | '
        . '<a href="' . e(url('help.php')) . '">帮助</a> | <a href="' . e(url('terms.php')) . '">服务条款</a> | '
        . '<a href="' . e(url('report.php')) . '">违规举报</a></div>'
        . '<div class="footCopy">' . e($site) . ' &copy; ' . date('Y') . '　'
        . ($icp !== '' ? '<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener">' . e($icp) . '</a>' : '')
        . '<br>Powered by <b>文谶云盘</b> v' . WC_VERSION . '　页面执行 ' . sprintf('%.3f', microtime(true) - WC_START) . 's'
        . ($extra !== '' ? '　' . $extra : '') . '</div>'
        . '</div>';
    echo '<div class="botLine"></div>';
    $stats = seo_stats_code();
    if ($stats !== '') { echo "\n<!-- 站点统计代码 -->\n" . $stats . "\n"; }
    echo '</body></html>';
}

/* ---------- 小部件 ---------- */
function box_start($title, $right = '')
{
    echo '<div class="box"><div class="boxHd">' . $title
        . ($right !== '' ? '<span class="boxRt">' . $right . '</span>' : '') . '</div><div class="boxBd">';
}

function box_end()
{
    echo '</div></div>';
}

function crumbs($items)
{
    $html = '<div class="crumb">当前位置：';
    $t = array();
    foreach ($items as $it) {
        $t[] = is_array($it) && isset($it[1]) ? '<a href="' . e($it[1]) . '">' . e($it[0]) . '</a>' : e($it);
    }
    echo implode(' &gt; ', $t) . '</div>';
}

function tip($msg, $class = 'ok')
{
    echo '<div class="tip tip' . ucfirst($class) . '">' . $msg . '</div>';
}

function file_icon($kind, $ext = '')
{
    return '<span class="fIcon fI_' . e($kind) . '" title="' . e(kind_name($kind)) . '">' . e(kind_icon($kind, $ext)) . '</span>';
}

function file_thumb_html($f, $size = 60)
{
    if (!empty($f['thumb']) && thumb_exists($f)) {
        return '<img src="' . e(thumb_url($f)) . '" width="' . $size . '" height="' . $size . '" class="thmb" alt="缩略图" loading="lazy">';
    }
    return file_icon($f['kind'], $f['ext']);
}

/** 表单行生成器，保持后台/前台样式统一 */
function form_row($label, $control, $note = '')
{
    echo '<tr><th>' . $label . '</th><td>' . $control
        . ($note !== '' ? '<div class="note">' . $note . '</div>' : '') . '</td></tr>';
}

function form_submit($value = '保存设置', $token = true)
{
    echo '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="' . e($value) . '"> '
        . '<input type="button" class="btn" value="返回" onclick="history.back()"></td></tr>';
}
