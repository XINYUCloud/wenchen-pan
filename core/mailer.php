<?php
/** 发信：SMTP（socket + STARTTLS/SSL）为主，支持 PHP mail() 与仅记录模式 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_MAILER')) { return; }
define('WC_CORE_MAILER', 1);

function wc_mail_config()
{
    return array(
        'method' => wc_setting('mail_method', 'smtp'),   // smtp | mail | log
        'host'   => wc_setting('mail_host', ''),
        'port'   => (int)wc_setting('mail_port', '465'),
        'secure' => strtolower(wc_setting('mail_secure', 'ssl')), // ssl | tls | none
        'user'   => wc_setting('mail_user', ''),
        'pass'   => wc_setting('mail_pass', ''),
        'from'   => wc_setting('mail_from', ''),
        'fromname' => wc_setting('mail_from_name', SITE_NAME),
        'reply'  => wc_setting('mail_reply', ''),
        'timeout' => (int)wc_setting('mail_timeout', '15'),
        'prefix' => wc_setting('mail_subject_prefix', ''),
    );
}

function wc_mail_last_error($msg = null)
{
    if ($msg !== null) { WcKV::set('mail:last_error', mb_substr((string)$msg, 0, 500), 86400); }
    return (string)WcKV::get('mail:last_error', '');
}

function wc_mail_b64($s) { return '=?UTF-8?B?' . base64_encode((string)$s) . '?='; }

function wc_mail_body($subject, $html, $to, $cfg)
{
    $bname = wc_mail_b64($cfg['fromname']);
    $from = empty($cfg['from']) ? $cfg['user'] : $cfg['from'];
    $h = array(
        'To: ' . (strpos($to, '@') !== false ? $to : $to),
        'From: ' . $bname . ' <' . $from . '>',
        'Reply-To: <' . (empty($cfg['reply']) ? $from : $cfg['reply']) . '>',
        'Subject: ' . wc_mail_b64(($cfg['prefix'] !== '' ? $cfg['prefix'] : '') . $subject),
        'Date: ' . date('r'),
        'Message-ID: <' . sha1(uniqid('', true)) . '@' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: WenchenPan/' . '1.0',
    );
    $body = chunk_split(base64_encode($html), 76, "\r\n");
    return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

/**
 * SMTP 发送（原生 socket，不依赖第三方库）
 * @return true|string
 */
function wc_smtp_send($to, $subject, $html)
{
    $cfg = wc_mail_config();
    if ($cfg['from'] === '' && $cfg['user'] === '') { return '未配置发件邮箱'; }
    $from = $cfg['from'] !== '' ? $cfg['from'] : $cfg['user'];
    $sock = 'tcp';
    if ($cfg['secure'] === 'ssl') { $sock = 'ssl'; }
    $ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true)));
    $fp = @stream_socket_client($sock . '://' . $cfg['host'] . ':' . $cfg['port'], $errno, $errstr, max(3, $cfg['timeout']), STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return '无法连接邮件服务器 ' . $cfg['host'] . ':' . $cfg['port'] . ' —— ' . $errstr; }
    stream_set_timeout($fp, max(5, $cfg['timeout']));
    $meta = stream_get_meta_data($fp);
    if ($sock === 'ssl' && !$meta['crypto_type']) { fclose($fp); return 'SSL 握手失败，请改用 TLS 或 none'; }

    $read = function () use ($fp) { return wc_smtp_reply($fp); };
    $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $r = $read();
    if (strpos($r, '220') !== 0) { fclose($fp); return '服务器拒绝连接：' . $r; }
    $host = preg_replace('#^https?://#', '', SITE_URL);
    $host = explode('/', $host)[0];
    $r = $cmd('EHLO ' . $host);
    if (strpos($r, '250') !== 0) { $r = $cmd('HELO ' . $host); }
    if ($cfg['secure'] === 'tls') {
        $r = $cmd('STARTTLS');
        if (strpos($r, '220') !== 0) { fclose($fp); return 'STARTTLS 不被支持：' . $r; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp); return 'TLS 握手失败';
        }
        $cmd('EHLO ' . $host);
    }
    if ($cfg['user'] !== '') {
        $r = $cmd('AUTH LOGIN');
        if (strpos($r, '334') !== 0) { fclose($fp); return '服务器不支持 AUTH LOGIN：' . $r; }
        $cmd(base64_encode($cfg['user']));
        $r = $cmd(base64_encode($cfg['pass']));
        if (strpos($r, '235') !== 0) { fclose($fp); return '邮件账号或授权码错误：' . $r; }
    }
    $r = $cmd('MAIL FROM:<' . $from . '>');
    if (strpos($r, '250') !== 0) { fclose($fp); return 'MAIL FROM 被拒绝：' . $r; }
    $r = $cmd('RCPT TO:<' . $to . '>');
    if (strpos($r, '250') !== 0 && strpos($r, '251') !== 0) { fclose($fp); return '收件地址被拒绝：' . $r; }
    $r = $cmd('DATA');
    if (strpos($r, '354') !== 0) { fclose($fp); return 'DATA 不被允许：' . $r; }
    fwrite($fp, wc_mail_body($subject, $html, $to, $cfg) . "\r\n.");
    $r = $read();
    if (strpos($r, '250') !== 0) { fclose($fp); return '发信失败：' . $r; }
    $cmd('QUIT');
    fclose($fp);
    return true;
}

