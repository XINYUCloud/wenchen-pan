<?php
/**
 * 文谶云盘 安装向导
 * 访问 install/index.php 按提示填写即可；已安装后本页面拒绝再次执行。
 */
define('WC_PAN', 1);
define('WC_ROOT', dirname(__DIR__));
define('I_STEP', isset($_GET['step']) ? (int)$_GET['step'] : 1);

$siteNamePost = '文谶云盘';
define('SITE_NAME', $siteNamePost);
require WC_ROOT . '/core/mailer.php';

function i_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function i_http_build()
{
    $s = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $dir = preg_replace('#/install$#', '', $dir);
    return $s . $host . $dir . '/';
}

function i_env()
{
    $rows = array();
    $rows[] = array('PHP 版本', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'warn', '建议 PHP 7.4 / 8.x');
    $rows[] = array('pdo_mysql', extension_loaded('pdo_mysql') ? '已安装' : '未安装', extension_loaded('pdo_mysql') ? 'ok' : 'err', '必需');
    $rows[] = array('mbstring', extension_loaded('mbstring') ? '已安装' : '未安装', extension_loaded('mbstring') ? 'ok' : 'err', '必需');
    $rows[] = array('json', function_exists('json_encode') ? '已安装' : '未安装', function_exists('json_encode') ? 'ok' : 'err', '必需');
    $rows[] = array('fileinfo', extension_loaded('fileinfo') ? '已安装' : '未安装', extension_loaded('fileinfo') ? 'ok' : 'err', '上传类型嗅探必需');
    $rows[] = array('gd', extension_loaded('gd') ? '已安装' : '未安装', extension_loaded('gd') ? 'ok' : 'warn', '图片缩略图需要');
    $rows[] = array('curl', extension_loaded('curl') ? '已安装' : '未安装', extension_loaded('curl') ? 'ok' : 'warn', 'URL 转存需要');
    $rows[] = array('openssl', extension_loaded('openssl') ? '已安装' : '未安装', extension_loaded('openssl') ? 'ok' : 'warn', 'SMTP SSL/TLS 需要');
    $rows[] = array('redis 扩展', extension_loaded('redis') ? '已安装' : '未安装', 'ok', '可选，程序内置 Redis 客户端');
    $rows[] = array('exec 函数', function_exists('exec') ? '可用' : '被禁用', function_exists('exec') ? 'ok' : 'warn', 'ffmpeg 视频截图需要');
    $up = wc_parse_size_str(ini_get('upload_max_filesize'));
    $post = wc_parse_size_str(ini_get('post_max_size'));
    $rows[] = array('upload_max_filesize', ini_get('upload_max_filesize'), $up >= 8 * 1048576 ? 'ok' : 'warn', '传统表单上传上限，建议 ≥ 64M');
    $rows[] = array('post_max_size', ini_get('post_max_size'), $post >= 8 * 1048576 ? 'ok' : 'warn', '建议 ≥ 64M');
    $rows[] = array('memory_limit', ini_get('memory_limit'), 'ok', '');
    $writable = array('config', 'data', 'data/storage', 'data/thumbs', 'data/tmp', 'data/logs');
    foreach ($writable as $d) {
        $abs = WC_ROOT . '/' . $d;
        if (!is_dir($abs)) { @mkdir($abs, 0750, true); }
        $ok = is_dir($abs) && is_writable($abs);
        $rows[] = array('目录可写 ' . $d, $ok ? '可写' : '不可写', $ok ? 'ok' : 'err', '用于存放配置与文件');
    }
    return $rows;
}

function wc_parse_size_str($v)
{
    $v = strtoupper(trim((string)$v));
    if ($v === '') { return 0; }
    if (substr($v, -2) === 'KB') { return (int)((float)substr($v, 0, -2) * 1024); }
    if (substr($v, -2) === 'MB') { return (int)((float)substr($v, 0, -2) * 1048576); }
    if (substr($v, -2) === 'GB') { return (int)((float)substr($v, 0, -2) * 1073741824); }
    if (substr($v, -2) === 'TB') { return (int)((float)substr($v, 0, -2) * 1099511627776); }
    $unit = substr($v, -1);
    $n = (float)$v;
    if ($unit === 'K') { return (int)($n * 1024); }
    if ($unit === 'M') { return (int)($n * 1048576); }
    if ($unit === 'G') { return (int)($n * 1073741824); }
    if ($unit === 'T') { return (int)($n * 1099511627776); }
    return (int)$n;
}

function wc_installed_lock()
{
    return is_file(WC_ROOT . '/config/config.php') && is_file(WC_ROOT . '/data/installed.lock');
}

