<?php
/**
 * 键值存储层：优先 Redis，连接不上自动降级到 MySQL 表 wc_kv
 * 会话、验证码、限流、缓存都走这一层，业务代码不感知后端差异
 */
if (!defined('WC_PAN')) { exit('No direct access'); }
if (defined('WC_CORE_KV')) { return; }
define('WC_CORE_KV', 1);

class WcRedisLite
{
    private $sock = null;
    private $err = '';

    public function connect($host, $port, $timeout, $pass = '', $db = 0)
    {
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client('tcp://' . $host . ':' . (int)$port, $errno, $errstr, $timeout);
        if (!$fp) { $this->err = $errstr ?: 'connect failed'; return false; }
        stream_set_timeout($fp, (int)ceil($timeout) + 2);
        $this->sock = $fp;
        if ($pass !== '') {
            $r = $this->command(array('AUTH', $pass));
            if ($r === false) { $this->err = 'auth failed'; $this->close(); return false; }
        }
        if ((int)$db > 0) {
            if ($this->command(array('SELECT', (int)$db)) === false) { $this->err = 'select failed'; $this->close(); return false; }
        }
        $ping = $this->command(array('PING'));
        if ($ping === false && strpos($this->err, 'NOAUTH') === false) { $this->err = 'ping failed'; $this->close(); return false; }
        return true;
    }

    public function error() { return $this->err; }
    public function alive() { return is_resource($this->sock) && !feof($this->sock); }

    public function close()
    {
        if (is_resource($this->sock)) { @fclose($this->sock); }
        $this->sock = null;
    }

    public function command(array $args)
    {
        if (!is_resource($this->sock)) { return false; }
        $buf = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string)$a;
            $buf .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        if (@fwrite($this->sock, $buf) === false) { $this->err = 'write failed'; $this->close(); return false; }
        $reply = $this->read_reply();
        if ($reply === false && $this->err === '') { $this->err = 'bad reply'; }
        if (is_string($reply) && strlen($reply) > 0 && $reply[0] === '-') { $this->err = $reply; return false; }
        return $reply;
    }

    private function read_line()
    {
        $s = '';
        while (($c = fgets($this->sock, 1024)) !== false) {
            $s .= $c;
            if (substr($s, -2) === "\r\n") { return substr($s, 0, -2); }
            if (strlen($s) > 1 << 22) { break; }
        }
        $this->err = 'read timeout';
        $this->close();
        return false;
    }

    private function read_bytes($n)
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->sock, $n - strlen($data));
            if ($chunk === false || $chunk === '') { $this->err = 'read broken'; $this->close(); return false; }
            $data .= $chunk;
        }
        return $data;
    }

    private function read_reply()
    {
        $line = $this->read_line();
        if ($line === false || $line === '') { return false; }
        $type = $line[0];
        $body = substr($line, 1);
        switch ($type) {
            case '+': return $body;
            case ':': return (int)$body;
            case '-': $this->err = $body; return false;
            case '$':
                $len = (int)$body;
                if ($len < 0) { return null; }
                $data = $this->read_bytes($len + 2);
                if ($data === false) { return false; }
                return substr($data, 0, $len);
            case '*':
                $n = (int)$body;
                if ($n < 0) { return null; }
                $out = array();
                for ($i = 0; $i < $n; $i++) { $out[] = $this->read_reply(); }
                return $out;
        }
        return false;
    }
}

/**
 * KV 门面。redis 不可用时静默切到 MySQL，并在本次请求内固定后端。
 */
final class WcKV
{
    private static $mode = null;
    private static $redis = null;
    private static $purged = false;

    public static function backend()
    {
        if (self::$mode !== null) { return self::$mode; }
        self::$mode = 'mysql';
        if (defined('REDIS_ENABLE') && REDIS_ENABLE) {
            $r = new WcRedisLite();
            $ok = $r->connect(REDIS_HOST, REDIS_PORT, (float)REDIS_TIMEOUT, REDIS_PASS, (int)REDIS_DB);
            if ($ok) { self::$redis = $r; self::$mode = 'redis'; }
        }
        return self::$mode;
    }

