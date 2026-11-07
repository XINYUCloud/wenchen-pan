<?php
/** 系统工具：空间明细、缩略图/临时文件维护、统计校准、数据库备份与优化 */
require_once __DIR__ . '/common.php';
wc_require_admin();

$act = cp_action();

if ($act === 'thumb_all') {
    set_time_limit(0);
    $limit = max(1, min(500, wc_postint('limit')));
    $kind = wc_post('kind');
    $where = "thumb='' AND path<>''";
    $args = array();
    if (in_array($kind, array('image', 'video'), true)) { $where .= ' AND kind=?'; $args[] = $kind; }
    $ids = wc_db_col('SELECT id FROM wc_file WHERE ' . $where . ' ORDER BY id ASC LIMIT ' . $limit, $args);
    $ok = 0;
    foreach ($ids as $i) { if (wc_thumb_make((int)$i)) { $ok++; } }
    wc_log_write('admin', '批量生成缩略图', $kind . ' 尝试 ' . count($ids) . ' 成功 ' . $ok, wc_auth_uid(), wc_admin_nick());
    cp_back('本批处理 ' . count($ids) . ' 个文件，成功生成 ' . $ok . ' 个缩略图' . (count($ids) >= $limit ? '（还有更多，可再次执行）' : '（已全部处理完）'));
}
if ($act === 'clean_tmp') {
    $n = 0;
    $bytes = 0;
    foreach (glob(wc_tmp_path('*')) as $p) {
        if (is_file($p)) { $bytes += (int)filesize($p); @unlink($p); $n++; }
    }
    foreach (glob(wc_tmp_path('chunk/*')) as $p) {
        if (is_file($p)) { $bytes += (int)filesize($p); @unlink($p); $n++; }
    }
    wc_db_exec('UPDATE wc_chunk SET status=2 WHERE status=0');
    wc_db_exec('DELETE FROM wc_chunk_part');
    cp_back('已清理临时目录 ' . $n . ' 个文件，约 ' . wc_e(wc_format_size($bytes)));
}
if ($act === 'clean_orphan') {
    wc_require_super();
    set_time_limit(0);
    $used = wc_db_col('SELECT path FROM wc_file GROUP BY path');
    $usedMap = array();
    foreach ($used as $p) { $usedMap[trim((string)$p)] = 1; }
    $n = 0;
    $bytes = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(wc_storage_path(), 2));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getFilename() === 'index.html') { continue; }
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(wc_storage_path()))), '/');
        if (!isset($usedMap[$rel])) {
            if (wc_post('dry') === '1') { $n++; $bytes += $file->getSize(); continue; }
            $bytes += $file->getSize();
            if (@unlink($file->getPathname())) { $n++; }
        }
    }
    wc_log_write('admin', $n . ' 个孤儿文件', wc_post('dry') === '1' ? '仅统计' : '已删除', wc_auth_uid(), wc_admin_nick());
    cp_back((wc_post('dry') === '1' ? '检测到 ' : '已删除 ') . $n . ' 个无引用实体文件，约 ' . wc_e(wc_format_size($bytes)) . '。建议保存记录后再执行删除。');
}
if ($act === 'expire_share') {
    $n = wc_db_exec('UPDATE wc_share SET status=0 WHERE status=1 AND expire>0 AND expire<' . time());
    cp_back('已将 ' . $n . ' 条过期外链标记为关闭');
}
if ($act === 'sync_stat') {
    foreach (wc_db_col('SELECT id FROM wc_user') as $i) { wc_user_sync_used((int)$i); }
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    wc_db_exec('REPLACE INTO wc_stat (d,upload_cnt,upload_bytes,down_cnt,down_bytes,new_user,share_cnt) SELECT ?, COUNT(*), COALESCE(SUM(size),0), COALESCE(SUM(`down`),0), COALESCE(SUM(traffic),0), 0, 0 FROM wc_file WHERE FROM_UNIXTIME(add_time, "%Y-%m-%d")=?', array($yesterday, $yesterday));
    cp_back('已重新统计全部账号占用，并校准昨日数据');
}
if ($act === 'optimize') {
    wc_require_super();
    $out = array();
    foreach (wc_db_col('SHOW TABLES') as $t) {
        try { wc_db_query('OPTIMIZE TABLE `' . str_replace(array('`', ';'), '', $t) . '`'); $out[] = $t; } catch (Exception $e) {}
    }
    cp_back('已优化 ' . count($out) . ' 张数据表');
}
if ($act === 'flush_cache') {
    WcKV::del('set:all');
    WcKV::flush_prefix('cap:');
    cp_back('已刷新设置缓存与验证码');
}
if ($act === 'ffmpeg_test') {
    $bin = trim(wc_setting('ffmpeg_path', ''));
    if ($bin === '') { cp_back('请先在「上传与类型」中填写 ffmpeg 路径', true); }
    $out = array();
    $rc = 0;
    @exec(escapeshellarg($bin) . ' -version 2>&1', $out, $rc);
    if ($rc === 0 && $out) { cp_back('ffmpeg 可用：' . wc_e((string)$out[0])); }
    cp_back('执行失败（返回码 ' . $rc . '）：请检查路径与 exec 是否被 PHP 禁用（disable_functions）', true);
}
if ($act === 'dump') {
    wc_require_super();
    if (wc_get('go') !== '1' || wc_get('k') !== wc_csrf_token()) { cp_back('备份链接校验失败，请重新点击', true); }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="wcpan_backup_' . date('Ymd_His') . '.sql"');
    echo "-- 文谶云盘数据备份\n-- 生成时间：" . date('c') . "\n-- 数据库：" . DB_NAME . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach (wc_db_col('SHOW TABLES') as $t) {
        $t = str_replace(array('`', ';', "\n", "\r", ' '), '', (string)$t);
        if ($t === '') { continue; }
        $create = wc_db_row('SHOW CREATE TABLE `' . $t . '`');
        $ddl = '';
        if ($create) {
            $vals = array_values($create);
            $ddl = isset($vals[1]) ? (string)$vals[1] : '';
        }
        echo "-- 表 " . $t . "\nDROP TABLE IF EXISTS `" . $t . "`;\n" . $ddl . ";\n\n";
        $offset = 0;
        while (true) {
            $rows = wc_db_rows('SELECT * FROM `' . $t . '` LIMIT ' . $offset . ',200');
            if (!$rows) { break; }
            foreach ($rows as $row) {
                $cols = array();
                $vals = array();
                foreach ($row as $c => $v) { $cols[] = '`' . $c . '`'; $vals[] = $v === null ? 'NULL' : wc_db()->quote((string)$v); }
                echo 'INSERT INTO `' . $t . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
            }
            echo "\n";
            if (count($rows) < 200) { break; }
            $offset += 200;
            flush();
        }
    }
    echo "-- 备份结束\n";
    wc_log_write('admin', '导出数据备份', DB_NAME, wc_auth_uid(), wc_admin_nick());
    exit;
}

