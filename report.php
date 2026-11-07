<?php
/** 违规举报：访客对某个外链提交举报，进入后台处理队列 */
require_once __DIR__ . '/core/bootstrap.php';

$code = preg_replace('/[^A-Za-z0-9]/', '', get('code') !== '' ? get('code') : post('code'));
$s = $code !== '' ? share_find($code) : null;
$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    if (!rate_limit('report:' . ip(), 5, 3600)) {
        $err = '本机举报次数过多，请 1 小时后再试。';
    } elseif (!captcha_check(post('code_img'))) {
        $err = '图形验证码不正确。';
    } else {
        $reason = post('reason');
        $allowReason = array('淫秽色情', '违法违规', '侵犯版权', '诈骗信息', '恶意软件', '其他');
        if (!in_array($reason, $allowReason, true)) { $reason = '其他'; }
        $detail = mb_substr(preg_replace('/[<>"\']/', '', post('detail')), 0, 1000);
        $contact = mb_substr(preg_replace('/[<>"\']/', '', post('contact')), 0, 100);
        $urlIn = mb_substr(post('url'), 0, 250);
        if ($s === null && $urlIn === '') { $err = '请填写要举报的分享地址。'; }
        else {
            $id = db_insert('wc_report', array(
                'code' => $s ? $s['code'] : substr($urlIn, 0, 12),
                'url' => mb_substr($s ? share_page_url($s['code']) : $urlIn, 0, 250),
                'reason' => $reason, 'detail' => $detail, 'contact' => $contact,
                'ip' => ip(), 'status' => 0, 'add_time' => time(),
            ));
            log_write('report', '收到举报', $reason . ' #' . $id, auth_uid(), auth_user() ? auth_user()['nick'] : '访客');
            $mailTo = setting('report_mail_to', '');
            if ($mailTo !== '' && function_exists('mail_tpl')) {
                mail_tpl($mailTo, '管理员', 'notice', array('{title}' => '收到新的违规举报', '{content}' => '举报编号 #' . $id . '，理由：' . $reason . '，地址：' . ($s ? share_page_url($s['code']) : $urlIn), '{url}' => url('admin/reports.php')));
            }
            foreach (db_col("SELECT id FROM wc_user WHERE role IN ('super','admin')") as $aid) {
                notice_add((int)$aid, '收到违规举报 #' . $id, '举报理由：' . $reason . "\n地址：" . ($s ? share_page_url($s['code']) : $urlIn) . "\n请在后台「举报处理」中查看。", 'report');
            }
            $msg = '举报已提交，编号 #' . $id . '。管理员会在核实的 24 小时内处理，如侵权请同时提供权属证明到 ' . e(setting('report_contact', setting('mail_from', ''))) . '。';
        }
    }
}

page_head('违规举报');
crumbs(array(array('首页', url()), '违规举报'));
box_start('违规举报受理');
if ($msg !== '') { tip($msg, 'ok'); }
if ($err !== '') { tip($err, 'err'); }

if ($s) {
    $f = db_row('SELECT name,size,ext,kind FROM wc_file WHERE id=?', array((int)$s['file_id']));
    echo '<div class="tip tipok">正在举报的链接：<b>' . e(share_page_url($s['code'])) . '</b><br>'
        . '指向文件：' . e($f ? $f['name'] . '（' . format_size($f['size']) . '）' : '未知')
        . '<br>分享者：' . e($s['nick'] !== '' ? $s['nick'] : mb_substr((string)$s['email'], 0, 3) . '***')
        . '　创建时间：' . e(date('Y-m-d H:i', (int)$s['add_time'])) . '</div>';
}

echo '<form method="post" action="' . e(url('report.php')) . '" class="frm">' . csrf_field()
    . '<input type="hidden" name="code" value="' . e($code) . '">'
    . '<table class="frmTb">'
    . ($s ? '' : '<tr><th>被举报地址</th><td><input type="text" name="url" size="52" maxlength="250" placeholder="https://' . e((parse_url(SITE_URL, PHP_URL_HOST) ?: 'site')) . '/s/短码" required>'
        . '<div class="note">填写站内 /s/ 开头的分享地址，或完整链接。</div></td></tr>')
    . '<tr><th>举报理由</th><td><select name="reason">'
    . '<option value="淫秽色情">淫秽色情</option><option value="违法违规">违法违规</option>'
    . '<option value="侵犯版权">侵犯版权</option><option value="诈骗信息">诈骗信息</option>'
    . '<option value="恶意软件">恶意软件 / 病毒</option><option value="其他">其他</option></select></td></tr>'
    . '<tr><th>详细说明</th><td><textarea name="detail" rows="5" cols="56" maxlength="1000" required></textarea>'
        . '<div class="note">请说明权属证明、违法事实等，便于管理员核实；版权投诉请附链接与原作品证明。</div></td></tr>'
    . '<tr><th>联系方式</th><td><input type="text" name="contact" size="30" maxlength="100" placeholder="邮箱或 QQ">'
        . '<div class="note">选填，用于反馈处理结果。</div></td></tr>'
    . '<tr><th>验证码</th><td><input type="text" name="code_img" size="8" maxlength="6" required> '
        . '<img src="' . e(url('captcha.php')) . '" width="120" height="40" class="capImg" alt="验证码" onclick="this.src=\'' . e(url('captcha.php')) . '?t=\'+Date.now()"></td></tr>'
    . '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="提交举报"></td></tr></table></form>';
echo '<div class="boxTips">恶意举报本站有权不予受理；处理结果可在 <a href="' . e(url('terms.php')) . '">服务条款</a> 中查看。</div>';
box_end();
page_foot();
