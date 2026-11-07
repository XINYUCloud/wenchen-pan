<?php
/** 邮件配置：SMTP 参数、连通测试、模板编辑、发信日志 */
require_once __DIR__ . '/common.php';
require_admin();

$act = cp_action();
$cfg = mail_config();

if ($act === 'save') {
    $map = array(
        'mail_method' => post('mail_method'),
        'mail_host' => post('mail_host'),
        'mail_port' => (string)(int)post('mail_port'),
        'mail_secure' => post('mail_secure'),
        'mail_user' => post('mail_user'),
        'mail_from' => post('mail_from'),
        'mail_from_name' => mb_substr(post('mail_from_name'), 0, 60),
        'mail_reply' => post('mail_reply'),
        'mail_timeout' => (string)max(5, (int)post('mail_timeout')),
        'mail_subject_prefix' => mb_substr(post('mail_subject_prefix'), 0, 40),
        'report_mail_to' => post('report_mail_to'),
    );
    if (!in_array($map['mail_method'], array('smtp', 'mail', 'log'), true)) { $map['mail_method'] = 'smtp'; }
    if (!in_array($map['mail_secure'], array('ssl', 'tls', 'none'), true)) { $map['mail_secure'] = 'ssl'; }
    if ($map['mail_port'] <= 0 || $map['mail_port'] > 65535) { $map['mail_port'] = '465'; }
    if (post('mail_pass') !== '') { $map['mail_pass'] = (string)post('mail_pass'); }
    if ($map['report_mail_to'] !== '' && !is_email($map['report_mail_to'])) { cp_back('举报接收邮箱格式不正确', true); }
    settings_set($map);
    mail_last_error('');
    log_write('admin', '修改邮件配置', $map['mail_method'] . ' ' . $map['mail_host'] . ':' . $map['mail_port'] . '/' . $map['mail_secure'], auth_uid(), admin_nick());
    cp_back('邮件配置已保存。建议立即用下方「测试发信」验证。');
}

if ($act === 'test') {
    $to = post('test_mail');
    if (!is_email($to)) { cp_back('请填写有效的测试收件邮箱', true); }
    if (!rate_limit('mailtest:' . ip(), 6, 600)) { cp_back('测试过于频繁，请 10 分钟后再试', true); }
    $t0 = microtime(true);
    $html = mail_layout('发信测试',
        '<p>这是一封来自 <b>' . e(setting('site_name', SITE_NAME)) . '</b> 的发信测试邮件。</p>'
        . '<p>发送时间：' . e(date('Y-m-d H:i:s')) . '<br>方式：' . e($cfg['method']) . '　服务器：' . e($cfg['host'] . ':' . $cfg['port'] . '/' . $cfg['secure']) . '<br>'
        . '发件人：' . e($cfg['from'] ?: $cfg['user']) . '　管理员：' . e(admin_nick()) . '</p>'
        . '<p>如果收到本邮件，说明注册验证、找回密码、举报通知等邮件功能均可正常使用。</p>');
    $r = mail_send($to, setting('site_name', SITE_NAME) . ' 发信测试', $html);
    $cost = round((microtime(true) - $t0) * 1000);
    log_write('admin', '邮件测试', $to . ' → ' . ($r === true ? '成功' : '失败:' . $r), auth_uid(), admin_nick());
    if ($r === true) { cp_back('测试邮件已发往 ' . e($to) . '，耗时 ' . $cost . 'ms。若几分钟后仍未收到，请检查垃圾箱与服务商拦截。'); }
    cp_back('发信失败：' . e((string)$r) . '（耗时 ' . $cost . 'ms）', true);
}

if ($act === 'tpl_save') {
    $k = post('k');
    $t = db_row('SELECT k FROM wc_mail_tpl WHERE k=?', array($k));
    if (!$t) { cp_back('模板不存在', true); }
    db_exec('UPDATE wc_mail_tpl SET subject=?, body=? WHERE k=?', array(mb_substr(post('subject'), 0, 190), (string)post('body'), $k));
    log_write('admin', '修改邮件模板', $k, auth_uid(), admin_nick());
    cp_back('模板「' . e($k) . '」已保存');
}
if ($act === 'tpl_reset') {
    $k = post('k');
    $defs = mail_tpl_defaults();
    if (!isset($defs[$k])) { cp_back('没有该模板的默认内容', true); }
    db_exec('UPDATE wc_mail_tpl SET subject=?, body=? WHERE k=?', array($defs[$k]['subject'], $defs[$k]['body'], $k));
    cp_back('模板「' . e($k) . '」已恢复默认');
}