if ($act === 'grav_test') {
    $mirror = rtrim(trim(wc_setting('gravatar_mirror', 'https://gravatar.fohok.xin')), '/');
    if ($mirror === '') { cp_back('未配置镜像地址', true); }
    $alive = wc_gravatar_mirror_alive(true);
    $official = rtrim(trim(wc_setting('gravatar_official', 'https://www.gravatar.com')), '/');
    $o2 = wc_http_probe($official . '/avatar/' . md5('probe@invalid') . '?s=32&d=404');
    cp_back('镜像 ' . wc_e($mirror) . '：' . ($alive ? '<span class="ok">在线</span>，头像走镜像' : '<span class="danger">不可达</span>，已自动降级官方源')
        . '；官方源 ' . wc_e($official) . '：' . ($o2 ? '在线' : '不可达') . '。探测结果已刷新缓存。');
}

cp_header('系统工具', 'tools.php');

/* ---------- 空间明细 ---------- */
$dirs = array('storage' => '文件实体 data/storage', 'thumbs' => '缩略图 data/thumbs', 'tmp' => '临时分片 data/tmp', 'logs' => '日志 data/logs', 'avatars' => '头像 data/avatars');
cp_panel('存储明细', '<span class="mini">目录扫描最多统计 20000 个文件</span>');
echo '<table class="cpTb"><tr class="hd"><td width="200">项目</td><td width="110" align="right">占用</td>'
    . '<td width="80" align="right">文件数</td><td>说明</td></tr>';