function i_post($k, $d = '')
{
    return isset($_POST[$k]) && trim((string)$_POST[$k]) !== '' ? trim((string)$_POST[$k]) : $d;
}

function i_form($vals = array(), $err = '')
{
    $v = array_merge(array(
        'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_user' => 'root', 'db_pass' => '', 'db_name' => 'wcpan', 'db_prefix' => 'wc_',
        'redis_on' => '1', 'redis_host' => '127.0.0.1', 'redis_port' => '6379', 'redis_pass' => '', 'redis_db' => '0',
        'site_name' => '文谶云盘', 'site_url' => i_http_build(), 'tz' => 'Asia/Shanghai',
        'quota' => '1 GB', 'admin_mail' => '', 'admin_pass' => '', 'admin_pass2' => '', 'admin_nick' => '站长',
        'cookie' => 'WCSESSION',
    ), (array)$vals);
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>文谶云盘 安装向导</title>
<link rel="stylesheet" href="../assets/install.css"></head><body>
<div class="insBox">
<div class="insHd">文谶云盘 <span>安装向导</span></div>
<div class="insNav"><b>1 环境检测</b> → 2 参数配置 → 3 写入数据</div>
<?php if ($err !== ''): ?><div class="insErr"><?php echo $err; ?></div><?php endif; ?>
<form method="post" action="index.php?step=3">
<table class="insTb">
<tr><th colspan="2">数据库（MySQL 5.6+ / MariaDB 10+）</th></tr>
<tr><td>数据库主机</td><td><input name="db_host" value="<?php echo i_e($v['db_host']); ?>" size="18"> 端口 <input name="db_port" value="<?php echo i_e($v['db_port']); ?>" size="5"></td></tr>
<tr><td>数据库账号</td><td><input name="db_user" value="<?php echo i_e($v['db_user']); ?>" size="18"> 密码 <input type="password" name="db_pass" value="<?php echo i_e($v['db_pass']); ?>" size="18"></td></tr>
<tr><td>数据库名</td><td><input name="db_name" value="<?php echo i_e($v['db_name']); ?>" size="18"> <span class="tips">不存在时可自动创建（需账号有建库权限），否则请预先建库</span></td></tr>
<tr><th colspan="2">Redis（可选，连不上会自动降级为 MySQL 存储）</th></tr>
<tr><td>启用 Redis</td><td><select name="redis_on"><option value="1" <?php echo $v['redis_on'] ? 'selected' : ''; ?>>启用</option><option value="0" <?php echo $v['redis_on'] ? '' : 'selected'; ?>>不启用</option></select>
 主机 <input name="redis_host" value="<?php echo i_e($v['redis_host']); ?>" size="14"> 端口 <input name="redis_port" value="<?php echo i_e($v['redis_port']); ?>" size="5">
 密码 <input type="password" name="redis_pass" value="<?php echo i_e($v['redis_pass']); ?>" size="10"> 库号 <input name="redis_db" value="<?php echo i_e($v['redis_db']); ?>" size="3"></td></tr>
<tr><th colspan="2">站点</th></tr>
<tr><td>站点名称</td><td><input name="site_name" value="<?php echo i_e($v['site_name']); ?>" size="30"></td></tr>
<tr><td>站点地址</td><td><input name="site_url" value="<?php echo i_e($v['site_url']); ?>" size="40"> <span class="tips">必须以 / 结尾</span></td></tr>
<tr><td>时区</td><td><input name="tz" value="<?php echo i_e($v['tz']); ?>" size="20"></td></tr>
<tr><td>会话 Cookie 名</td><td><input name="cookie" value="<?php echo i_e($v['cookie']); ?>" size="20"></td></tr>
<tr><th colspan="2">管理员账号（邮箱直接标记为已验证）</th></tr>
<tr><td>管理员邮箱</td><td><input name="admin_mail" value="<?php echo i_e($v['admin_mail']); ?>" size="30"></td></tr>
<tr><td>初始空间配额</td><td><input name="quota" value="<?php echo i_e($v['quota']); ?>" size="12"> <span class="tips">如 5 GB / 500 MB</span></td></tr>
<tr><td>登录密码</td><td><input type="password" name="admin_pass" value="<?php echo i_e($v['admin_pass']); ?>" size="20"> 确认 <input type="password" name="admin_pass2" value="<?php echo i_e($v['admin_pass2']); ?>" size="20"></td></tr>
<tr><td>昵称</td><td><input name="admin_nick" value="<?php echo i_e($v['admin_nick']); ?>" size="20"></td></tr>
<tr><td>&nbsp;</td><td><input type="submit" class="btnBig" value="开始安装">&nbsp;&nbsp;<a href="index.php">返回环境检测</a></td></tr>
</table>
</form>
<div class="insFt">安装完成后请删除 install 目录或为其设置访问密码；配置文件将写入 config/config.php。</div>
</div></body></html><?php
}