function wc_smtp_reply($fp)
{
    $out = '';
    while (($line = fgets($fp, 512)) !== false) {
        $out .= rtrim($line) . ' ';
        if (strlen($line) < 5 || $line[3] !== '-') { break; }
    }
    return trim($out);
}

/**
 * 统一发信入口
 * @return true|string 成功返回 true，失败返回错误说明
 */
function wc_mail_send($to, $subject, $html)
{
    $to = strtolower(trim((string)$to));
    if (!wc_is_email($to)) { return '收件邮箱无效'; }
    $cfg = wc_mail_config();
    $ok = false;
    if ($cfg['method'] === 'log') {
        $ok = true;
    } elseif ($cfg['method'] === 'mail') {
        $ok = @mail($to, wc_mail_b64($subject), $html, "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: " . $cfg['fromname'] . ' <' . ($cfg['from'] ?: $cfg['user']) . '>');
    } else {
        $r = wc_smtp_send($to, $subject, $html);
        $ok = ($r === true);
        if (!$ok) { wc_mail_last_error($r); }
    }
    wc_mail_dump($to, $subject, $html, $ok);
    if (!$ok && wc_mail_last_error() === '') { wc_mail_last_error('发信失败，请检查后台邮件配置'); }
    return $ok ? true : wc_mail_last_error();
}

/** 所有发信都落一份日志，方便排错；仅记录模式下这就是唯一的输出 */
function wc_mail_dump($to, $subject, $html, $ok)
{
    $dir = DATA_DIR . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $line = sprintf("\n==== [%s] %s => %s\n主题：%s\n%s\n", date('Y-m-d H:i:s'), $ok ? '成功' : '失败', $to, $subject, strip_tags($html));
    @file_put_contents($dir . '/mail.log', $line, FILE_APPEND);
}

function wc_mail_layout($title, $body)
{
    $site = wc_e(SITE_NAME);
    return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:16px;background:#eef2f6;font:12px/1.8 SimSun,serif">'
        . '<div style="max-width:620px;margin:0 auto;border:1px solid #a8bdd4;background:#fff">'
        . '<div style="background:#3a6ea5;color:#fff;padding:8px 14px;font-size:14px;font-weight:bold">' . $site . ' · ' . wc_e($title) . '</div>'
        . '<div style="padding:18px 16px;color:#333">' . $body
        . '<div style="margin-top:22px;padding-top:10px;border-top:1px dashed #ccc;color:#888">'
        . '本邮件由 ' . $site . ' 系统自动发出，请勿直接回复。' . '<br>如非本人操作，请忽略本邮件。 <a href="' . wc_e(SITE_URL) . '">' . $site . '</a></div></div></div></body></html>';
}

