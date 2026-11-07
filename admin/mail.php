<?php
/** 邮件配置：SMTP 参数、连通测试、模板编辑、发信日志 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$act = cp_action();
$cfg = wc_mail_config();

if ($act === 'save') {
    $map = array(
        'mail_method' => wc_post('mail_method'),
        'mail_host' => wc_post('mail_host'),
        'mail_port' => (string)(int)wc_post('mail_port'),
        'mail_secure' => wc_post('mail_secure'),
        'mail_user' => wc_post('mail_user'),
        'mail_from' => wc_post('mail_from'),
        'mail_from_name' => mb_substr(wc_post('mail_from_name'), 0, 60),
        'mail_reply' => wc_post('mail_reply'),
        'mail_timeout' => (string)max(5, (int)wc_post('mail_timeout')),
        'mail_subject_prefix' => mb_substr(wc_post('mail_subject_prefix'), 0, 40),
        'report_mail_to' => wc_post('report_mail_to'),
    );
    if (!in_array($map['mail_method'], array('smtp', 'mail', 'log'), true)) { $map['mail_method'] = 'smtp'; }
    if (!in_array($map['mail_secure'], array('ssl', 'tls', 'none'), true)) { $map['mail_secure'] = 'ssl'; }
    if ($map['mail_port'] <= 0 || $map['mail_port'] > 65535) { $map['mail_port'] = '465'; }
    if (wc_post('mail_pass') !== '') { $map['mail_pass'] = (string)wc_post('mail_pass'); }
    if ($map['report_mail_to'] !== '' && !wc_is_email($map['report_mail_to'])) { cp_back('举报接收邮箱格式不正确', true); }
    wc_settings_set($map);
    wc_mail_last_error('');
    wc_log_write('admin', '修改邮件配置', $map['mail_method'] . ' ' . $map['mail_host'] . ':' . $map['mail_port'] . '/' . $map['mail_secure'], wc_auth_uid(), wc_admin_nick());
    cp_back('邮件配置已保存。建议立即用下方「测试发信」验证。');
}

if ($act === 'test') {
    $to = wc_post('test_mail');
    if (!wc_is_email($to)) { cp_back('请填写有效的测试收件邮箱', true); }
    if (!wc_rate_limit('mailtest:' . wc_ip(), 6, 600)) { cp_back('测试过于频繁，请 10 分钟后再试', true); }
    $t0 = microtime(true);
    $html = wc_mail_layout('发信测试',
        '<p>这是一封来自 <b>' . wc_e(wc_setting('site_name', SITE_NAME)) . '</b> 的发信测试邮件。</p>'
        . '<p>发送时间：' . wc_e(date('Y-m-d H:i:s')) . '<br>方式：' . wc_e($cfg['method']) . '　服务器：' . wc_e($cfg['host'] . ':' . $cfg['port'] . '/' . $cfg['secure']) . '<br>'
        . '发件人：' . wc_e($cfg['from'] ?: $cfg['user']) . '　管理员：' . wc_e(wc_admin_nick()) . '</p>'
        . '<p>如果收到本邮件，说明注册验证、找回密码、举报通知等邮件功能均可正常使用。</p>');
    $r = wc_mail_send($to, wc_setting('site_name', SITE_NAME) . ' 发信测试', $html);
    $cost = round((microtime(true) - $t0) * 1000);
    wc_log_write('admin', '邮件测试', $to . ' → ' . ($r === true ? '成功' : '失败:' . $r), wc_auth_uid(), wc_admin_nick());
    if ($r === true) { cp_back('测试邮件已发往 ' . wc_e($to) . '，耗时 ' . $cost . 'ms。若几分钟后仍未收到，请检查垃圾箱与服务商拦截。'); }
    cp_back('发信失败：' . wc_e((string)$r) . '（耗时 ' . $cost . 'ms）', true);
}

if ($act === 'tpl_save') {
    $k = wc_post('k');
    $t = wc_db_row('SELECT k FROM wc_mail_tpl WHERE k=?', array($k));
    if (!$t) { cp_back('模板不存在', true); }
    wc_db_exec('UPDATE wc_mail_tpl SET subject=?, body=? WHERE k=?', array(mb_substr(wc_post('subject'), 0, 190), (string)wc_post('body'), $k));
    wc_log_write('admin', '修改邮件模板', $k, wc_auth_uid(), wc_admin_nick());
    cp_back('模板「' . wc_e($k) . '」已保存');
}
if ($act === 'tpl_reset') {
    $k = wc_post('k');
    $defs = wc_mail_tpl_defaults();
    if (!isset($defs[$k])) { cp_back('没有该模板的默认内容', true); }
    wc_db_exec('UPDATE wc_mail_tpl SET subject=?, body=? WHERE k=?', array($defs[$k]['subject'], $defs[$k]['body'], $k));
    cp_back('模板「' . wc_e($k) . '」已恢复默认');
}

cp_header('邮件配置', 'mail.php');

cp_panel('发信服务器', '<span class="mini">当前方式：' . wc_e($cfg['method']) . '</span>');
echo '<form method="post" action="' . wc_e(cp_url('mail.php')) . '">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="save">'
    . '<table class="cpTb cpForm"><tr class="hd"><td width="170">参数</td><td>值</td></tr>'
    . wc_sel_row('mail_method', '发送方式', $cfg['method'], array('smtp' => 'SMTP 服务器（推荐）', 'mail' => 'PHP mail() 函数', 'log' => '仅记录不发信（调试用）'),
        '宝塔上一般用 SMTP；选「仅记录」时所有邮件只写入 data/logs/mail.log，不会真的发出。')
    . wc_txt_row('mail_host', 'SMTP 服务器', $cfg['host'], '例如 smtp.qq.com、smtp.163.com、smtp.exmail.qq.com')
    . wc_txt_row('mail_port', '端口', $cfg['port'], 'SSL 通常 465，TLS/STARTTLS 通常 587 或 25')
    . wc_sel_row('mail_secure', '加密方式', $cfg['secure'], array('ssl' => 'SSL（465）', 'tls' => 'STARTTLS（587/25）', 'none' => '不加密'),
        '465 端口必须选 SSL；阿里云/腾讯企业邮 465 与 587 都支持。')
    . wc_txt_row('mail_user', 'SMTP 账号', $cfg['user'], '通常就是发件邮箱地址')
    . '<tr><th>SMTP 密码 / 授权码<br><span class="mono mini">mail_pass</span></th><td><input type="password" name="mail_pass" size="30" value="" placeholder="'
    . ($cfg['pass'] !== '' ? '已保存（留空表示不修改）' : '请输入密码或授权码') . '">'
    . '<span class="note">QQ / 163 / 腾讯企业邮需在邮箱设置里开启 SMTP 并获取<b>授权码</b>，不是登录密码。密码以明文存放于数据库 wc_setting 表，请保护好后台与数据库。</span></td></tr>'
    . wc_txt_row('mail_from', '发件地址', $cfg['from'], '留空则使用 SMTP 账号；多数服务商要求与登录账号一致')
    . wc_txt_row('mail_from_name', '发件人显示名', $cfg['fromname'], '')
    . wc_txt_row('mail_reply', '回复地址', $cfg['reply'], '留空则与发件地址相同')
    . wc_txt_row('mail_timeout', '连接超时(秒)', (string)$cfg['timeout'], '')
    . wc_txt_row('mail_subject_prefix', '主题前缀', $cfg['prefix'], '如 [文谶云盘]，留空则不加')
    . wc_txt_row('report_mail_to', '举报接收邮箱', $cfg['method'] === '' ? '' : wc_setting('report_mail_to', ''), '有人提交违规举报时同时发邮件到此地址，留空则只发站内信')
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存邮件配置">　'
    . '<span class="mini">保存后请立即测试发信。</span></td></tr></table></form>';
cp_panel_end();

cp_panel('测试发信');
$lastErr = wc_mail_last_error();
if ($lastErr !== '') { echo '<div class="tip tipErr">最近一次发信错误：<b>' . wc_e($lastErr) . '</b></div>'; }
echo '<form method="post" action="' . wc_e(cp_url('mail.php')) . '">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="test">'
    . '<table class="cpTb cpForm"><tr><th width="170">收件地址</th><td><input type="text" name="test_mail" size="34" value="' . wc_e(wc_auth_user()['email']) . '" required> '
    . '<input type="submit" class="btn" value="发送测试邮件"></td></tr>'
    . '<tr><th>常见失败原因</th><td class="mini">'
    . '① 未开启 SMTP 服务或用了登录密码而非授权码；② 465 端口选了 TLS/不加密；③ 服务器出站 25/465 端口被机房屏蔽（换成 587 或 878 试试）；'
    . '④ 发件域名 SPF/DKIM 未配置导致进垃圾箱；⑤ 本机 PHP 缺少 openssl 扩展。'
    . '</td></tr></table></form>';
cp_panel_end();

cp_panel('邮件模板', '<span class="mini">可用变量：{site} {name} {mail} {url} {time} {ip} {date}</span>');
$tpls = wc_db_rows('SELECT * FROM wc_mail_tpl ORDER BY k');
echo '<table class="cpTb"><tr class="hd"><td width="110">标识</td><td width="120">用途</td><td>主题</td><td width="150">操作</td></tr>';
foreach ($tpls as $t) {
    echo '<tr class="r' . ((int)crc32($t['k']) % 2) . '"><td class="mono">' . wc_e($t['k']) . '</td><td>' . wc_e($t['name']) . '</td>'
        . '<td>' . wc_e($t['subject']) . '</td>'
        . '<td class="ops"><a href="?tpl=' . wc_e($t['k']) . '">编辑</a> <span class="mini">（在上方测试发信验证连通性）</span></td></tr>';
}
echo '</table>';
cp_panel_end();

$editKey = wc_get('tpl');
if ($editKey !== '') {
    $t = wc_db_row('SELECT * FROM wc_mail_tpl WHERE k=?', array($editKey));
    if ($t) {
        $vars = array('{site}' => '站点名', '{name}' => '用户昵称', '{mail}' => '用户邮箱', '{url}' => '链接地址', '{time}' => '有效小时数', '{ip}' => '操作 IP', '{date}' => '当前时间');
        cp_panel('编辑模板：' . wc_e($t['name']) . '（' . wc_e($t['k']) . '）',
            '<a href="' . wc_e(cp_url('mail.php')) . '">返回</a>');
        echo '<form method="post" action="' . wc_e(cp_url('mail.php')) . '">' . wc_csrf_field()
            . '<input type="hidden" name="do" value="tpl_save"><input type="hidden" name="k" value="' . wc_e($t['k']) . '">'
            . '<table class="cpTb cpForm">'
            . '<tr><th width="170">主题</th><td><input type="text" name="subject" size="70" maxlength="190" value="' . wc_e($t['subject']) . '"></td></tr>'
            . '<tr><th>正文 HTML</th><td><textarea name="body" rows="14" cols="90" class="txtArea">' . wc_e($t['body']) . '</textarea>'
            . '<span class="note">正文会被包在统一邮件外壳中（含站点名页头与页脚），此处只写主体段落。支持 p/br/b/strong/a/span 等基础标签。</span></td></tr>'
            . '<tr><th>可用变量</th><td class="mini">';
        foreach ($vars as $v => $d) { echo '<code>' . wc_e($v) . '</code>（' . wc_e($d) . '）　'; }
        echo '</td></tr>'
            . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存模板">　'
            . '<input type="submit" class="btn" value="恢复默认" formaction="?do=tpl_reset" onclick="return confirm(\'用内置默认内容覆盖当前模板？\')">'
            . '</td></tr></table></form>';
        cp_panel_end();
    }
}

cp_panel('最近发信记录', '<span class="mini">文件 data/logs/mail.log</span>');
$logFile = DATA_DIR . '/logs/mail.log';
if (is_file($logFile)) {
    $size = filesize($logFile);
    $fp = fopen($logFile, 'rb');
    fseek($fp, max(0, $size - 8192));
    $tail = fread($fp, 8192);
    fclose($fp);
    $lines = explode("\n", $tail);
    if ($size > 8192) { echo '<div class="mini">仅显示最后 8KB（共 ' . wc_e(wc_format_size($size)) . '）</div>'; }
    echo '<pre class="txtView">' . wc_e(implode("\n", array_slice($lines, -60))) . '</pre>';
} else {
    echo '<div class="empty">还没有发信记录。</div>';
}
cp_panel_end();
cp_foot();

function wc_txt_row($k, $label, $val, $note = '')
{
    return '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td>'
        . '<input type="text" name="' . wc_e($k) . '" size="40" value="' . wc_e($val) . '">'
        . ($note !== '' ? '<span class="note">' . $note . '</span>' : '') . '</td></tr>';
}

function wc_sel_row($k, $label, $val, $opts, $note = '')
{
    $html = '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td><select name="' . wc_e($k) . '">';
    foreach ($opts as $ov => $ol) { $html .= '<option value="' . wc_e($ov) . '"' . ($val === $ov ? ' selected' : '') . '>' . wc_e($ol) . '</option>'; }
    return $html . '</select>' . ($note !== '' ? '<span class="note">' . $note . '</span>' : '') . '</td></tr>';
}