cp_header('邮件配置', 'mail.php');

cp_panel('发信服务器', '<span class="mini">当前方式：' . e($cfg['method']) . '</span>');
echo '<form method="post" action="' . e(cp_url('mail.php')) . '">' . csrf_field()
    . '<input type="hidden" name="do" value="save">'
    . '<table class="cpTb cpForm"><tr class="hd"><td width="170">参数</td><td>值</td></tr>'
    . sel_row('mail_method', '发送方式', $cfg['method'], array('smtp' => 'SMTP 服务器（推荐）', 'mail' => 'PHP mail() 函数', 'log' => '仅记录不发信（调试用）'),
        '宝塔上一般用 SMTP；选「仅记录」时所有邮件只写入 data/logs/mail.log，不会真的发出。')
    . txt_row('mail_host', 'SMTP 服务器', $cfg['host'], '例如 smtp.qq.com、smtp.163.com、smtp.exmail.qq.com')
    . txt_row('mail_port', '端口', $cfg['port'], 'SSL 通常 465，TLS/STARTTLS 通常 587 或 25')
    . sel_row('mail_secure', '加密方式', $cfg['secure'], array('ssl' => 'SSL（465）', 'tls' => 'STARTTLS（587/25）', 'none' => '不加密'),
        '465 端口必须选 SSL；阿里云/腾讯企业邮 465 与 587 都支持。')
    . txt_row('mail_user', 'SMTP 账号', $cfg['user'], '通常就是发件邮箱地址')
    . '<tr><th>SMTP 密码 / 授权码<br><span class="mono mini">mail_pass</span></th><td><input type="password" name="mail_pass" size="30" value="" placeholder="'
    . ($cfg['pass'] !== '' ? '已保存（留空表示不修改）' : '请输入密码或授权码') . '">'
    . '<span class="note">QQ / 163 / 腾讯企业邮需在邮箱设置里开启 SMTP 并获取<b>授权码</b>，不是登录密码。密码以明文存放于数据库 wc_setting 表，请保护好后台与数据库。</span></td></tr>'
    . txt_row('mail_from', '发件地址', $cfg['from'], '留空则使用 SMTP 账号；多数服务商要求与登录账号一致')
    . txt_row('mail_from_name', '发件人显示名', $cfg['fromname'], '')
    . txt_row('mail_reply', '回复地址', $cfg['reply'], '留空则与发件地址相同')
    . txt_row('mail_timeout', '连接超时(秒)', (string)$cfg['timeout'], '')
    . txt_row('mail_subject_prefix', '主题前缀', $cfg['prefix'], '如 [文谶云盘]，留空则不加')
    . txt_row('report_mail_to', '举报接收邮箱', $cfg['method'] === '' ? '' : setting('report_mail_to', ''), '有人提交违规举报时同时发邮件到此地址，留空则只发站内信')
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存邮件配置">　'
    . '<span class="mini">保存后请立即测试发信。</span></td></tr></table></form>';
cp_panel_end();

cp_panel('测试发信');
$lastErr = mail_last_error();
if ($lastErr !== '') { echo '<div class="tip tipErr">最近一次发信错误：<b>' . e($lastErr) . '</b></div>'; }
echo '<form method="post" action="' . e(cp_url('mail.php')) . '">' . csrf_field()
    . '<input type="hidden" name="do" value="test">'
    . '<table class="cpTb cpForm"><tr><th width="170">收件地址</th><td><input type="text" name="test_mail" size="34" value="' . e(auth_user()['email']) . '" required> '
    . '<input type="submit" class="btn" value="发送测试邮件"></td></tr>'
    . '<tr><th>常见失败原因</th><td class="mini">'
    . '① 未开启 SMTP 服务或用了登录密码而非授权码；② 465 端口选了 TLS/不加密；③ 服务器出站 25/465 端口被机房屏蔽（换成 587 或 878 试试）；'
    . '④ 发件域名 SPF/DKIM 未配置导致进垃圾箱；⑤ 本机 PHP 缺少 openssl 扩展。'
    . '</td></tr></table></form>';