    private static function r($args)
    {
        if (self::backend() !== 'redis') { return null; }
        return self::$redis->command($args);
    }

    private static function purge()
    {
        if (self::$purged) { return; }
        self::$purged = true;
        try { wc_db_exec('DELETE FROM wc_kv WHERE expire_at>0 AND expire_at<' . time()); } catch (Exception $e) {}
    }

    public static function get($k, $default = '')
    {
        if (self::backend() === 'redis') {
            $v = self::$redis->command(array('GET', $k));
            return ($v === null || $v === false) ? $default : $v;
        }
        $row = wc_db_row('SELECT v,expire_at FROM wc_kv WHERE k=?', array($k));
        if (!$row) { return $default; }
        if ($row['expire_at'] > 0 && $row['expire_at'] < time()) { self::del($k); return $default; }
        return (string)$row['v'];
    }

    public static function set($k, $v, $ttl = 0)
    {
        $v = (string)$v;
        if (self::backend() === 'redis') {
            if ($ttl > 0) { self::$redis->command(array('SETEX', $k, (int)$ttl, $v)); }
            else { self::$redis->command(array('SET', $k, $v)); }
            return true;
        }
        self::purge();
        $exp = $ttl > 0 ? time() + (int)$ttl : 0;
        wc_db_exec('REPLACE INTO wc_kv (k,v,expire_at) VALUES (?,?,?)', array($k, $v, $exp));
        return true;
    }

    public static function del($k)
    {
        if (self::backend() === 'redis') { self::$redis->command(array('DEL', $k)); return true; }
        wc_db_exec('DELETE FROM wc_kv WHERE k=?', array($k));
        return true;
    }

    public static function exists($k)
    {
        if (self::backend() === 'redis') { return (int)self::r(array('EXISTS', $k)) > 0; }
        $row = wc_db_row('SELECT expire_at,v FROM wc_kv WHERE k=?', array($k));
        if (!$row) { return false; }
        if ($row['expire_at'] > 0 && $row['expire_at'] < time()) { self::del($k); return false; }
        return true;
    }

    public static function incr($k, $ttl = 0)
    {
        if (self::backend() === 'redis') {
            $n = (int)self::r(array('INCR', $k));
            if ($n === 1 && $ttl > 0) { self::r(array('EXPIRE', $k, (int)$ttl)); }
            return $n;
        }
        $v = (int)self::get($k, '0') + 1;
        self::set($k, (string)$v, $ttl);
        return $v;
    }

    public static function ttl($k)
    {
        if (self::backend() === 'redis') { return (int)self::r(array('TTL', $k)); }
        $row = wc_db_row('SELECT expire_at FROM wc_kv WHERE k=?', array($k));
        if (!$row) { return -2; }
        return $row['expire_at'] > 0 ? $row['expire_at'] - time() : -1;
    }

    public static function keys($pattern)
    {
        if (self::backend() === 'redis') {
            $r = self::r(array('KEYS', $pattern));
            return is_array($r) ? $r : array();
        }
        $like = str_replace(array('%', '_'), array('\%', '\_'), $pattern);
        $like = str_replace('*', '%', $like);
        return wc_db_col('SELECT k FROM wc_kv WHERE k LIKE ? LIMIT 5000', array($like));
    }

    public static function flush_prefix($prefix)
    {
        foreach (self::keys($prefix . '*') as $k) { self::del($k); }
    }

    /** 带过期写入的原子占位，用于分布式锁 / 一次性 token */
    public static function setnx($k, $v, $ttl = 60)
    {
        if (self::backend() === 'redis') {
            $ok = (int)self::r(array('SET', $k, $v, 'NX', 'EX', (int)$ttl));
            return $ok === 1;
        }
        $row = wc_db_row('SELECT v,expire_at FROM wc_kv WHERE k=?', array($k));
        if ($row && ($row['expire_at'] == 0 || $row['expire_at'] > time())) { return false; }
        self::set($k, $v, $ttl);
        return true;
    }
}
