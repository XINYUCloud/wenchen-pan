<?php
/**
 * 数据库升级：新增表/字段/默认参数，不覆盖已有数据
 * 覆盖新版代码后访问本页面点「执行升级」即可
 */
define('WC_PAN', 1);
define('WC_ROOT', dirname(__DIR__));
require WC_ROOT . '/config/config.php';
require WC_ROOT . '/core/db.php';
require WC_ROOT . '/core/kv.php';
require WC_ROOT . '/core/functions.php';
require WC_ROOT . '/core/mailer.php';

$done = array();
$err = '';

/** 需要补建/补列的结构定义 */
function upgrade_columns()
{
    return array(
        'wc_user' => array('ban_reason' => "VARCHAR(200) NOT NULL DEFAULT ''", 'login_cnt' => 'INT UNSIGNED NOT NULL DEFAULT 0'),
        'wc_file' => array('tags' => "VARCHAR(255) NOT NULL DEFAULT ''", 'note' => "VARCHAR(255) NOT NULL DEFAULT ''", 'star' => 'TINYINT NOT NULL DEFAULT 0'),
        'wc_share' => array('pub' => 'TINYINT NOT NULL DEFAULT 0', 'direct' => 'TINYINT NOT NULL DEFAULT 0', 'title' => "VARCHAR(255) NOT NULL DEFAULT ''"),
        'wc_chunk' => array('folder' => 'INT UNSIGNED NOT NULL DEFAULT 0'),
    );
}

if (is_post() && post('go') === '1') {
    try {
        // 1) 补建数据表
        $sql = file_get_contents(WC_ROOT . '/install/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $st) {
            if ($st === '') { continue; }
            db_query($st);
        }
        $done[] = '数据表结构已同步（CREATE TABLE IF NOT EXISTS）';

        // 2) 补字段
        foreach (upgrade_columns() as $table => $cols) {
            foreach ($cols as $col => $def) {
                $has = (int)db_one(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',
                    array(DB_NAME, $table, $col)
                );
                if ($has === 0) {
                    db_query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` ' . $def);
                    $done[] = $table . '.' . $col . ' 字段已新增';
                }
            }
        }

        // 3) 补默认参数（已存在的不覆盖）
        $defs = require WC_ROOT . '/install/defaults.php';
        $have = db_col('SELECT k FROM wc_setting');
        $ins = 0;
        foreach ($defs as $k => $v) {
            if (!in_array($k, $have, true)) {
                db_exec('INSERT INTO wc_setting (k,v) VALUES (?,?)', array($k, (string)$v));
                $ins++;
            }
        }
        $done[] = '新增站点默认参数 ' . $ins . ' 项（已有参数保持不变）';

        // 4) 补邮件模板
        $tpls = mail_tpl_defaults();
        $haveTpl = db_col('SELECT k FROM wc_mail_tpl');
        $tins = 0;
        foreach ($tpls as $k => $t) {
            if (!in_array($k, $haveTpl, true)) {
                db_exec('INSERT INTO wc_mail_tpl (k,name,subject,body) VALUES (?,?,?,?)', array($k, $t['名称'], $t['subject'], $t['body']));
                $tins++;
            }
        }
        $done[] = '新增邮件模板 ' . $tins . ' 套';

        KV::del('set:all');
        $done[] = '设置缓存已刷新';
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}
?><!DOCTYPE html><html><head><meta charset="utf-8"><title>文谶云盘 数据库升级</title>
<link rel="stylesheet" href="../assets/install.css"></head><body>
<div class="insBox">
<div class="insHd">文谶云盘 <span>数据库升级</span></div>
<div class="insNav">用于覆盖新版代码后补齐数据表、字段与默认参数，不会删除或覆盖已有数据</div>
<?php if ($err !== ''): ?><div class="insErr">升级失败：<?php echo htmlspecialchars($err); ?></div><?php endif; ?>
<?php if ($done): ?><div class="insOk"><?php foreach ($done as $d) { echo '· ' . htmlspecialchars($d) . '<br>'; } ?></div><?php endif; ?>
<table class="insTb">
<tr><th colspan="2">当前环境</th></tr>
<tr><td width="180">数据库</td><td><?php echo htmlspecialchars(DB_NAME . '@' . DB_HOST . ':' . DB_PORT); ?>　数据表 <?php echo count(db_col('SHOW TABLES')); ?> 张</td></tr>
<tr><td>PHP 版本</td><td><?php echo PHP_VERSION; ?></td></tr>
<tr><td>存储后端</td><td><?php echo KV::backend() === 'redis' ? 'Redis' : 'MySQL 降级（wc_kv）'; ?></td></tr>
<tr><td>建议</td><td class="tips">执行前请先在宝塔面板导出数据库备份；本页面只做 ADD，不做 DROP。</td></tr>
</table>
<form method="post" style="text-align:center;padding:10px">
<input type="hidden" name="go" value="1">
<input type="submit" class="btnBig" value="执行升级" onclick="return confirm('已确认做过数据库备份？')">
</form>
<div class="insFt">升级完成后请删除 install 目录。<a href="../">前台首页</a>　<a href="../admin/">管理后台</a></div>
</div></body></html>
