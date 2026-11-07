<?php
/**
 * 全站防爬虫引擎
 * 分层：IP 封禁表 → UA 规则 → JS 通行证挑战 → 频率/深度/节奏 → 蜜罐 → 蜘蛛白名单（反向 DNS 校验）
 * 处置：challenge（人机验证页）/ block（403）/ soft（精简壳页）/ log（仅记录）
 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_GUARD')) { return; }
define('WC_CORE_GUARD', 1);

define('GUARD_PASS_COOKIE', 'WC-GUARD-PASS');

function guard_enabled() { return wc_setting('scan_open', '1') === '1'; }
function guard_level() { return in_array(wc_setting('scan_level', 'mid'), array('low', 'mid', 'high'), true) ? wc_setting('scan_level', 'mid') : 'mid'; }

/** UA 规则表 */
function guard_ua_rules()
{
    $level = guard_level();
    $rules = array(
        'hack' => array('sqlmap', 'nikto', 'nessus', 'acunetix', 'awvs', 'webinspect', 'dirbuster', 'gobuster', 'havij',
            'masscan', 'zgrab', 'nmap', 'hydra', 'openvas', 'nuclei', 'httpframework', 'bfac', 'w3af', 'arachni'),
        'bot' => array('scrapy', 'python-requests', 'python-urllib', 'httpclient', 'java/', 'okhttp', 'libwww-perl',
            'go-http-client', 'wget/', 'curl/', 'node-fetch', 'axios/', 'got/', 'phantomjs', 'headlesschrome',
            'puppeteer', 'selenium', 'playwright', 'webcopier', 'webzip', 'teleport', 'offline explorer',
            'sitesucker', 'grab-site', 'harvest', 'extractor', 'crawler', 'spider', 'fetch/', 'python-httplib2',
            'aiohttp', 'httpx', 'fast-http', 'reactivehttp', 'coccoc', 'megaindex'),
    );
    if ($level === 'low') { return array('hack' => $rules['hack']); }
    return $rules;
}

function guard_known_spiders()
{
    return array(
        'googlebot' => array('.googlebot.com', '.google.com'),
        'bingbot' => array('.search.msn.com'),
        'baidu' => array('.baidu.com'),
        'sogou' => array('.sogou.com'),
        'yandex' => array('.yandex.ru', '.yandex.net'),
        'duckduck' => array('.duckduckbot.com'),
        'slurp' => array('.crawl.yahoo.net'),
    );
}

/** 反向 DNS 校验蜘蛛真伪 */
function guard_spider_verified($ip, $ua)
{
    if (!wc_setting('scan_allow_spider', '1')) { return false; }
    $hit = null;
    foreach (guard_known_spiders() as $name => $sufs) {
        if (stripos($ua, $name) !== false) { $hit = $sufs; break; }
    }
    if ($hit === null) { return false; }
    $host = @gethostbyaddr($ip);
    if (!$host) { return false; }
    $host = strtolower($host);
    $matched = false;
    foreach ($hit as $suf) {
        if (substr($host, -strlen($suf)) === $suf) { $matched = true; break; }
    }
    if (!$matched) { return false; }
    $back = @gethostbyname($host);
    return $back === $ip;
}

function guard_is_banned($ip = null)
{
    $ip = $ip === null ? wc_ip() : $ip;
    $v = WcKV::get('scan:ban:' . $ip, '');
    if ($v === '') { return false; }
    $j = json_decode($v, true);
    if (is_array($j) && isset($j['until']) && $j['until'] < time()) { WcKV::del('scan:ban:' . $ip); return false; }
    return is_array($j) ? $j : true;
}