$storageCnt = (int)wc_db_one('SELECT COUNT(DISTINCT path) FROM wc_file WHERE path<>""');
$storageBytes = (int)wc_db_one('SELECT COALESCE(SUM(size),0) FROM wc_file');
echo '<tr><td>数据表口径 · 文件实体</td><td align="right">' . wc_e(wc_format_size($storageBytes)) . '</td><td align="right">' . number_format($storageCnt) . '</td>'
    . '<td class="mini">按 wc_file 统计（含去重引用，秒传后实际磁盘占用可能更小）</td></tr>';
foreach ($dirs as $d => $label) {
    $r = wc_dir_stat(DATA_DIR . '/' . $d);
    echo '<tr class="r' . ((int)array_search($d, array_keys($dirs)) % 2) . '"><td>' . wc_e($label) . '</td>'
        . '<td align="right">' . wc_e(wc_format_size($r[0])) . '</td><td align="right">' . number_format($r[1]) . '</td>'
        . '<td class="mini">' . wc_e(wc_dir_note($d)) . '</td></tr>';
}
$free = @disk_free_space(DATA_DIR);
$total = @disk_total_space(DATA_DIR);
echo '<tr><td>磁盘可用</td><td align="right">' . wc_e($free !== false ? wc_format_size($free) : '未知') . '</td><td align="right">/</td>'
    . '<td class="mini">分区总量 ' . wc_e($total !== false ? wc_format_size($total) : '未知') . '</td></tr>';
echo '</table>';
cp_panel_end();

/* ---------- 数据库 / Redis ---------- */
$tables = array();
foreach (wc_db_rows('SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY DATA_LENGTH DESC', array(DB_NAME)) as $t) {
    $tables[] = $t;
}
cp_panel('数据库与缓存', '当前库 ' . wc_e(DB_NAME) . '　MySQL ' . wc_e((string)wc_db_one('SELECT VERSION()')) . '　KV：' . wc_e(WcKV::backend() === 'redis' ? 'Redis' : 'MySQL 降级'));
echo '<table class="cpTb"><tr class="hd"><td width="180">数据表</td><td width="110" align="right">约行数</td>'
    . '<td width="110" align="right">数据+索引</td><td width="90">引擎</td><td>说明</td></tr>';
foreach ($tables as $t) {
    $name = isset($t['TABLE_NAME']) ? $t['TABLE_NAME'] : '';
    echo '<tr><td class="mono">' . wc_e($name) . '</td><td align="right">' . number_format((int)$t['TABLE_ROWS']) . '</td>'
        . '<td align="right">' . wc_e(wc_format_size((int)$t['DATA_LENGTH'] + (int)$t['INDEX_LENGTH'])) . '</td>'
        . '<td>' . wc_e($t['ENGINE']) . '</td><td class="mini">' . wc_e(wc_table_note($name)) . '</td></tr>';
}
echo '</table>';
if (WcKV::backend() === 'redis') {
    $r = new WcRedisLite();
    $info = '';
    if ($r->connect(REDIS_HOST, REDIS_PORT, 1.5, REDIS_PASS, (int)REDIS_DB)) {
        $res = $r->command(array('DBSIZE'));
        $info = '键数量 ' . (int)$res;
        $ver = $r->command(array('INFO', 'server'));
        if (is_array($ver)) { $ver = implode("\n", $ver); }
        if (preg_match('/redis_version:([^\r\n]+)/', (string)$ver, $m)) { $info .= '　版本 ' . $m[1]; }
        $r->close();
    }
    echo '<div class="mini" style="margin-top:6px">Redis：' . wc_e(REDIS_HOST . ':' . REDIS_PORT . ' DB' . REDIS_DB) . '　' . wc_e($info) . '</div>';
} else {
    echo '<div class="mini" style="margin-top:6px">Redis 未启用或连不上，会话/限流/验证码存放于 wc_kv 表（' . wc_db_count('wc_kv') . ' 行）。'
        . '如需启用请安装 php-redis 扩展或在 config/config.php 正确填写 REDIS_* 参数。</div>';
}
cp_panel_end();

