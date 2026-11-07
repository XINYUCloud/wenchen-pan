<?php
/** 数据库访问层（PDO + 预处理） */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_DB')) { return; }
define('WC_CORE_DB', 1);

function wc_db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        } catch (Exception $e) {
            wc_db_fail($e->getMessage());
        }
    }
    return $pdo;
}

function wc_db_fail($msg)
{
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    if (is_dir(DATA_DIR)) { @file_put_contents(DATA_DIR . '/logs/sql_error.log', $line, FILE_APPEND); }
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "SQL ERROR: $msg\n"); exit(1); }
    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    echo '<meta charset="utf-8"><title>数据库连接失败</title><div style="font:12px SimSun,serif;padding:24px">'
        . '<b>数据库连接失败</b><br>请确认 MySQL 已启动、config/config.php 中的账号密码正确，'
        . '或重新运行 <a href="install/index.php">安装向导</a>。<hr>'
        . (DEBUG ? '<pre style="color:#a00">' . htmlspecialchars($msg) . '</pre>' : '')
        . '</div>';
    exit;
}

function wc_db_query($sql, $args = array())
{
    try {
        $st = wc_db()->prepare($sql);
        $st->execute($args);
        return $st;
    } catch (Exception $e) {
        wc_db_fail($e->getMessage() . ' || ' . $sql);
    }
}

function wc_db_rows($sql, $args = array())
{
    return wc_db_query($sql, $args)->fetchAll();
}

function wc_db_row($sql, $args = array())
{
    $r = wc_db_query($sql, $args)->fetch();
    return $r === false ? null : $r;
}

function wc_db_one($sql, $args = array(), $default = 0)
{
    $r = wc_db_query($sql, $args)->fetch(PDO::FETCH_NUM);
    return ($r === false || $r[0] === null) ? $default : $r[0];
}

function wc_db_col($sql, $args = array())
{
    return wc_db_query($sql, $args)->fetchAll(PDO::FETCH_COLUMN);
}

function wc_db_exec($sql, $args = array())
{
    return wc_db_query($sql, $args)->rowCount();
}

function wc_db_insert($table, $data)
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')';
    wc_db_query($sql, array_values($data));
    return (int)wc_db()->lastInsertId();
}

function wc_db_update($table, $data, $where, $args = array())
{
    $set = array();
    $vals = array();
    foreach ($data as $k => $v) { $set[] = "`$k`=?"; $vals[] = $v; }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $where;
    return wc_db_exec($sql, array_merge($vals, $args));
}

function wc_db_count($table, $where = '1', $args = array())
{
    return (int)wc_db_one("SELECT COUNT(*) FROM `$table` WHERE $where", $args);
}

/** 只允许白名单列名参与排序，防注入 */
function wc_db_order($sql_order, $allow, $default)
{
    $sql_order = strtolower(trim((string)$sql_order));
    return isset($allow[$sql_order]) ? $allow[$sql_order] : $default;
}
