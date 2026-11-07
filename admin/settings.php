<?php
/** 站点设置：分标签管理全部站内参数（数据库/Redis 连接在 config/config.php） */
require_once __DIR__ . '/common.php';
wc_require_admin();

$tab = wc_get('tab', 'site');
if (!in_array($tab, array('site', 'upload', 'share', 'security', 'seo', 'avatar', 'ui', 'text'), true)) { $tab = 'site'; }

/** 字段定义：key => [标题, 类型, 说明, 默认值, (select 选项数组)] 类型 text/int/size/switch/area/select/ext/list/kindlist */
$fields = wc_settings_fields();

if (wc_is_post() && wc_post('do') === 'save') {
    wc_csrf_check();
    $group = wc_post('tab');
    if (!isset($fields[$group])) { cp_back('未知的设置分组', true); }
    $save = array();
    $errs = array();
    foreach ($fields[$group] as $k => $def) {
        list($label, $type) = $def;
        if ($k === 'seo_stats_code' && wc_auth_role() !== 'super') { continue; }
        if ($type === 'switch') {
            $save[$k] = wc_post($k) === '1' ? '1' : '0';
            continue;
        }
        $v = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
        if ($type === 'int') { $v = (string)max(0, (int)$v); }
        elseif ($type === 'size') {
            $bytes = wc_parse_size($v === '' ? '0' : $v);
            if ($bytes < 0) { $errs[] = $label . ' 数值不正确'; continue; }
            $v = $v === '' ? '0' : $v;
        } elseif ($type === 'list') {
            $arr = array();
            foreach (preg_split('/[,\s、;；]+/u', $v) as $one) {
                $one = trim($one);
                if ($one !== '') { $arr[] = $one; }
            }
            $v = json_encode(array_values(array_unique($arr)), JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'ext') {
            $arr = array();
            foreach (preg_split('/[,\s、;；]+/u', strtolower($v)) as $one) {
                $one = trim($one, " .");
                if ($one !== '' && preg_match('/^[a-z0-9]{1,10}$/', $one)) { $arr[] = $one; }
            }
            $v = json_encode(array_values(array_unique($arr)));
        } elseif ($type === 'kindlist') {
            $v = json_encode(array_values(array_intersect(array_keys(wc_kind_map()), (array)(isset($_POST[$k]) ? $_POST[$k] : array()))));
        } elseif ($type === 'select') {
            $opts = (isset($def[4]) && is_array($def[4])) ? $def[4] : array();
            $v = in_array($v, array_keys($opts), true) ? $v : (string)$default;
        } elseif ($type === 'area') {
            $v = mb_substr($v, 0, 60000);
            if (in_array($k, array('terms_text', 'help_text'), true)) { $v = wc_ann_clean($v); }
        } else {
            $v = mb_substr($v, 0, 500);
        }
        $save[$k] = $v;
    }
    if ($errs) { cp_back(implode('；', $errs), true); }
    if (isset($save['site_url_note'])) { unset($save['site_url_note']); }
    wc_settings_set($save);
    wc_log_write('admin', '修改站点设置', '分组 ' . $group . ' 共 ' . count($save) . ' 项', wc_auth_uid(), wc_admin_nick());
    cp_back('设置已保存（共 ' . count($save) . ' 项），缓存已刷新。');
}

cp_header('站点设置', 'settings.php');

$tabs = array('site' => '站点基本', 'upload' => '上传与类型', 'share' => '外链与下载', 'security' => '注册与安全', 'seo' => 'SEO 优化', 'avatar' => '头像服务', 'ui' => '界面与皮肤', 'text' => '文案与条款');
echo '<div class="cpFilter">';
foreach ($tabs as $k => $v) {
    echo '<a href="' . wc_e(cp_url('settings.php', array('tab' => $k))) . '"' . ($tab === $k ? ' class="on"' : '') . ' style="margin-right:8px">' . $v . '</a> ';
}
echo '<span class="mini">　当前站点地址：' . wc_e(SITE_URL) . '　数据库：' . wc_e(DB_NAME . '@' . DB_HOST . ':' . DB_PORT) . '　存储后端：' . wc_e(WcKV::backend() === 'redis' ? 'Redis' : 'MySQL 降级') . '</span>';
echo '</div>';

if ($tab === 'upload') {
    cp_panel('PHP 相关限制提示');
    echo '<div class="mini">upload_max_filesize = ' . wc_e(ini_get('upload_max_filesize')) . '　post_max_size = ' . wc_e(ini_get('post_max_size'))
        . '　max_execution_time = ' . wc_e(ini_get('max_execution_time')) . 's　memory_limit = ' . wc_e(ini_get('memory_limit')) . '<br>'
        . '实际单文件上限取「站点设置」与 PHP 的较小值；宝塔请在 网站设置 → 上传限制 / PHP 配置 中调大 PHP 参数。当前计算结果：<b>' . wc_e(wc_upload_limit_hint()) . '</b></div>';
    cp_panel_end();
}
if ($tab === 'share') {
    $diskFree = @disk_free_space(DATA_DIR);
    cp_panel('存储状态');
    echo '<div class="mini">数据目录 ' . wc_e(DATA_DIR) . '　磁盘剩余 ' . wc_e($diskFree !== false ? wc_format_size($diskFree) : '未知')
        . '　全部文件占用 ' . wc_e(wc_format_size((int)wc_db_one('SELECT COALESCE(SUM(size),0) FROM wc_file'))) . '</div>';
    cp_panel_end();
}

cp_panel($tabs[$tab] . '（参数键名前缀 ' . wc_e($tab) . '）');
echo '<form method="post" action="' . wc_e(cp_url('settings.php', array('tab' => $tab))) . '">' . wc_csrf_field()
    . '<input type="hidden" name="do" value="save"><input type="hidden" name="tab" value="' . wc_e($tab) . '">'
    . '<table class="cpTb cpForm"><tr class="hd"><td width="200">参数</td><td>设置值</td></tr>';
foreach ($fields[$tab] as $k => $def) {
    list($label, $type, $note, $default) = $def;
    $cur = wc_setting($k, $default);
    $ctl = '';
    if ($type === 'switch') {
        $ctl = '<label><input type="checkbox" name="' . $k . '" value="1"' . ((int)$cur === 1 ? ' checked' : '') . '> 开启</label>';
    } elseif ($type === 'area') {
        $rows = in_array($k, array('terms_text', 'help_text'), true) ? '16' : '5';
        $ctl = '<textarea name="' . $k . '" class="txtArea" rows="' . $rows . '" cols="90">' . wc_e($cur) . '</textarea>';
    } elseif ($type === 'kindlist') {
        $arr = wc_setting_arr($k, array('image', 'video', 'audio', 'doc', 'archive', 'code', 'other'));
        foreach (wc_kind_map() as $kk => $vv) {
            $ctl .= '<label style="margin-right:10px"><input type="checkbox" name="' . $k . '[]" value="' . $kk . '"'
                . (in_array($kk, $arr, true) ? ' checked' : '') . '> ' . wc_e(wc_kind_name($kk)) . '</label>';
        }
    } elseif ($type === 'ext') {
        $arr = wc_setting_arr($k, array());
        $ctl = '<textarea name="' . $k . '" rows="3" cols="70" class="txtArea">' . wc_e(implode(' ', $arr)) . '</textarea>';
    } elseif ($type === 'list') {
        $arr = wc_setting_arr($k, array());
        $ctl = '<input type="text" name="' . $k . '" size="70" value="' . wc_e(implode(' ', $arr)) . '">';
    } elseif ($type === 'select') {
        $opts = (isset($def[4]) && is_array($def[4])) ? $def[4] : array();
        $ctl = '<select name="' . $k . '">';
        foreach ($opts as $o => $ol) {
            $ctl .= '<option value="' . wc_e($o) . '"' . ($cur === $o ? ' selected' : '') . '>' . wc_e($ol) . '</option>';
        }
        $ctl .= '</select>';
    } else {
        $w = $type === 'int' ? '8' : ($type === 'size' ? '12' : '50');
        $ctl = '<input type="text" name="' . $k . '" size="' . $w . '" value="' . wc_e($cur) . '">';
    }
    echo '<tr><th>' . wc_e($label) . '<br><span class="mono mini">' . wc_e($k) . '</span></th><td>' . $ctl
        . ($note !== '' ? '<span class="note">' . $note . '</span>' : '') . '</td></tr>';
}
echo '<tr><th>&nbsp;</th><td><input type="submit" class="btn" value="保存本组设置">　'
    . '<span class="mini">保存后立即生效；涉及发信的参数请在「邮件配置」中测试。</span></td></tr></table></form>';
cp_panel_end();

cp_foot();

function wc_settings_fields()
{
    return array(
        'site' => array(
            'site_name' => array('站点名称', 'text', '出现在标题、导航与邮件中', '文谶云盘'),
            'site_slogan' => array('站点副标题', 'text', '首页 Logo 下方一句话', '图片 · 视频 · 文档 外链云盘'),
            'site_notice' => array('首页公告条', 'text', '首页顶部黄条提示', ''),
            'site_icp' => array('备案号', 'text', '页脚显示并可点击跳转备案网站，留空不显示', ''),
            'footer_text' => array('页脚附加文字', 'text', '统计行后面追加的自定义说明', ''),
            'announce_open' => array('启用公告板块', 'switch', '关闭后首页与导航不再显示公告', '1'),
            'index_show_share' => array('允许公开索引', 'switch', '关闭后首页「最新公开分享 / 下载排行 / 站内搜索」全部停用', '1'),
            'site_close' => array('闭站维护', 'switch', '开启后除管理员和外链下载外前台停止访问', '0'),
            'site_close_msg' => array('闭站提示语', 'text', '', '站点维护中，请稍后再来。'),
        ),
        'upload' => array(
            'upload_open' => array('允许上传', 'switch', '关闭后所有账号都不能上传新文件', '1'),
            'max_size' => array('单文件上限', 'size', '支持 200MB / 2GB 等写法，实际取与 PHP 限制的较小值', '200 MB'),
            'daily_limit' => array('每人每日上传量', 'size', '0 表示不限制', '0'),
            'allow_kinds' => array('允许的大类', 'kindlist', '未勾选的大类一律拒绝', '[]'),
            'allow_ext' => array('扩展名白名单', 'ext', '留空表示不按白名单限制（仅按大类与黑名单）；填空格或逗号分隔，如：jpg png pdf mp4', '[]'),
            'block_ext' => array('扩展名黑名单', 'ext', '始终拦截，务必保留 php/exe/sh 等危险类型', '[]'),
            'dedupe' => array('秒传 / 内容去重', 'switch', '相同 SHA1+大小 的文件只保存一份实体', '1'),
            'chunk_enable' => array('允许分片断点续传', 'switch', '大文件自动切片上传，中断可续', '1'),
            'chunk_max_size' => array('分片大小', 'size', '建议 2-8MB，需小于 PHP upload_max_filesize', '8 MB'),
            'url_fetch' => array('允许 URL 转存', 'switch', '用户填写网络地址由服务器代拉', '1'),
            'fetch_timeout' => array('转存超时(秒)', 'int', '', '120'),
            'fetch_ua' => array('转存 User-Agent', 'text', '部分站点需要伪装浏览器才允许直链下载', 'Mozilla/5.0'),
            'thumb_enable' => array('生成缩略图', 'switch', '需要 GD 扩展；视频缩略图需要配置 ffmpeg', '1'),
            'thumb_w' => array('缩略图宽', 'int', '像素', '240'),
            'thumb_h' => array('缩略图高', 'int', '像素', '240'),
            'thumb_quality' => array('缩略图质量', 'int', 'JPEG 质量 60-95', '82'),
            'thumb_side_max' => array('原图最大边长', 'int', '超过则跳过缩略图，避免内存爆炸', '4000'),
            'ffmpeg_path' => array('ffmpeg 路径', 'text', '如 /usr/local/bin/ffmpeg，留空则不做视频截图', ''),
            'ffmpeg_seek' => array('视频取帧秒数', 'int', '', '1'),
            'upload_notice' => array('上传页提示', 'text', '显示在上传限制说明最后', ''),
        ),
        'share' => array(
            'share_open' => array('允许创建外链', 'switch', '', '1'),
            'share_default_days' => array('默认有效期(天)', 'int', '0 表示默认永久', '0'),
            'share_max_down' => array('默认下载上限', 'int', '0 表示不限，用户仍可自填', '0'),
            'share_allow_direct' => array('允许直链访问', 'switch', '关闭后所有 /d/ 地址一律拒绝', '1'),
            'share_hot_ref' => array('防盗链白名单', 'list', '允许的 Referer 域名，空格或逗号分隔，如：example.com baidu.com；留空表示不限制', ''),
            'share_page_view' => array('外链页显示其他分享', 'switch', '在分享页底部展示该用户的其他公开分享', '1'),
            'hot_preview_max' => array('外链缩略显示上限', 'size', '图片超过该大小只显示图标，避免拉全图', '20 MB'),
            'thumb_direct_max' => array('缩略图回退原图上限', 'size', '没有生成缩略图时，图片直出原图的大小阈值', '8 MB'),
            'qr_enable' => array('显示二维码', 'switch', '', '1'),
            'qr_api' => array('二维码生成接口', 'text', '默认使用公共接口，可换成自建服务；关闭二维码则不请求', 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='),
            'max_zip_size' => array('打包上限', 'size', '超过则跳过剩余文件，避免生成超大 ZIP', '2 GB'),
            'down_speed' => array('下载限速(KB/s)', 'int', '0 表示不限速；限速会占用 PHP 进程时间', '0'),
            'direct_cache' => array('直链缓存(秒)', 'int', '浏览器缓存直链内容的时长', '60'),
            'recycle_days' => array('回收站保留(天)', 'int', '到期自动彻底删除', '7'),
        ),
        'security' => array(
            'reg_open' => array('开放注册', 'switch', '', '1'),
            'reg_auto_verify' => array('注册即视为已验证', 'switch', '仅在无法发信时使用，正常应保持关闭', '0'),
            'captcha_reg' => array('注册验证码', 'switch', '', '1'),
            'captcha_login' => array('登录/找回密码验证码', 'switch', '', '0'),
            'admin_captcha' => array('后台登录验证码', 'switch', '强烈建议开启', '1'),
            'default_quota' => array('新用户初始配额', 'size', '', '1 GB'),
            'limit_login_ip' => array('登录尝试/IP(次/分钟)', 'int', '', '30'),
            'limit_login_fail' => array('单账号失败上限', 'int', '达到后锁定该邮箱 15 分钟', '8'),
            'limit_reg_ip' => array('注册次数/IP(次/小时)', 'int', '', '5'),
            'limit_upload' => array('上传请求/分钟', 'int', '', '600'),
            'limit_download_ip' => array('下载请求/分钟', 'int', '', '120'),
            'mail_black_domain' => array('禁止注册的邮箱域', 'list', '空格分隔，如：tempmail.com', '[]'),
            'ip_black' => array('IP 黑名单', 'list', '支持完整 IP、1.2.3.* 前缀与 1.2.3.0/24；命中者禁止访问全站（管理员会话不受限）', '[]'),
            'trust_proxy' => array('信任反向代理', 'switch', '开启后从 X-Forwarded-For 取真实 IP，仅在前面有 Nginx/CDN 时启用', '0'),
            'log_keep_days' => array('日志保留(天)', 'int', '自动清理任务使用', '30'),
            'cron_auto' => array('自动清理任务', 'switch', '有访问时最长每 30 分钟执行一次过期清理', '1'),
        ),
        'seo' => array(
            'seo_title' => array('首页标题', 'text', '浏览器标签与搜索结果里显示的标题，留空则用「站点名称」', ''),
            'seo_keywords' => array('首页关键词', 'text', '逗号分隔，多个关键词', '云盘,外链分享,图片上传,文件网盘'),
            'seo_description' => array('站点描述', 'text', '建议 80-160 字，留空则自动取首页公告条', ''),
            'seo_title_format' => array('内页标题格式', 'text', '可用变量 {page} 与 {site}，如：{page}_{site}', '{page} - {site}'),
            'seo_robots_meta' => array('内页索引策略', 'select', '控制前台页面 <meta name="robots">', 'index,follow',
                array('index,follow' => 'index,follow（允许收录）', 'noindex,follow' => 'noindex,follow（不收录但跟踪）', 'noindex,nofollow' => 'noindex,nofollow（全部禁止）')),
            'seo_index_share' => array('允许收录外链页', 'switch', '开启后 /s/短码 页面可被搜索引擎收录（默认禁止，避免用户私密分享被爬取）', '0'),
            'seo_robots_file' => array('启用 robots.txt', 'switch', '动态输出 robots.txt，禁止抓取 data/core/config 等目录', '1'),
            'seo_sitemap' => array('启用 sitemap.xml', 'switch', '输出首页、公告、帮助与公开分享的站点地图', '1'),
            'seo_sitemap_days' => array('地图仅含近 N 天分享', 'int', '0 表示全部公开分享，最多输出 5000 条', '90'),
            'seo_stats_enable' => array('启用统计代码', 'switch', '关闭后不输出统计代码', '1'),
            'seo_stats_code' => array('统计/分析代码', 'area', '原样输出到每个前台页面底部，可放百度统计、Google Analytics、友盟等。仅超级管理员可修改。', ''),
        ),
        'avatar' => array(
            'gravatar_enable' => array('启用 Gravatar 头像', 'switch', '用户未上传自定义头像时，按邮箱 MD5 从 Gravatar 取像；请求带 d=404，无头像时显示昵称首字占位', '1'),
            'gravatar_mirror' => array('国内镜像地址', 'text', '默认 gravatar.fohok.xin，只需填写协议+域名，程序自动拼 /avatar/{hash}?s=尺寸&d=404', 'https://gravatar.fohok.xin'),
            'gravatar_official' => array('官方源地址', 'text', '镜像探测失败时的备用源，也是前端 onerror 的二级降级地址', 'https://www.gravatar.com'),
            'gravatar_timeout' => array('探测超时(秒)', 'int', '探测镜像是否在线的单次超时时间', '3'),
            'gravatar_cache' => array('探测结果缓存(秒)', 'int', '镜像在线时可较久；判定不可达时固定 30 秒后重试，便于镜像恢复', '600'),
        ),
        'ui' => array(
            'ui_skin' => array('默认皮肤', 'select', 'mdui = Material Design 3 深色新版；classic = 2005 经典门户。访客可在页脚自行切换（cookie 覆盖）', 'mdui',
                array('mdui' => 'mdui · Material3 深色新版', 'classic' => 'classic · 2005 经典')),
            'ui_theme' => array('默认主题', 'select', '深色 / 浅色 / 跟随系统；访客可用顶栏按钮切换', 'dark',
                array('dark' => '深色', 'light' => '浅色', 'auto' => '跟随系统')),
            'ui_cdn' => array('mdui 资源来源', 'select', 'local = 使用 assets/vendor 内置文件（推荐，离线可用）；cdn = 走外部 CDN', 'local',
                array('local' => '本地内置', 'cdn' => '外部 CDN')),
            'cdn_base' => array('CDN 基地址', 'text', '仅 ui_cdn=cdn 时生效，如 https://cdn.jsdelivr.net/npm/mdui@2 或 https://unpkg.com/mdui@2', 'https://cdn.jsdelivr.net/npm/mdui@2'),
        ),
        'text' => array(
            'site_notice' => array('首页公告条', 'area', '纯文本，自动转义', ''),
            'upload_notice' => array('上传页底部提示', 'area', '', ''),
            'help_text' => array('帮助中心正文', 'area', '留空则使用内置的分条帮助；支持基础 HTML', ''),
            'terms_text' => array('服务条款正文', 'area', '留空则使用内置条款；支持基础 HTML（script 会被清除）', ''),
            'terms_date' => array('条款更新日期', 'text', '', ''),
            'report_contact' => array('投诉联系邮箱', 'text', '显示在服务条款与举报页，留空则用发信邮箱', ''),
        ),
    );
}

/** 与 announces.php 同名函数：清除脚本与内联事件 */
if (!function_exists('ann_clean')) {
    function wc_ann_clean($html)
    {
        $html = (string)$html;
        $html = preg_replace('#<\s*(script|style|iframe|frame|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|style|iframe|frame|object|embed)[^>]*/?>#is', '', $html);
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(javascript|data|vbscript)\s*:#i', '', $html);
        return mb_substr(trim($html), 0, 60000);
    }
}