function i_env_page($err = '')
{
    $rows = i_env();
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>安装向导 - 环境检测</title>
<link rel="stylesheet" href="../assets/install.css"></head><body>
<div class="insBox">
<div class="insHd">文谶云盘 <span>安装向导</span></div>
<div class="insNav">1 环境检测 → <b>2 参数配置</b> → 3 写入数据</div>
<?php if ($err !== ''): ?><div class="insErr"><?php echo $err; ?></div><?php endif; ?>
<table class="insTb"><tr><th colspan="3">运行环境检测</th></tr>
<tr class="hd"><td width="34%">检查项</td><td width="26%">当前</td><td>说明</td></tr>
<?php foreach ($rows as $r): ?><tr><td><?php echo i_e($r[0]); ?></td><td class="st_<?php echo $r[2]; ?>"><?php echo i_e($r[1]); ?></td><td class="tips"><?php echo i_e($r[3]); ?></td></tr><?php endforeach; ?>
</table>
<?php
$mustErr = false;
foreach ($rows as $r) { if ($r[2] === 'err') { $mustErr = true; } }
if ($mustErr) {
    echo '<div class="insErr">存在必需项未通过，请先安装对应扩展或修正目录权限后刷新本页。</div>';
} else {
    echo '<div class="insOk">必需项检测通过。</div><p style="text-align:center;padding:12px"><a class="btnBig" style="text-decoration:none;padding:6px 18px" href="index.php?step=2">下一步：填写参数</a></p>';
}
echo '<div class="insFt">如无法自动创建数据表，请把 install/schema.sql 导入数据库后再进行配置。</div>';
echo '</div></body></html>';
}

function i_config_text($p)
{
    $u = parse_url($p['site_url']);
    $lines = array(
        '<?php',
        '/** 文谶云盘 配置文件，由安装向导 ' . date('Y-m-d H:i:s') . ' 生成 */',
        "if (!defined('WC_PAN')) { exit('No direct access'); }",
        '',
        "define('DB_HOST', " . var_export($p['db_host'], true) . ");",
        "define('DB_PORT', " . (int)$p['db_port'] . ");",
        "define('DB_USER', " . var_export($p['db_user'], true) . ");",
        "define('DB_PASS', " . var_export($p['db_pass'], true) . ");",
        "define('DB_NAME', " . var_export($p['db_name'], true) . ");",
        "define('DB_CHARSET', 'utf8mb4');",
        '',
        "define('SITE_NAME', " . var_export($p['site_name'], true) . ");",
        "define('SITE_URL', " . var_export($p['site_url'], true) . ");",
        "define('DATA_DIR', dirname(__DIR__) . '/data');",
        "define('TIMEZONE', " . var_export($p['tz'], true) . ");",
        '',
        "define('REDIS_ENABLE', " . ($p['redis_on'] ? 'true' : 'false') . ");",
        "define('REDIS_HOST', " . var_export($p['redis_host'], true) . ");",
        "define('REDIS_PORT', " . (int)$p['redis_port'] . ");",
        "define('REDIS_PASS', " . var_export($p['redis_pass'], true) . ");",
        "define('REDIS_DB', " . (int)$p['redis_db'] . ");",
        "define('REDIS_TIMEOUT', 1.5);",
        '',
        "define('SESSION_NAME', " . var_export($p['cookie'], true) . ");",
        "define('COOKIE_SECURE', false);",
        "define('DEBUG', false);",
        '',
    );
    return implode("\n", $lines);
}

function i_run_sql($pdo, $file)
{
    $sql = file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    $n = 0;
    foreach ($stmts as $s) {
        if ($s === '') { continue; }
        $pdo->exec($s);
        $n++;
    }
    return $n;
}