function guard_ban($ip, $minutes, $reason)
{
    $ip = trim((string)$ip);
    if ($ip === '' || $ip === '0.0.0.0') { return false; }
    $minutes = max(1, (int)$minutes);
    WcKV::set('scan:ban:' . $ip, json_encode(array('until' => time() + $minutes * 60, 'reason' => mb_substr($reason, 0, 100), 'ip' => $ip), JSON_UNESCAPED_UNICODE), $minutes * 60 + 60);
    WcKV::incr('scan:ban:' . date('Ymd'), 86400 * 8);
    guard_log('封禁 ' . $ip . ' ' . $minutes . ' 分钟：' . $reason);
    return true;
}

function guard_unban($ip)
{
    WcKV::del('scan:ban:' . trim((string)$ip));
    return true;
}

function guard_banned_list()
{
    $out = array();
    foreach (WcKV::keys('scan:ban:*') as $k) {
        $v = json_decode((string)WcKV::get($k, ''), true);
        if (!is_array($v)) { continue; }
        if (isset($v['until']) && $v['until'] < time()) { continue; }
        $out[] = array('ip' => $v['ip'] ?? str_replace('scan:ban:', '', $k), 'until' => $v['until'] ?? 0, 'reason' => $v['reason'] ?? '');
    }
    usort($out, function ($a, $b) { return $b['until'] - $a['until']; });
    return $out;
}

function guard_log($msg, $uid = 0)
{
    static $n = 0;
    if ($n++ >= 3) { return; }
    try {
        wc_db_insert('wc_log', array('type' => 'guard', 'uid' => (int)$uid, 'uname' => '', 'action' => mb_substr((string)$msg, 0, 90),
            'detail' => 'UA: ' . mb_substr(wc_ua(200), 0, 200), 'ip' => wc_ip(), 'add_time' => time()));
    } catch (Exception $e) {}
    WcKV::incr('scan:hit:' . date('Ymd'), 86400 * 8);
}

/** 通行证：JS 执行后写 cookie，纯脚本拿不到 */
function guard_pass_ok()
{
    $got = isset($_COOKIE[GUARD_PASS_COOKIE]) ? preg_replace('/[^a-f0-9]/', '', (string)$_COOKIE[GUARD_PASS_COOKIE]) : '';
    if ($got === '') { return false; }
    return WcKV::get('scan:pass:' . wc_ip(), '') === $got;
}

function guard_issue_pass()
{
    $t = hash('sha256', wc_ip() . '|' . microtime(true) . '|' . wc_rand_str(12));
    WcKV::set('scan:pass:' . wc_ip(), substr($t, 0, 32), max(300, wc_setting_int('scan_pass_ttl', 7200)));
    return substr($t, 0, 32);
}

/**
 * 主入口
 * @param string $kind page|file|api
 * @return bool true=放行
 */