cp_panel_end();

cp_panel('邮件模板', '<span class="mini">可用变量：{site} {name} {mail} {url} {time} {ip} {date}</span>');
$tpls = db_rows('SELECT * FROM wc_mail_tpl ORDER BY k');
echo '<table class="cpTb"><tr class="hd"><td width="110">标识</td><td width="120">用途</td><td>主题</td><td width="150">操作</td></tr>';
foreach ($tpls as $t) {
    echo '<tr class="r' . ((int)crc32($t['k']) % 2) . '"><td class="mono">' . e($t['k']) . '</td><td>' . e($t['name']) . '</td>'
        . '<td>' . e($t['subject']) . '</td>'
        . '<td class="ops"><a href="?tpl=' . e($t['k']) . '">编辑</a> <span class="mini">（在上方测试发信验证连通性）</span></td></tr>';
}
echo '</table>';
cp_panel_end();

$editKey = get('tpl');
if ($editKey !== '') {
    $t = db_row('SELECT * FROM wc_mail_tpl WHERE k=?', array($editKey));
    if ($t) {
        $vars = array('{site}' => '站点名', '{name}' => '用户昵称', '{mail}' => '用户邮箱', '{url}' => '链接地址', '{time}' => '有效小时数', '{ip}' => '操作 IP', '{date}' => '当前时间');
        cp_panel('编辑模板：' . e($t['name']) . '（' . e($t['k']) . '）',
            '<a href="' . e(cp_url('mail.php')) . '">返回</a>');
        echo '<form method="post" action="' . e(cp_url('mail.php')) . '">' . csrf_field()
            . '<input type="hidden" name="do" value="tpl_save"><input type="hidden" name="k" value="' . e($t['k']) . '">'
            . '<table class="cpTb cpForm">'
            . '<tr><th width="170">主题</th><td><input type="text" name="subject" size="70" maxlength="190" value="' . e($t['subject']) . '"></td></tr>'
            . '<tr><th>正文 HTML</th><td><textarea name="body" rows="14" cols="90" class="txtArea">' . e($t['body']) . '</textarea>'
            . '<span class="note">正文会被包在统一邮件外壳中（含站点名页头与页脚），此处只写主体段落。支持 p/br/b/strong/a/span 等基础标签。</span></td></tr>'
            . '<tr><th>可用变量</th><td class="mini">';
        foreach ($vars as $v => $d) { echo '<code>' . e($v) . '</code>（' . e($d) . '）　'; }
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
    if ($size > 8192) { echo '<div class="mini">仅显示最后 8KB（共 ' . e(format_size($size)) . '）</div>'; }
    echo '<pre class="txtView">' . e(implode("\n", array_slice($lines, -60))) . '</pre>';
} else {
    echo '<div class="empty">还没有发信记录。</div>';
}
cp_panel_end();
cp_foot();

function txt_row($k, $label, $val, $note = '')
{
    return '<tr><th>' . e($label) . '<br><span class="mono mini">' . e($k) . '</span></th><td>'
        . '<input type="text" name="' . e($k) . '" size="40" value="' . e($val) . '">'
        . ($note !== '' ? '<span class="note">' . $note . '</span>' : '') . '</td></tr>';
}

function sel_row($k, $label, $val, $opts, $note = '')
{
    $html = '<tr><th>' . e($label) . '<br><span class="mono mini">' . e($k) . '</span></th><td><select name="' . e($k) . '">';
    foreach ($opts as $ov => $ol) { $html .= '<option value="' . e($ov) . '"' . ($val === $ov ? ' selected' : '') . '>' . e($ol) . '</option>'; }
    return $html . '</select>' . ($note !== '' ? '<span class="note">' . $note . '</span>' : '') . '</td></tr>';
}
