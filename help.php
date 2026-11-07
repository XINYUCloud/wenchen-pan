<?php
/** 帮助中心：可由后台改写正文，未设置时用内置说明 */
require_once __DIR__ . '/core/bootstrap.php';

$custom = wc_setting('help_text', '');
$topics = array(
    '一、怎么上传文件？' => array(
        '注册并验证邮箱后，进入「上传文件」页面。可以把文件直接拖进虚线区域，也可以点击「选择文件」多选；'
        . '在网页里按 Ctrl+V 粘贴截图同样能上传。',
        '小文件走一次性上传；超过 ' . wc_upload_limit_hint() . ' 会自动切成小分片依次上传，中途关掉了浏览器，'
        . '下次再选同一个文件会从断点继续（服务器保留分片 2 天）。',
    ),
    '二、外链 / 直链有什么区别？' => array(
        '外链（/s/短码）是一个展示页，访客能看到文件信息、预览图、下载按钮，可加访问密码、限制有效期与下载次数。',
        '直链（/d/短码）直接把文件内容吐给浏览器，适合在论坛、图床引用图片，或在播放器里在线看视频。'
        . '需要在外链设置里勾选「允许直链」才会开放；站点也支持配置防盗链 Referer 白名单。',
    ),
    '三、文件大小与类型限制' => array(
        '单文件上限：' . wc_e(wc_setting('max_size', '200 MB')) . '，允许大类：'
        . wc_e(implode('、', array_map('wc_kind_name', wc_setting_arr('allow_kinds', array('image', 'video', 'audio', 'doc', 'archive', 'code', 'other'))))) . '。',
        '脚本与可执行文件（php/exe/bat/sh 等）属于禁止类型，任何账号都不能上传，后台也会记录拦截日志。',
    ),
    '四、回收站与空间' => array(
        '删除文件先进入回收站，保留 ' . (int)wc_setting('recycle_days', '7') . ' 天，可还原或彻底删除；'
        . '到期后系统自动清除并释放空间。',
        '我的配额：' . wc_e(wc_setting('default_quota', '1 GB')) . '（管理员可单独调整）。同一份内容重复上传会命中秒传，'
        . '只保留一份实体，但各账号仍各自计算占用。',
    ),
    '五、下载与播放' => array(
        '下载支持断点续传（HTTP Range），可以用迅雷、IDM 等工具；视频支持拖动进度，取决于浏览器对该编码的支持程度。',
        '文件夹可以整体打包成 ZIP 下载，勾选多个文件也能打包，打包过程不占用服务器临时空间。',
    ),
    '六、举报与申诉' => array(
        '发现违法违规内容，可在外链页点「举报此链接」，或在 <a href="' . wc_e(wc_url('report.php')) . '">违规举报</a> 页提交。'
        . '管理员核实后会关闭外链并可能删除文件，处理结果通过站内消息通知分享者。',
        '被误删可联系站长邮箱申诉。',
    ),
);

wc_page_head('帮助中心');
wc_crumbs(array(array('首页', wc_url()), '帮助中心'));
wc_box_start('帮助中心', '<a href="' . wc_e(wc_url('announce.php')) . '">公告</a> | <a href="' . wc_e(wc_url('terms.php')) . '">服务条款</a> | <a href="' . wc_e(wc_url('report.php')) . '">举报</a>');
if ($custom !== '') {
    echo '<div class="doc">' . nl2br($custom) . '</div>';
} else {
    foreach ($topics as $t => $ps) {
        echo '<h3 class="docHd">' . wc_e($t) . '</h3>';
        foreach ((array)$ps as $p) { echo '<p class="docP">' . $p . '</p>'; }
    }
}
echo '<div class="hr"></div><div class="mini">常用入口：'
    . '<a href="' . wc_e(wc_url('upload.php')) . '">上传文件</a>　<a href="' . wc_e(wc_url('files.php')) . '">我的文件</a>　'
    . '<a href="' . wc_e(wc_url('shares.php')) . '">我的分享</a>　<a href="' . wc_e(wc_url('trash.php')) . '">回收站</a>　'
    . '<a href="' . wc_e(wc_url('user.php')) . '">用户中心</a>　<a href="' . wc_e(wc_url('rank.php')) . '">下载排行</a></div>';
wc_box_end();
wc_page_foot();