function guard_run($kind = 'page')
{
    if (!guard_enabled()) { return true; }
    if (defined('GUARD_DONE')) { return true; }
    define('GUARD_DONE', 1);

    $ip = wc_ip();
    $allow = wc_setting_arr('scan_allow_ip', array());
    foreach ($allow as $a) {
        $a = trim((string)$a);
        if ($a === '') { continue; }
        if ($a === $ip) { return true; }
        if (substr($a, -1) === '*' && strpos($ip, rtrim($a, '*')) === 0) { return true; }
        if (strpos($a, '/') !== false && wc_ip_in_cidr($ip, $a)) { return true; }
    }

    // 1. 蜜罐：任何命中直接封
    $path = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    $hp = trim(wc_setting('scan_honeypot', '/nodelay.php'));
    if ($hp !== '' && strpos($path, $hp) === 0) {
        if (wc_setting('scan_honeypot_ban', '1')) {
            guard_ban($ip, wc_setting_int('scan_ban_minutes', 1440), '点击蜜罐链接（爬虫特征）');
        }
        guard_deny('honeypot');
    }

    // 2. 已封禁
    $ban = guard_is_banned($ip);
    if ($ban !== false) { guard_deny('banned', is_array($ban) ? $ban : array()); }

    // 3. 管理员与已登录用户跳过 UA/挑战，但仍受频率与深度限制
    $uid = wc_auth_uid();
    $isAdmin = wc_is_admin();
    if ($isAdmin) { return guard_volume($kind, $ip, true); }

    // 4. 蜘蛛白名单：声称是蜘蛛但 DNS 校验不过 → 视为伪装采集
    $ua = (string)(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    if ($ua === '') {
        if (guard_level() !== 'low') { guard_deny('empty-ua'); }
    } elseif (guard_spider_verified($ip, $ua)) {
        return guard_volume($kind, $ip, true);
    } else {
        foreach (array_keys(guard_known_spiders()) as $spiderName) {
            if (stripos($ua, $spiderName) !== false) {
                guard_ban($ip, wc_setting_int('scan_ban_minutes', 1440), '伪装搜索引擎蜘蛛（DNS 校验失败）：' . mb_substr($ua, 0, 60));
                guard_deny('fake-spider');
            }
        }
    }

    // 5. UA 规则
    $rules = guard_ua_rules();
    $low = strtolower($ua);
    foreach ($rules as $group => $list) {
        foreach ($list as $needle) {
            if ($needle !== '' && strpos($low, strtolower($needle)) !== false) {
                if ($group === 'hack') {
                    guard_ban($ip, wc_setting_int('scan_ban_minutes', 1440), '高危工具 UA：' . mb_substr($ua, 0, 60));
                    guard_deny('hack-ua');
                }
                guard_log('命中 UA 规则[' . $group . ']：' . mb_substr($ua, 0, 80), $uid);
                if (wc_setting('scan_action', 'challenge') === 'block') { guard_deny('ua-block'); }
                if (wc_setting('scan_action', 'challenge') === 'log') { return guard_volume($kind, $ip, false); }
                if (guard_pass_ok()) { return guard_volume($kind, $ip, false); }
                guard_challenge();
            }
        }
    }

    // 6. high 级：所有请求都要通行证
    if (guard_level() === 'high' && !guard_pass_ok() && $kind === 'page') {
        guard_challenge();
    }

    // 7. 无 Accept 头的裸请求（典型脚本）
    if (!isset($_SERVER['HTTP_ACCEPT']) && guard_level() !== 'low' && $uid <= 0 && !guard_pass_ok()) {
        guard_log('缺少 Accept 头', $uid);
        guard_challenge();
    }

    return guard_volume($kind, $ip, false);
}

/** 频率、深度、节奏 */
function guard_volume($kind, $ip, $trusted)
{
    $now = microtime(true);
    // 节奏：连续过快请求
    $gapMs = wc_setting_int('scan_min_gap', 120);
    if ($gapMs > 0) {
        $last = (float)WcKV::get('scan:gap:' . $ip, '0');
        $fast = (int)WcKV::get('scan:fast:' . $ip, '0');
        if ($last > 0 && ($now - $last) * 1000 < $gapMs) {
            $fast = WcKV::incr('scan:fast:' . $ip, 120);
            if ($fast > wc_setting_int('scan_gap_hits', 12)) {
                guard_log('请求节奏过快（' . $fast . ' 次连续 <' . $gapMs . 'ms）', 0);
                if (!$trusted) {
                    if (wc_setting('scan_auto_ban', '1') === '1') { guard_ban($ip, wc_setting_int('scan_ban_minutes', 30), '请求节奏过快，疑似批量抓取'); }
                    guard_deny('too-fast');
                }
            }
        } elseif ($fast > 0) {
            WcKV::del('scan:fast:' . $ip);
        }
        WcKV::set('scan:gap:' . $ip, sprintf('%.4f', $now), 120);
    }

    // 每分钟请求数
    $rate = wc_setting_int('scan_rate', 120);
    if ($kind === 'file') { $rate = wc_setting_int('scan_file_rate', 240); }
    elseif ($kind === 'api') { $rate = wc_setting_int('scan_api_rate', 300); }
    if ($rate > 0) {
        $n = WcKV::incr('scan:rate:' . $ip . ':' . date('Hi'), 120);
        if ($n > $rate) {
            guard_log('超出每分钟阈值 ' . $rate . '（实际 ' . $n . '）', 0);
            if (wc_setting('scan_auto_ban', '1') === '1') { guard_ban($ip, wc_setting_int('scan_ban_minutes', 30), '访问频率超限'); }
            guard_deny('rate');
        }
    }

    // 页面深度（5 分钟窗口内不同 URL 数）
    if ($kind === 'page' && !$trusted) {
        $depthMax = wc_setting_int('scan_depth', 90);
        if ($depthMax > 0) {
            $key = 'scan:depth:' . $ip;
            $list = json_decode((string)WcKV::get($key, '[]'), true);
            if (!is_array($list)) { $list = array(); }
            $sig = substr(md5($kind . '|' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '')), 0, 10);
            if (!isset($list[$sig])) {
                if (count($list) >= $depthMax) {
                    guard_log('5 分钟内访问 ' . count($list) . ' 个不同页面，判定为抓取', 0);
                    if (wc_setting('scan_auto_ban', '1') === '1') { guard_ban($ip, wc_setting_int('scan_ban_minutes', 60), '页面访问深度异常'); }
                    guard_deny('depth');
                }
                $list[$sig] = 1;
                WcKV::set($key, json_encode($list), 300);
            }
        }
    }
    return true;
}