function i_done_page($p, $redisMode, $tableCount)
{
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>安装完成</title>
<link rel="stylesheet" href="../assets/install.css"></head><body>
<div class="insBox">
<div class="insHd">文谶云盘 <span>安装完成</span></div>
<div class="insNav">1 环境检测 → 2 参数配置 → <b>3 写入数据</b></div>
<div class="insOk">
数据库 <b><?php echo i_e($p['db_name']); ?></b> 建表成功，共 <?php echo (int)$tableCount; ?> 张数据表。<br>
配置文件已写入 <b>config/config.php</b>，安装锁文件 <b>data/installed.lock</b> 已生成。<br>
KeyValue 后端：<b><?php echo $redisMode === 'redis' ? 'Redis（已连通）' : 'MySQL 降级存储（wc_kv 表）'; ?></b>
</div>
<table class="insTb">
<tr><th colspan="2">下一步</th></tr>
<tr><td>前台首页</td><td><a href="../" target="_blank"><?php echo i_e(rtrim($p['site_url'], '/') . '/'); ?></a></td></tr>
<tr><td>管理后台</td><td><a href="../admin/" target="_blank"><?php echo i_e(rtrim($p['site_url'], '/') . '/admin/'); ?></a></td></tr>
<tr><td>登录账号</td><td><?php echo i_e($p['admin_mail']); ?>（超级管理员，邮箱已验证）</td></tr>
<tr><td>发邮件</td><td>请到 后台 → 邮件配置 填写 SMTP，注册验证邮件才能真正发出；未配置时可临时开启「仅记录模式」。</td></tr>
<tr><td>安全提醒</td><td class="st_err">请立即删除服务器上的 install 目录，或为其加上访问密码；并妥善备份 config/config.php。</td></tr>
</table>
<div class="insFt">宝塔部署提示：网站根目录指向本层，开启伪静态（根目录 .htaccess 已内置）；data 目录务必设为不可直接访问。</div>
</div></body></html><?php
}

function i_install($p)
{
    $err = '';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $p['db_name'])) { $err = '数据库名只允许字母数字下划线'; }
    if (!filter_var($p['admin_mail'], FILTER_VALIDATE_EMAIL)) { $err = '管理员邮箱格式不正确'; }
    if (strlen($p['admin_pass']) < 6) { $err = '管理员密码至少 6 位'; }
    if ($p['admin_pass'] !== $p['admin_pass2']) { $err = '两次输入的密码不一致'; }
    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $p['cookie'])) { $err = '会话 Cookie 名只允许字母数字下划线（3-32 位）'; }
    if (substr($p['site_url'], -1) !== '/') { $p['site_url'] = rtrim($p['site_url'], '/') . '/'; }
    if ($err !== '') { i_form($p, i_e($err)); return; }

    try {
        $pdo = new PDO('mysql:host=' . $p['db_host'] . ';port=' . $p['db_port'] . ';charset=utf8mb4', $p['db_user'], $p['db_pass'],
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    } catch (Exception $e) {
        i_form($p, '数据库连接失败：' . i_e($e->getMessage()));
        return;
    }
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $p['db_name'] . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $pdo->exec('USE `' . $p['db_name'] . '`');

    $n = i_run_sql($pdo, WC_ROOT . '/install/schema.sql');

    // 配置目录权限与防护
    $dirs = array('data', 'data/storage', 'data/thumbs', 'data/tmp', 'data/logs', 'config');
    foreach ($dirs as $d) {
        $abs = WC_ROOT . '/' . $d;
        if (!is_dir($abs)) { @mkdir($abs, 0750, true); }
    }
    $deny = "Options -Indexes\n\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    @file_put_contents(WC_ROOT . '/data/.htaccess', $deny);
    @file_put_contents(WC_ROOT . '/config/.htaccess', $deny);
    @file_put_contents(WC_ROOT . '/data/index.html', '<title>no permission</title>');
    @file_put_contents(WC_ROOT . '/data/storage/index.html', '<title>no permission</title>');

    // 写配置
    if (@file_put_contents(WC_ROOT . '/config/config.php', i_config_text($p)) === false) {
        i_form($p, 'config/config.php 写入失败，请检查 config 目录权限。已建好数据表，修正权限后重新提交即可。');
        return;
    }

    $defaults = require WC_ROOT . '/install/defaults.php';
    $defaults['site_name'] = $p['site_name'];
    $defaults['default_quota'] = $p['quota'];
    $st = $pdo->prepare('REPLACE INTO wc_setting (k,v) VALUES (?,?)');
    foreach ($defaults as $k => $v) { $st->execute(array($k, (string)$v)); }

    $mailTpls = wc_mail_tpl_defaults();
    $mt = $pdo->prepare('REPLACE INTO wc_mail_tpl (k,name,subject,body) VALUES (?,?,?,?)');
    foreach ($mailTpls as $k => $t) { $mt->execute(array($k, $t['名称'], $t['subject'], $t['body'])); }

    $quota = wc_parse_size_str($p['quota']);
    $now = time();
    $ins = $pdo->prepare('INSERT INTO wc_user (email,pass,nick,quota,used,verified,status,role,reg_ip,reg_time,last_time) VALUES (?,?,?,?,?,1,1,?,?,?,?)');
    $ins->execute(array(
        strtolower($p['admin_mail']),
        password_hash($p['admin_pass'], PASSWORD_DEFAULT),
        $p['admin_nick'],
        $quota > 0 ? $quota : 1073741824,
        0,
        'super',
        '127.0.0.1',
        $now,
        $now,
    ));
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO wc_folder (uid,name,parent,add_time) VALUES (?,?,0,?)')->execute(array($uid, '默认目录', $now));
    $pdo->prepare('INSERT INTO wc_announce (title,content,author,top,status,view,add_time,edit_time) VALUES (?,?,?,?,1,0,?,?)')->execute(
        array('欢迎使用' . $p['site_name'], '<p>本站支持图片、视频、音频、文档、压缩包等多格式文件上传与外链分享。</p><p>注册后请先验证邮箱，未验证邮箱不能上传。</p><p>请勿上传违法违规内容，管理员有权随时删除并封禁账号。</p>', $p['admin_nick'], 1, $now, $now));
    $pdo->prepare('INSERT INTO wc_notice (uid,title,content,type,is_read,add_time) VALUES (?,?,?,?,0,?)')->execute(
        array($uid, '安装完成', '文谶云盘已安装成功。请先到「邮件配置」填写 SMTP 并测试发信，再到「上传与类型」确认大小限制与允许类型。', 'system', $now));

    $lock = 'installed ' . date('c') . ' key:' . bin2hex(random_bytes(8));
    @file_put_contents(WC_ROOT . '/data/installed.lock', $lock);

    // 探测 Redis
    $redisMode = 'mysql';
    if ($p['redis_on']) {
        $fp = @stream_socket_client('tcp://' . $p['redis_host'] . ':' . (int)$p['redis_port'], $errno, $errstr, 1.5);
        if ($fp) {
            fwrite($fp, "PING\r\n");
            $r = fgets($fp, 128);
            fclose($fp);
            if (strpos((string)$r, '+PONG') !== false) { $redisMode = 'redis'; }
        }
    }
    i_done_page($p, $redisMode, $n);
    exit;
}