/* ---------- 维护操作 ---------- */
cp_panel('维护操作');
echo '<div class="mini">下列任务会向本页面 POST 执行；「扫描孤儿文件」与「优化数据表」仅超级管理员可用。'
    . '批量生成缩略图可反复执行，每次处理一批。</div>';
echo '<form method="post" action="' . wc_e(cp_url('tools.php')) . '">' . wc_csrf_field();
echo '<table class="cpTb"><tr class="hd"><td width="190">动作</td><td>可选参数</td><td width="170">&nbsp;</td></tr>';
$quick = array(
    'thumb_all' => '批量生成缩略图',
    'clean_tmp' => '清空临时上传目录',
    'expire_share' => '标记过期外链',
    'sync_stat' => '统计校准',
    'flush_cache' => '刷新缓存',
    'ffmpeg_test' => '测试 ffmpeg',
    'grav_test' => '探测 Gravatar 镜像',
    'optimize' => '优化数据表（超管）',
    'clean_orphan' => '扫描/删除孤儿文件（超管）',
);
foreach ($quick as $d => $l) {
    if (in_array($d, array('optimize', 'clean_orphan'), true) && wc_auth_role() !== 'super') { continue; }
    $extra = '';
    if ($d === 'thumb_all') { $extra = '最多 <input type="number" name="limit" value="100" size="4"> 个 <select name="kind"><option value="image">图片</option><option value="video">视频</option><option value="">全部</option></select>'; }
    if ($d === 'clean_orphan') { $extra = '<label><input type="checkbox" name="dry" value="1" checked> 仅统计</label>'; }
    echo '<tr><td>' . wc_e($l) . '</td><td class="mini">' . $extra . '</td>'
        . '<td><input type="submit" class="btn" value="执行 ' . wc_e($l) . '" formaction="?do=' . $d . '"'
        . ($d === 'clean_orphan' || $d === 'optimize' ? ' onclick="return confirm(\'该操作影响较大，确认执行？\')"' : '') . '></td></tr>';
}
echo '</table></form>';
if (wc_auth_role() === 'super') {
    echo '<div style="margin-top:8px"><a class="btn" href="' . wc_e(cp_url('tools.php', array('do' => 'dump', 'go' => 1, 'k' => wc_csrf_token()))) . '">下载数据备份 (.sql)</a> '
        . '<span class="mini">导出全部表结构与数据；实体文件请一并下载 data/storage 目录（宝塔 → 文件 → 压缩下载）。</span></div>';
}
cp_panel_end();
cp_foot();

function wc_dir_stat($path)
{
    $bytes = 0;
    $cnt = 0;
    if (!is_dir($path)) { return array(0, 0); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, 2), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($cnt > 20000) { break; }
        if (!$f->isDir()) { $bytes += $f->getSize(); $cnt++; }
    }
    return array($bytes, $cnt);
}

function wc_dir_note($d)
{
    $m = array(
        'storage' => '上传的文件实体，按 u/用户/年月/随机名 存放，禁止直接 URL 访问',
        'thumbs' => 'GD/ffmpeg 生成的 JPG 缩略图，可随时重建',
        'tmp' => '分片上传的临时切片，合并后自动删除',
        'logs' => '发信日志、SQL 错误、PHP 错误',
        'avatars' => '用户头像原图（有 GD 时会裁成 256 方形）',
    );
    return isset($m[$d]) ? $m[$d] : '';
}

function wc_table_note($t)
{
    $m = array(
        'wc_user' => '账号、配额与状态', 'wc_folder' => '用户目录树', 'wc_file' => '文件记录（含回收站软删除）',
        'wc_share' => '外链分享', 'wc_share_log' => '外链访问与下载日志', 'wc_announce' => '公告',
        'wc_notice' => '站内消息', 'wc_setting' => '站点参数', 'wc_mail_tpl' => '邮件模板',
        'wc_report' => '违规举报', 'wc_log' => '操作/登录/上传日志', 'wc_kv' => 'Redis 不可用时的键值存储',
        'wc_chunk' => '分片上传会话', 'wc_chunk_part' => '已完成的分片索引', 'wc_stat' => '每日统计',
    );
    return isset($m[$t]) ? $m[$t] : '';
}
