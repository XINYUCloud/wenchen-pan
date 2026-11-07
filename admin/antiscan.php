<?php
/** 防爬虫面板：策略配置、命中统计、封禁管理、蜜罐与蜘蛛白名单 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$act = cp_action();

if ($act === 'save') {
    $map = array(
        'scan_open' => wc_post('scan_open') === '1' ? '1' : '0',
        'scan_level' => in_array(wc_post('scan_level'), array('low', 'mid', 'high'), true) ? wc_post('scan_level') : 'mid',
        'scan_action' => in_array(wc_post('scan_action'), array('challenge', 'block', 'soft', 'log'), true) ? wc_post('scan_action') : 'challenge',
        'scan_deny_mode' => in_array(wc_post('scan_deny_mode'), array('page', 'soft'), true) ? wc_post('scan_deny_mode') : 'page',
        'scan_rate' => (string)max(0, wc_postint('scan_rate')),
        'scan_file_rate' => (string)max(0, wc_postint('scan_file_rate')),
        'scan_api_rate' => (string)max(0, wc_postint('scan_api_rate')),
        'scan_depth' => (string)max(0, wc_postint('scan_depth')),
        'scan_min_gap' => (string)max(0, wc_postint('scan_min_gap')),
        'scan_gap_hits' => (string)max(1, wc_postint('scan_gap_hits')),
        'scan_auto_ban' => wc_post('scan_auto_ban') === '1' ? '1' : '0',
        'scan_ban_minutes' => (string)max(1, wc_postint('scan_ban_minutes')),
        'scan_allow_spider' => wc_post('scan_allow_spider') === '1' ? '1' : '0',
        'scan_honeypot_on' => wc_post('scan_honeypot_on') === '1' ? '1' : '0',
        'scan_honeypot' => '/' . ltrim(preg_replace('#[^A-Za-z0-9_.-]#', '', wc_post('scan_honeypot')), '/'),
        'scan_honeypot_ban' => wc_post('scan_honeypot_ban') === '1' ? '1' : '0',
        'scan_pass_ttl' => (string)max(300, wc_postint('scan_pass_ttl')),
    );
    $allow = array();
    foreach (preg_split('/[\s,;]+/u', wc_post('scan_allow_ip')) as $one) {
        $one = trim($one);
        if ($one !== '') { $allow[] = $one; }
    }
    $map['scan_allow_ip'] = json_encode(array_values(array_unique($allow)));
    wc_settings_set($map);
    wc_log_write('admin', '修改防爬策略', $map['scan_level'] . '/' . $map['scan_action'], wc_auth_uid(), wc_admin_nick());
    cp_back('防爬策略已保存。');
}
if ($act === 'unban') {
    guard_unban(wc_get('ip'));
    wc_log_write('admin', '解除防爬封禁', wc_get('ip'), wc_auth_uid(), wc_admin_nick());
    cp_back('已解除 ' . wc_e(wc_get('ip')) . ' 的封禁。');
}
if ($act === 'ban') {
    $ip = trim(wc_post('ip'));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) { cp_back('IP 格式不正确', true); }
    guard_ban($ip, max(1, wc_postint('minutes')), '管理员手动封禁：' . mb_substr(wc_post('reason'), 0, 60));
    cp_back('已封禁 ' . wc_e($ip) . '。');
}
if ($act === 'to_black') {
    $ip = trim(wc_get('ip'));
    $list = wc_setting_arr('ip_black', array());
    if (!in_array($ip, $list, true)) { $list[] = $ip; }
    wc_setting_set('ip_black', json_encode($list));
    guard_unban($ip);
    wc_log_write('admin', '防爬封禁转永久黑名单', $ip, wc_auth_uid(), wc_admin_nick());
    cp_back('已把 ' . wc_e($ip) . ' 加入全站 IP 黑名单。');
}

$stats = guard_stats(7);
$max = 1;
foreach ($stats as $s) { $max = max($max, $s['hit'], $s['ban']); }
$banned = guard_banned_list();
$logs = wc_db_rows("SELECT * FROM wc_log WHERE type='guard' ORDER BY id DESC LIMIT 30");

cp_header('防爬虫', 'antiscan.php');

cp_panel('近 7 天命中 / 封禁', '<span class="mini">当前封禁 ' . count($banned) . ' 个 IP</span>');
echo '<table class="spark"><tr>';
foreach ($stats as $s) {
    echo '<td title="命中 ' . $s['hit'] . ' / 封禁 ' . $s['ban'] . '"><div style="display:inline-block;width:44%">'
        . '<i style="height:' . (round($s['hit'] / $max * 70) + 2) . 'px;display:block;background:linear-gradient(#8ab4ff,#3f6fd0)"></i>'
        . '<i style="height:' . (round($s['ban'] / $max * 70) + 2) . 'px;display:block;background:linear-gradient(#ffb4ab,#c0392b);margin-top:2px"></i>'
        . '</div></td>';
}
echo '</tr><tr class="lb">';
foreach ($stats as $s) { echo '<td>' . wc_e($s['d']) . '</td>'; }
echo '</tr></table><div class="mini">蓝=命中次数，红=封禁次数。</div>';
cp_panel_end();

cp_panel('策略配置');
echo '<form method="post" action="' . wc_e(cp_url('antiscan.php')) . '">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="save"><table class="cpTb cpForm">'
    . '<tr class="hd"><td width="190">参数</td><td>值</td></tr>'
    . wc_sw_row('scan_open', '启用防爬虫', '关闭后所有检测停止（仍保留 IP 黑名单）')
    . wc_sel_row2('scan_level', '严格度', array('low' => '低：仅高危工具与空 UA', 'mid' => '中：常见采集库 + 无 Accept + 节奏', 'high' => '高：所有页面请求需 JS 通行证'))
    . wc_sel_row2('scan_action', '命中处置', array('challenge' => 'JS 人机验证（推荐）', 'block' => '直接 403', 'soft' => '返回精简壳页', 'log' => '仅记录不拦截'))
    . wc_sel_row2('scan_deny_mode', '拒绝页样式', array('page' => '完整 403 说明页', 'soft' => '精简壳页（不给数据）'))
    . wc_num_row('scan_rate', '页面请求/分钟/IP', '超过即处置；0 不限')
    . wc_num_row('scan_file_rate', '文件直链/分钟/IP', '图片视频直链单独计数')
    . wc_num_row('scan_api_rate', '接口请求/分钟/IP', 'api/chunk/export')
    . wc_num_row('scan_depth', '5 分钟不同页面数', '超过判定为批量抓取；0 不限')
    . wc_num_row('scan_min_gap', '最小请求间隔(ms)', '连续低于该间隔视为机器；0 关闭')
    . wc_num_row('scan_gap_hits', '连续过快次数阈值', '达到后处置')
    . wc_sw_row('scan_auto_ban', '自动封禁', '频率/深度/节奏超限时自动封 IP')
    . wc_num_row('scan_ban_minutes', '封禁时长(分钟)', '自动与手动封禁默认时长')
    . wc_sw_row('scan_allow_spider', '放行真实搜索引擎', 'UA 声称蜘蛛时做反向+正向 DNS 校验，伪装者拦截')
    . wc_sw_row('scan_honeypot_on', '输出蜜罐链接', '页面里隐藏链接，爬虫跟进即封')
    . wc_txt_row2('scan_honeypot', '蜜罐路径', '默认 /nodelay.php，需保持该文件存在')
    . wc_sw_row('scan_honeypot_ban', '蜜罐命中即封禁', '关闭则仅记录')
    . wc_num_row('scan_pass_ttl', 'JS 通行证有效期(秒)', '通过验证后免验证时长')
    . '<tr><th>IP 白名单<br><span class="mono mini">scan_allow_ip</span></th><td><input type="text" name="scan_allow_ip" size="60" value="' . wc_e(implode(' ', wc_setting_arr('scan_allow_ip', array()))) . '">'
    . '<span class="note">空格分隔，支持 1.2.3.* 与 CIDR；白名单内不做任何检测（本机调试可加 127.0.0.1）</span></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存策略"></td></tr></table></form>';
cp_panel_end();

cp_panel('当前封禁列表', '<span class="mini">到期自动解除</span>');
if (!$banned) {
    echo '<div class="empty">当前没有被封禁的 IP。</div>';
} else {
    echo '<table class="cpTb"><tr class="hd"><td width="150">IP</td><td width="160">解除时间</td><td>原因</td><td width="200">操作</td></tr>';
    foreach ($banned as $b) {
        echo '<tr><td class="mono">' . wc_e($b['ip']) . '</td><td>' . wc_e(date('m-d H:i', (int)$b['until'])) . '</td>'
            . '<td>' . wc_e($b['reason']) . '</td><td class="ops">'
            . cp_act('unban', '解除', array('ip' => $b['ip'])) . ' '
            . cp_act('to_black', '转永久黑名单', array('ip' => $b['ip']), '加入全站黑名单后不再自动解除？', 'danger')
            . '</td></tr>';
    }
    echo '</table>';
}
echo '<form method="post" action="' . wc_e(cp_url('antiscan.php')) . '" style="margin-top:10px">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="ban">手动封禁：<input type="text" name="ip" size="16" placeholder="IP 地址" required> '
    . '<input type="number" name="minutes" size="6" value="60" min="1"> 分钟 '
    . '<input type="text" name="reason" size="24" placeholder="原因"> <input type="submit" class="btn" value="封禁"></form>';
cp_panel_end();

cp_panel('最近命中日志', '<a href="' . wc_e(cp_url('logs.php', array('type' => 'guard'))) . '">全部日志</a>');
if (!$logs) {
    echo '<div class="empty">暂无命中记录。</div>';
} else {
    echo '<table class="cpTb"><tr class="hd"><td width="130">时间</td><td width="130">IP</td><td>动作</td><td>UA</td></tr>';
    foreach ($logs as $l) {
        echo '<tr class="r' . ((int)$l['id'] % 2) . '"><td>' . wc_e(date('m-d H:i:s', (int)$l['add_time'])) . '</td>'
            . '<td class="mono">' . wc_e($l['ip']) . '</td><td>' . wc_e($l['action']) . '</td>'
            . '<td class="mini">' . wc_e(wc_cut_str($l['detail'], 70)) . '</td></tr>';
    }
    echo '</table>';
}
cp_panel_end();
cp_foot();

function wc_sw_row($k, $label, $note = '')
{
    return '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td>'
        . '<label><input type="checkbox" name="' . wc_e($k) . '" value="1"' . (wc_setting($k, '0') === '1' ? ' checked' : '') . '> 开启</label>'
        . ($note !== '' ? '<span class="note">' . wc_e($note) . '</span>' : '') . '</td></tr>';
}
function wc_num_row($k, $label, $note = '')
{
    return '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td>'
        . '<input type="number" name="' . wc_e($k) . '" size="8" value="' . wc_e(wc_setting($k, '0')) . '">'
        . ($note !== '' ? '<span class="note">' . wc_e($note) . '</span>' : '') . '</td></tr>';
}
function wc_txt_row2($k, $label, $note = '')
{
    return '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td>'
        . '<input type="text" name="' . wc_e($k) . '" size="40" value="' . wc_e(wc_setting($k, '')) . '">'
        . ($note !== '' ? '<span class="note">' . wc_e($note) . '</span>' : '') . '</td></tr>';
}
function wc_sel_row2($k, $label, $opts)
{
    $h = '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td><select name="' . wc_e($k) . '">';
    foreach ($opts as $v => $t) { $h .= '<option value="' . wc_e($v) . '"' . (wc_setting($k, '') === $v ? ' selected' : '') . '>' . wc_e($t) . '</option>'; }
    return $h . '</select></td></tr>';
}