function wc_mail_tpl_vars_default($user_nick = '', $user_mail = '')
{
    return array(
        '{site}' => SITE_NAME,
        '{name}' => $user_nick !== '' ? $user_nick : $user_mail,
        '{mail}' => $user_mail,
        '{url}'  => '',
        '{time}' => '24',
        '{ip}'   => wc_ip(),
        '{date}' => date('Y-m-d H:i'),
    );
}

function wc_mail_tpl_body($key)
{
    $t = wc_db_row('SELECT * FROM wc_mail_tpl WHERE k=?', array($key));
    return $t;
}

/** 按模板发信 */
function wc_mail_tpl($to, $name, $key, $vars = array())
{
    $t = wc_mail_tpl_body($key);
    if (!$t) { return wc_mail_send($to, SITE_NAME . ' 通知', wc_mail_layout('通知', '模板缺失：' . wc_e($key))); }
    $map = wc_mail_tpl_vars_default($name, $to);
    foreach ($vars as $k => $v) { $map['{' . trim($k, '{}') . '}'] = $v; }
    $subject = strtr($t['subject'], $map);
    $body = strtr($t['body'], $map);
    $html = wc_mail_layout($subject, $body);
    $r = wc_mail_send($to, $subject, $html);
    return $r === true;
}

function wc_mail_tpl_defaults()
{
    return array(
        'verify' => array('名称' => '邮箱验证', 'subject' => '请验证你的 {site} 邮箱', 'body' => '<p>你好 <b>{name}</b>：</p><p>感谢注册 <b>{site}</b>。请在 <b>{time} 小时</b>内点击下面的按钮完成邮箱验证，未验证的账号无法上传文件。</p><p><a href="{url}" style="background:#3a6ea5;color:#fff;padding:8px 18px;text-decoration:none;border:1px solid #2d5b8a">立即验证邮箱</a></p><p>如果按钮无法点击，请复制下列地址到浏览器：<br><span style="color:#06c">{url}</span></p>'),
        'reset' => array('名称' => '找回密码', 'subject' => '{site} 密码重置', 'body' => '<p>你好 <b>{name}</b>：</p><p>系统收到一次来自 {ip} 的密码重置请求。如为你本人操作，请在 <b>{time} 小时</b>内完成重置：</p><p><a href="{url}" style="background:#3a6ea5;color:#fff;padding:8px 18px;text-decoration:none;border:1px solid #2d5b8a">重设密码</a></p><p>复制地址：<span style="color:#06c">{url}</span></p><p>如非本人操作，请忽略本邮件，并建议修改邮箱密码。</p>'),
        'change_mail' => array('名称' => '换绑邮箱确认', 'subject' => '{site} 换绑邮箱确认', 'body' => '<p>你好 <b>{name}</b>：</p><p>你的账号申请将登录邮箱更换为本邮箱，请在 <b>{time} 小时</b>内确认：</p><p><a href="{url}" style="background:#3a6ea5;color:#fff;padding:8px 18px;text-decoration:none;border:1px solid #2d5b8a">确认更换邮箱</a></p><p>确认地址：<span style="color:#06c">{url}</span></p>'),
        'notice' => array('名称' => '通用通知', 'subject' => '{site} 新通知：{title}', 'body' => '<p>你好 <b>{name}</b>：</p><p>{content}</p><p><a href="{url}">{url}</a></p>'),
        'welcome' => array('名称' => '欢迎信', 'subject' => '欢迎使用 {site}', 'body' => '<p>你好 <b>{name}</b>：</p><p>你的账号 {mail} 已开通，初始空间 {quota}。</p><p>直接开始使用：<a href="{url}">{url}</a></p>'),
    );
}
