<?php
/** 安全中心：响应头策略、会话与 CSRF、签名直链、后台 IP 白名单、密钥轮换、环境自检 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$act = cp_action();

if ($act === 'save') {
    $map = array(
        'sec_csp' => in_array(wc_post('sec_csp'), array('off', 'basic', 'strict'), true) ? wc_post('sec_csp') : 'basic',
        'sec_frame' => in_array(wc_post('sec_frame'), array('SAMEORIGIN', 'DENY'), true) ? wc_post('sec_frame') : 'SAMEORIGIN',
        'sec_referrer' => in_array(wc_post('sec_referrer'), array('no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'no-referrer-when-downgrade'), true) ? wc_post('sec_referrer') : 'strict-origin-when-cross-origin',
        'sec_hsts' => wc_post('sec_hsts') === '1' ? '1' : '0',
        'sec_hsts_maxage' => (string)max(0, wc_postint('sec_hsts_maxage')),
        'sec_hsts_preload' => wc_post('sec_hsts_preload') === '1' ? '1' : '0',
        'sec_hide_phpver' => wc_post('sec_hide_phpver') === '1' ? '1' : '0',
        'sec_xss_protection' => wc_post('sec_xss_protection') === '1' ? '1' : '0',
        'sec_csrf_once' => wc_post('sec_csrf_once') === '1' ? '1' : '0',
        'sec_session_bind' => in_array(wc_post('sec_session_bind'), array('off', 'loose', 'strict'), true) ? wc_post('sec_session_bind') : 'loose',
        'admin_ip_allow_session' => wc_post('admin_ip_allow_session') === '1' ? '1' : '0',
        'share_sign_required' => wc_post('share_sign_required') === '1' ? '1' : '0',
        'upload_max_side' => (string)max(1000, wc_postint('upload_max_side')),
    );
    $allow = array();
    foreach (preg_split('/[\s,;]+/u', wc_post('admin_ip_allow')) as $one) {
        $one = trim($one);
        if ($one !== '') { $allow[] = $one; }
    }
    $map['admin_ip_allow'] = json_encode(array_values(array_unique($allow)));
    wc_settings_set($map);
    wc_log_write('admin', '修改安全策略', 'csp=' . $map['sec_csp'] . ' bind=' . $map['sec_session_bind'], wc_auth_uid(), wc_admin_nick());
    cp_back('安全策略已保存，立即生效。');
}
if ($act === 'rotate') {
    sec_require_reauth('轮换密钥会使旧签名直链与缩略图签名失效，需验证密码');
    $done = sec_rotate_secrets();
    wc_log_write('admin', '轮换安全密钥', implode('、', $done), wc_auth_uid(), wc_admin_nick());
    cp_back('已轮换：' . wc_e(implode('、', $done)) . '。旧签名直链立即失效，页面需刷新。');
}
if ($act === 'probe') {
    $r = sec_probe_data_leak();
    cp_back('data 目录自检：' . wc_e($r[1]), $r[0] === 'danger');
}

cp_header('安全中心', 'security.php');

cp_panel('环境自检', '<a class="btn" href="' . wc_e(cp_url('security.php', array('do' => 'probe', 'k' => wc_csrf_token()))) . '">重新探测 data 目录</a>');
echo '<table class="cpTb"><tr class="hd"><td width="220">检查项</td><td width="180">当前</td><td width="70">结论</td><td>说明</td></tr>';
foreach (sec_env_checks() as $c) {
    $cls = $c[2] === 'ok' ? 'ok' : ($c[2] === 'err' ? 'danger' : 'mini');
    echo '<tr><td>' . wc_e($c[0]) . '</td><td class="mono">' . wc_e(mb_substr((string)$c[1], 0, 40)) . '</td>'
        . '<td class="' . $cls . '">' . wc_e($c[2] === 'ok' ? '通过' : ($c[2] === 'err' ? '危险' : ($c[2] === 'warn' ? '注意' : '提示'))) . '</td>'
        . '<td class="mini">' . wc_e($c[3]) . '</td></tr>';
}
echo '</table>';
cp_panel_end();

cp_panel('响应头与传输安全');
echo '<form method="post" action="' . wc_e(cp_url('security.php')) . '">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="save"><table class="cpTb cpForm">'
    . '<tr class="hd"><td width="200">参数</td><td>值</td></tr>'
    . sec_sel('sec_csp', 'Content-Security-Policy', array('off' => '关闭', 'basic' => '基础（允许内联脚本/样式）', 'strict' => '严格（禁内联脚本 + 强制 HTTPS）'),
        'strict 会禁用页面内联 onclick 等，需确认前端不依赖内联事件；本站交互已尽量外置，但部分旧页面仍用内联 confirm，建议先用 basic')
    . sec_sel('sec_frame', 'X-Frame-Options', array('SAMEORIGIN' => '仅同域可嵌入', 'DENY' => '禁止任何嵌入'), '防点击劫持')
    . sec_sel('sec_referrer', 'Referrer-Policy', array('no-referrer' => '不发送', 'same-origin' => '仅同域', 'strict-origin' => '仅源', 'strict-origin-when-cross-origin' => '跨域仅源（推荐）', 'no-referrer-when-downgrade' => '降级不发送'), '')
    . sec_sw('sec_hsts', '启用 HSTS', '仅 HTTPS 站点生效；开启后浏览器强制走 HTTPS')
    . sec_num('sec_hsts_maxage', 'HSTS max-age(秒)', '默认 31536000 = 1 年')
    . sec_sw('sec_hsts_preload', 'HSTS preload', '加入浏览器预加载列表前请确认全站 HTTPS')
    . sec_sw('sec_hide_phpver', '隐藏 PHP/Server 版本头', '减少指纹信息')
    . sec_sw('sec_xss_protection', 'X-XSS-Protection', '旧浏览器兜底')
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存响应头策略"></td></tr></table></form>';
cp_panel_end();

cp_panel('会话 / CSRF / 直链 / 后台访问');
echo '<form method="post" action="' . wc_e(cp_url('security.php')) . '">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="save"><table class="cpTb cpForm">'
    . sec_sw('sec_csrf_once', 'CSRF 一次性表单 token', '表单 token 用过即废（30 分钟有效）；AJAX 仍用会话级 token 以支持连续上传')
    . sec_sel('sec_session_bind', '会话指纹绑定', array('off' => '关闭', 'loose' => '宽松（UA 家族 + IP 前三段）', 'strict' => '严格（完整 UA + IP）'), '指纹变化强制重新登录；移动网络切换建议 loose')
    . sec_sw('share_sign_required', '直链强制签名', '开启后无时效签名的 /d/ 直链一律 403（分享页复制的签名链接仍可用）')
    . sec_num('upload_max_side', '图片最大边长(px)', '超过拒绝入库，防解码炸弹')
    . '<tr><th>后台 IP 白名单<br><span class="mono mini">admin_ip_allow</span></th><td><input type="text" name="admin_ip_allow" size="60" value="' . wc_e(implode(' ', wc_setting_arr('admin_ip_allow', array()))) . '">'
    . '<span class="note">留空不限制；支持 IP / 1.2.3.* / CIDR</span></td></tr>'
    . sec_sw('admin_ip_allow_session', '白名单外已登录管理员放行', '避免改错白名单把自己锁在门外；关闭则严格按 IP')
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存"></td></tr></table></form>';
cp_panel_end();

cp_panel('密钥轮换', '<span class="mini">需密码重认证</span>');
echo '<p class="mini">轮换后：缩略图/头像签名、签名直链全部失效（旧链接 403），当前会话 CSRF 重置。适用于密钥疑似泄露或定期轮换。</p>'
    . '<form method="post" action="' . wc_e(cp_url('security.php')) . '">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="rotate">'
    . '<input type="password" name="reauth_pass" size="24" placeholder="当前管理员密码" required> '
    . '<input type="submit" class="btn" value="立即轮换密钥" data-confirm="轮换会使所有旧签名链接失效，确定？"></form>';
cp_panel_end();

cp_panel('当前生效的安全响应头');
echo '<table class="cpTb"><tr class="hd"><td width="260">Header</td><td>值</td></tr>';
foreach (array('Content-Security-Policy', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'X-Content-Type-Options', 'Strict-Transport-Security') as $h) {
    $v = '';
    foreach (headers_list() as $hl) {
        if (stripos($hl, $h . ':') === 0) { $v = trim(substr($hl, strlen($h) + 1)); break; }
    }
    echo '<tr><td class="mono">' . wc_e($h) . '</td><td class="mini mono">' . wc_e($v !== '' ? $v : '（未发送）') . '</td></tr>';
}
echo '</table>';
cp_panel_end();
cp_foot();

function sec_sw($k, $label, $note = '')
{
    return '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td>'
        . '<label><input type="checkbox" name="' . wc_e($k) . '" value="1"' . (wc_setting($k, '0') === '1' ? ' checked' : '') . '> 开启</label>'
        . ($note !== '' ? '<span class="note">' . wc_e($note) . '</span>' : '') . '</td></tr>';
}
function sec_num($k, $label, $note = '')
{
    return '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td>'
        . '<input type="number" name="' . wc_e($k) . '" size="10" value="' . wc_e(wc_setting($k, '0')) . '">'
        . ($note !== '' ? '<span class="note">' . wc_e($note) . '</span>' : '') . '</td></tr>';
}
function sec_sel($k, $label, $opts, $note = '')
{
    $h = '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td><select name="' . wc_e($k) . '">';
    foreach ($opts as $v => $t) { $h .= '<option value="' . wc_e($v) . '"' . (wc_setting($k, '') === $v ? ' selected' : '') . '>' . wc_e($t) . '</option>'; }
    $h .= '</select>' . ($note !== '' ? '<span class="note">' . wc_e($note) . '</span>' : '') . '</td></tr>';
    return $h;
}
