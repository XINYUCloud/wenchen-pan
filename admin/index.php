<?php
/** 后台控制台：核心指标、近 7 天趋势、环境状态、快捷操作 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$act = cp_action();
if ($act === 'close_on') { wc_settings_set(array('site_close' => '1')); wc_log_write('admin', '开启闭站维护'); cp_back('已开启闭站维护，普通访客将无法访问前台。'); }
if ($act === 'close_off') { wc_settings_set(array('site_close' => '0')); wc_log_write('admin', '关闭闭站维护'); cp_back('站点已恢复正常访问。'); }
if ($act === 'recycle_clean') { $n = wc_cron_clean(); wc_log_write('admin', '手动清理', '回收过期文件与日志 ' . $n . ' 条'); cp_back('清理完成，处理过期文件 ' . $n . ' 个。'); }
if ($act === 'sync_used') {
    $us = wc_db_col('SELECT id FROM wc_user');
    foreach ($us as $i) { wc_user_sync_used((int)$i); }
    cp_back('已按文件表重新统计 ' . count($us) . ' 个账号的占用。');
}

$stat = array(
    'user' => wc_db_count('wc_user'),
    'user_verified' => wc_db_count('wc_user', 'verified=1'),
    'user_banned' => wc_db_count('wc_user', 'status=2'),
    'file' => (int)wc_db_one('SELECT COUNT(*) FROM wc_file WHERE del_time=0'),
    'file_trash' => (int)wc_db_one('SELECT COUNT(*) FROM wc_file WHERE del_time>0'),
    'bytes' => (int)wc_db_one('SELECT COALESCE(SUM(size),0) FROM wc_file'),
    'share' => wc_db_count('wc_share', 'status=1'),
    'share_pub' => wc_db_count('wc_share', 'status=1 AND pub=1'),
    'report' => wc_db_count('wc_report', 'status=0'),
    'today_up' => (int)wc_db_one('SELECT COALESCE(SUM(upload_cnt),0) FROM wc_stat WHERE d=?', array(date('Y-m-d'))),
    'today_down' => (int)wc_db_one('SELECT COALESCE(SUM(down_cnt),0) FROM wc_stat WHERE d=?', array(date('Y-m-d'))),
    'today_user' => (int)wc_db_one('SELECT COALESCE(SUM(new_user),0) FROM wc_stat WHERE d=?', array(date('Y-m-d'))),
);

$days = array();
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $r = wc_db_row('SELECT * FROM wc_stat WHERE d=?', array($d));
    $days[] = array('d' => $d, 'up' => $r ? (int)$r['upload_cnt'] : 0, 'down' => $r ? (int)$r['down_cnt'] : 0);
}
$maxBar = 1;
foreach ($days as $x) { $maxBar = max($maxBar, $x['up'], $x['down']); }

$diskFree = @disk_free_space(DATA_DIR);
$diskTotal = @disk_total_space(DATA_DIR);
$phpUpl = ini_get('upload_max_filesize');

cp_header('控制台', 'index.php');

cp_panel('核心指标', '最后刷新 ' . date('H:i:s'));
echo '<table class="kpi"><tr>'
    . '<td><span class="n">' . number_format($stat['user']) . '</span><span class="t">注册用户（已验证 ' . $stat['user_verified'] . '）</span></td>'
    . '<td><span class="n">' . number_format($stat['file']) . '</span><span class="t">正常文件（回收站 ' . $stat['file_trash'] . '）</span></td>'
    . '<td><span class="n">' . wc_format_size($stat['bytes']) . '</span><span class="t">全部占用空间</span></td>'
    . '<td><span class="n">' . number_format($stat['share']) . '</span><span class="t">有效外链（公开 ' . $stat['share_pub'] . '）</span></td>'
    . '<td><span class="n">' . $stat['today_up'] . ' / ' . $stat['today_down'] . '</span><span class="t">今日上传 / 下载次数</span></td>'
    . '<td><span class="n">' . ($stat['report'] > 0 ? '<span class="danger">' . $stat['report'] . '</span>' : '0') . '</span><span class="t">待处理举报</span></td>'
    . '</tr></table>';
echo '<div class="clr"></div>';

echo '<table class="spark"><tr>';
foreach ($days as $x) {
    $h1 = round($x['up'] / $maxBar * 70) + 2;
    $h2 = round($x['down'] / $maxBar * 70) + 2;
    echo '<td title="上传 ' . $x['up'] . ' / 下载 ' . $x['down'] . '">'
        . '<div style="display:inline-block;width:44%">'
        . '<i style="height:' . $h1 . 'px;display:block;background:linear-gradient(#8fc0ea,#2b6bab)"></i>'
        . '<i style="height:' . $h2 . 'px;display:block;background:linear-gradient(#f0c98c,#d99b28);margin-top:2px"></i>'
        . '</div></td>';
}
echo '</tr><tr class="lb">';
foreach ($days as $x) { echo '<td>' . wc_e(substr($x['d'], 5)) . '</td>'; }
echo '</tr></table>';
echo '<div class="mini" style="margin-top:4px">蓝=上传次数，黄=下载次数，近 7 天。</div>';
cp_panel_end();

cp_panel('运行环境');
$redisMode = WcKV::backend();
echo '<table class="cpTb"><tr class="hd"><td width="180">检查项</td><td width="200">当前值</td><td>说明 / 建议</td></tr>'
    . wc_env_row('PHP 版本', PHP_VERSION, version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'bad', '建议 7.4 或 8.x')
    . wc_env_row('MySQL 版本', (string)wc_db_one('SELECT VERSION()'), 'ok', '')
    . wc_env_row('存储后端', $redisMode === 'redis' ? 'Redis 已连接' : 'MySQL 降级（wc_kv）', $redisMode === 'redis' ? 'ok' : '', $redisMode === 'redis' ? '' : 'Redis 未连通，会话与限流走数据库，功能不受影响')
    . wc_env_row('GD 图像库', function_exists('imagecreatetruecolor') ? '已启用' : '未启用', function_exists('imagecreatetruecolor') ? 'ok' : 'bad', '未启用时无法生成图片缩略图与验证码')
    . wc_env_row('curl', extension_loaded('curl') ? '已启用' : '未启用', extension_loaded('curl') ? 'ok' : '', 'URL 转存依赖')
    . wc_env_row('exec', function_exists('exec') ? '可用' : '已禁用', '', 'ffmpeg 视频截图依赖')
    . wc_env_row('上传限制', $phpUpl . ' / ' . ini_get('post_max_size'), '', '站点单文件上限 ' . wc_e(wc_setting('max_size', '200 MB')) . '，实际取两者较小值')
    . wc_env_row('数据目录', DATA_DIR, 'ok', '磁盘剩余 ' . ($diskFree !== false ? wc_format_size($diskFree) : '未知') . '／总 ' . ($diskTotal !== false ? wc_format_size($diskTotal) : '未知'))
    . wc_env_row('time 时区', date_default_timezone_get() . ' / ' . date('Y-m-d H:i:s'), '', '')
    . wc_env_row('头像服务', wc_setting('gravatar_enable', '1') ? wc_gravatar_backend_name() : '已关闭', '', '未上传自定义头像时按邮箱 MD5 取 Gravatar，镜像不可达自动降级官方源')
    . '</table>';
cp_panel_end();

cp_panel('快捷操作');
echo cp_act('recycle_clean', '① 清理过期回收站与日志', array(), '将删除超过保留期的文件并清理旧日志？') . '　';
echo cp_act('sync_used', '② 重新统计所有账号占用') . '　';
echo wc_setting('site_close', '0') === '1'
    ? cp_act('close_off', '③ 关闭闭站维护（恢复访问）', array(), '', 'danger')
    : cp_act('close_on', '③ 开启闭站维护', array('msg' => ''), '开启后除管理员与外链下载外，前台全部停止访问？') . '　';
echo '<a href="' . wc_e(cp_url('tools.php')) . '">④ 系统工具（备份 / 缩略图 / IP 黑名单）</a>　';
echo '<a href="' . wc_e(cp_url('mail.php')) . '">⑤ 邮件配置与测试发信</a>';
cp_panel_end();

cp_panel('最新注册用户', '<a href="' . wc_e(cp_url('users.php')) . '">全部用户</a>');
$rows = wc_db_rows('SELECT * FROM wc_user ORDER BY id DESC LIMIT 8');
echo wc_user_table($rows);
cp_panel_end();

cp_panel('最新操作日志', '<a href="' . wc_e(cp_url('logs.php')) . '">全部日志</a>');
$logs = wc_db_rows('SELECT * FROM wc_log ORDER BY id DESC LIMIT 10');
if (!$logs) { echo '<div class="empty">暂无日志。</div>'; }
else {
    echo '<table class="cpTb"><tr class="hd"><td width="130">时间</td><td width="70">类型</td><td width="120">账号</td><td>动作</td><td width="110">IP</td></tr>';
    foreach ($logs as $l) {
        echo '<tr class="r' . ((int)$l['id'] % 2) . '"><td>' . wc_e(date('m-d H:i:s', (int)$l['add_time'])) . '</td><td>' . wc_e($l['type']) . '</td>'
            . '<td>' . wc_e($l['uname']) . '</td><td>' . wc_e($l['action']) . ' ' . wc_e(wc_cut_str($l['detail'], 50)) . '</td><td>' . wc_e($l['ip']) . '</td></tr>';
    }
    echo '</table>';
}
cp_panel_end();
cp_foot();

function wc_env_row($k, $v, $cls = '', $note = '')
{
    $span = $cls === 'ok' ? '<span class="ok">' : ($cls === 'bad' ? '<span class="danger">' : '<span>');
    return '<tr><td>' . wc_e($k) . '</td><td>' . $span . wc_e($v) . '</span></td><td class="mini">' . wc_e($note) . '</td></tr>';
}

function wc_user_table($rows)
{
    if (!$rows) { return '<div class="empty">暂无数据。</div>'; }
    $html = '<table class="cpTb"><tr class="hd"><td width="50">ID</td><td width="200">邮箱</td><td width="110">昵称</td>'
        . '<td width="90" align="right">已用</td><td width="90" align="right">配额</td><td width="60">验证</td>'
        . '<td width="70">状态</td><td width="70">角色</td><td width="110">注册</td><td width="110">最后活跃</td></tr>';
    foreach ($rows as $x) {
        $st = array('0' => '待验证', '1' => '正常', '2' => '<span class="danger">封禁</span>');
        $html .= '<tr class="r' . ((int)$x['id'] % 2) . '"><td>' . (int)$x['id'] . '</td><td>' . wc_e($x['email']) . '</td>'
            . '<td>' . wc_e(wc_cut_str($x['nick'], 12)) . '</td><td align="right">' . wc_e(wc_format_size($x['used'])) . '</td>'
            . '<td align="right">' . wc_e(wc_format_size($x['quota'])) . '</td>'
            . '<td>' . ((int)$x['verified'] ? '是' : '否') . '</td><td>' . (isset($st[(string)$x['status']]) ? $st[(string)$x['status']] : $x['status']) . '</td>'
            . '<td>' . wc_e($x['role']) . '</td><td>' . wc_e(date('y-m-d', (int)$x['reg_time'])) . '</td>'
            . '<td>' . wc_e($x['last_time'] ? date('y-m-d H:i', (int)$x['last_time']) : '-') . '</td></tr>';
    }
    return $html . '</table>';
}