/** 处置：403 / 精简页 / JS 挑战 */
function guard_deny($reason, $ban = array())
{
    $mode = wc_setting('scan_deny_mode', 'page');
    if (wc_is_ajax() || guard_wants_json()) {
        wc_json_out(array('ok' => 0, 'msg' => '访问被拒绝：请求特征异常', 'code' => 'guard', 'reason' => $reason), 403);
    }
    if ($mode === 'soft' && $reason !== 'banned' && $reason !== 'honeypot') {
        guard_soft_page();
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Guard: ' . $reason);
    $retry = is_array($ban) && isset($ban['until']) ? max(0, (int)($ban['until'] - time())) : wc_setting_int('scan_ban_minutes', 30) * 60;
    echo guard_shell('403 访问被拒绝',
        '<p>你的请求被站点防护系统判定为自动采集，已拒绝访问。</p>'
        . '<p>如需恢复：请关闭采集脚本、在浏览器中正常打开页面完成人机验证；'
        . ($retry > 0 ? '本次限制约 <b>' . guard_hms($retry) . '</b> 后自动解除。' : '') . '</p>'
        . '<p>误拦请联系站长，或在后台「防爬虫」面板加入 IP 白名单。</p>');
    exit;
}

function guard_challenge()
{
    $token = guard_issue_pass();
    $back = (string)(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Guard: challenge');
    $ttl = max(300, wc_setting_int('scan_pass_ttl', 7200));
    echo guard_shell('正在进行安全检查',
        '<p>系统需要确认你不是自动程序。请保持浏览器开启 JavaScript，验证将在几秒内自动完成。</p>'
        . '<div class="gSpin"><span></span></div>'
        . '<p class="gTip">若长时间停留在此页，请点击下方按钮重试。</p>'
        . '<p><a class="gBtn" href="javascript:location.reload()">重新验证</a></p>',
        '<script>(function(){var t=' . json_encode($token) . ',ttl=' . $ttl . ';'
        . 'document.cookie="' . GUARD_PASS_COOKIE . '="+t+"; path=/; max-age="+ttl+"; SameSite=Lax";'
        . 'setTimeout(function(){location.reload();},600);})();</script>');
    exit;
}

/** 精简壳页：不返回真实数据，让爬虫抓不到内容 */
function guard_soft_page()
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Guard: soft');
    echo guard_shell(wc_setting('site_name', SITE_NAME),
        '<p>内容正在加载中，请在支持 JavaScript 的浏览器中访问。</p>'
        . '<div class="gSk"><i></i><i></i><i></i><i></i></div>');
    exit;
}

function guard_wants_json()
{
    $h = isset($_SERVER['HTTP_ACCEPT']) ? (string)$_SERVER['HTTP_ACCEPT'] : '';
    return strpos($h, 'application/json') !== false;
}

function guard_hms($s)
{
    $s = (int)$s;
    if ($s >= 3600) { return floor($s / 3600) . ' 小时 ' . floor(($s % 3600) / 60) . ' 分'; }
    if ($s >= 60) { return floor($s / 60) . ' 分 ' . ($s % 60) . ' 秒'; }
    return $s . ' 秒';
}

/** 防爬页面外壳（不依赖登录态与皮肤） */
function guard_shell($title, $body, $extra = '')
{
    $site = htmlspecialchars(wc_setting('site_name', SITE_NAME), ENT_QUOTES);
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' · ' . $site . '</title>'
        . '<style>:root{color-scheme:dark}body{margin:0;font:14px/1.8 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif;'
        . 'background:#0f1115;color:#e3e6eb;display:flex;min-height:100vh;align-items:center;justify-content:center}'
        . '.gBox{max-width:560px;padding:32px 28px;border-radius:18px;background:rgba(30,34,42,.72);'
        . '-webkit-backdrop-filter:blur(18px) saturate(160%);backdrop-filter:blur(18px) saturate(160%);'
        . 'border:1px solid rgba(255,255,255,.08);box-shadow:0 18px 48px rgba(0,0,0,.45)}'
        . '.gBox h1{margin:0 0 12px;font-size:19px}.gBox p{margin:8px 0;color:#b9c1cc}'
        . '.gBtn{display:inline-block;padding:8px 18px;border-radius:999px;background:#4f8cff;color:#fff;text-decoration:none}'
        . '.gTip{font-size:12px;color:#8b95a3}.gSpin{margin:22px auto;width:42px;height:42px}'
        . '.gSpin span{display:block;width:42px;height:42px;border:3px solid rgba(255,255,255,.16);border-top-color:#4f8cff;border-radius:50%;animation:gR 1s linear infinite}'
        . '@keyframes gR{to{transform:rotate(360deg)}}'
        . '.gSk{margin-top:18px}.gSk i{display:block;height:14px;margin:10px 0;border-radius:8px;'
        . 'background:linear-gradient(90deg,rgba(255,255,255,.05),rgba(255,255,255,.14),rgba(255,255,255,.05));'
        . 'background-size:200% 100%;animation:gS 1.2s ease-in-out infinite}'
        . '@keyframes gS{to{background-position:-200% 0}}'
        . '@media (prefers-reduced-motion:reduce){*{animation:none!important}}'
        . '</style></head><body><div class="gBox"><h1>' . $site . '</h1>' . $body . '</div>' . $extra . '</body></html>';
}

/** 蜜罐链接（人类不可见，爬虫会跟进） */
function guard_honeypot_link()
{
    if (!wc_setting('scan_honeypot_on', '1')) { return ''; }
    $hp = trim(wc_setting('scan_honeypot', '/nodelay.php'));
    return '<nav class="gHp" aria-hidden="true" tabindex="-1"><a href="' . wc_e($hp) . '?k=' . wc_e(sec_rand(6)) . '" rel="nofollow">sitemap-all</a>'
        . '<a href="' . wc_e($hp) . '?k=' . wc_e(sec_rand(6)) . '" rel="nofollow">export-all</a></nav>';
}

/** 命中统计（后台面板用） */
function guard_stats($days = 7)
{
    $out = array();
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Ymd', strtotime("-$i day"));
        $out[] = array('d' => date('m-d', strtotime("-$i day")),
            'hit' => (int)WcKV::get('scan:hit:' . $d, '0'),
            'ban' => (int)WcKV::get('scan:ban:' . $d, '0'));
    }
    return $out;
}