/* ================= 入口逻辑 ================= */
if (wc_installed_lock() && !isset($_GET['re'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<meta charset="utf-8"><div style="font:13px SimSun;padding:40px">'
        . '<b>系统已安装。</b><br>如需重新安装，请删除 config/config.php 与 data/installed.lock 后刷新本页面。<br><br>'
        . '<a href="../">前台首页</a>　<a href="../admin/">管理后台</a>　'
        . '<a href="upgrade.php">数据库升级（新增表/字段）</a></div>';
    exit;
}

if (isset($_GET['re'])) {
    @unlink(WC_ROOT . '/data/installed.lock');
    @unlink(WC_ROOT . '/config/config.php');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && I_STEP === 3) {
    i_install(array(
        'db_host' => i_post('db_host', '127.0.0.1'), 'db_port' => i_post('db_port', '3306'),
        'db_user' => i_post('db_user', 'root'), 'db_pass' => isset($_POST['db_pass']) ? (string)$_POST['db_pass'] : '',
        'db_name' => i_post('db_name', 'wcpan'), 'db_prefix' => i_post('db_prefix', 'wc_'),
        'redis_on' => i_post('redis_on', '1') === '1', 'redis_host' => i_post('redis_host', '127.0.0.1'),
        'redis_port' => i_post('redis_port', '6379'), 'redis_pass' => isset($_POST['redis_pass']) ? (string)$_POST['redis_pass'] : '',
        'redis_db' => i_post('redis_db', '0'),
        'site_name' => i_post('site_name', '文谶云盘'), 'site_url' => i_post('site_url', i_http_build()),
        'tz' => i_post('tz', 'Asia/Shanghai'), 'cookie' => i_post('cookie', 'WCSESSION'),
        'quota' => i_post('quota', '1 GB'), 'admin_mail' => i_post('admin_mail'),
        'admin_pass' => isset($_POST['admin_pass']) ? (string)$_POST['admin_pass'] : '',
        'admin_pass2' => isset($_POST['admin_pass2']) ? (string)$_POST['admin_pass2'] : '',
        'admin_nick' => i_post('admin_nick', '站长'),
    ));
    exit;
}

if (I_STEP >= 2) { i_form(); } else { i_env_page(); }
exit;
