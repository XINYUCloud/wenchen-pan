<?php
/**
 * 流式 ZIP 打包（STORE 不压缩，不落临时文件，直接输出给浏览器）
 * 用于「文件夹外链 -> 打包下载」「批量选中 -> 打包下载」
 */
if (!defined('WC_PAN')) { exit('No direct access'); }

class ZipStream
{
    private $entries = array();
    private $offset = 0;
    private $bytes = 0;
    private $limit = 0;
    private $skipped = 0;

    public function __construct($downloadName = 'download.zip')
    {
        $this->limit = parse_size(setting('max_zip_size', '2 GB'));
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . ASCII_fallback($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
            header('X-Content-Type-Options: nosniff');
        }
    }

    public function skipped() { return $this->skipped; }

    private static function dos_time($ts)
    {
        $t = getdate((int)$ts);
        $time = ($t['hours'] << 11) | ($t['minutes'] << 5) | (int)($t['seconds'] / 2);
        $year = max(1980, (int)$t['year']);
        $date = (($year - 1980) << 9) | ((int)$t['mon'] << 5) | (int)$t['mday'];
        return array($time, $date);
    }

    private static function safe_name($name)
    {
        $name = str_replace(array('\\', "\0"), array('/', ''), (string)$name);
        $parts = array();
        foreach (explode('/', $name) as $p) {
            $p = trim($p);
            if ($p === '' || $p === '.' || $p === '..') { continue; }
            $parts[] = $p;
        }
        return implode('/', $parts);
    }

    public function add_data($data, $name, $ts = 0)
    {
        $name = self::safe_name($name);
        if ($name === '') { return false; }
        $data = (string)$data;
        $len = strlen($data);
        if ($this->limit > 0 && $this->bytes + $len > $this->limit) { $this->skipped++; return false; }
        $crc = crc32($data);
        list($dt, $dd) = self::dos_time($ts ?: time());
        echo pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dt, $dd, $crc, $len, $len, strlen($name), 0) . $name . $data;
        $this->entries[] = array('name' => $name, 'crc' => $crc, 'size' => $len, 'time' => $dt, 'date' => $dd, 'offset' => $this->offset);
        $this->offset += 30 + strlen($name) + $len;
        $this->bytes += $len;
        return true;
    }

    public function add_file($abs, $name, $ts = 0)
    {
        $name = self::safe_name($name);
        if ($name === '' || !is_file($abs)) { return false; }
        $len = filesize($abs);
        if ($this->limit > 0 && $this->bytes + $len > $this->limit) { $this->skipped++; return false; }
        // 第一遍：算 CRC32（STORE 要求头部先给出 CRC）
        $fp = fopen($abs, 'rb');
        if (!$fp) { return false; }
        $crc = 0;
        while (!feof($fp)) {
            $buf = fread($fp, 1048576);
            if ($buf === false || $buf === '') { break; }
            $crc = $crc === 0 ? crc32($buf) : crc32($buf, $crc);
        }
        rewind($fp);
        list($dt, $dd) = self::dos_time($ts ?: filemtime($abs));
        echo pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dt, $dd, $crc, $len, $len, strlen($name), 0) . $name;
        $this->offset += 30 + strlen($name);
        while (!feof($fp)) {
            $buf = fread($fp, 524288);
            if ($buf === false || $buf === '') { break; }
            echo $buf;
            $this->offset += strlen($buf);
            $this->bytes += strlen($buf);
            flush();
        }
        fclose($fp);
        $this->entries[] = array('name' => $name, 'crc' => $crc, 'size' => $len, 'time' => $dt, 'date' => $dd, 'offset' => $this->offset - $len);
        return true;
    }

    public function finish()
    {
        $cd = '';
        foreach ($this->entries as $en) {
            $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $en['time'], $en['date'],
                $en['crc'], $en['size'], $en['size'], strlen($en['name']), 0, 0, 0, 0, 0, $en['offset']) . $en['name'];
        }
        $count = count($this->entries);
        echo $cd;
        echo pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($cd), $this->offset, 0);
        flush();
        return $count;
    }
}

/** 递归把某用户某文件夹下的文件写入 zip */
function zip_add_folder($zip, $uid, $fid, $prefix = '', $depth = 0)
{
    if ($depth > 8) { return 0; }
    $n = 0;
    $files = db_rows('SELECT * FROM wc_file WHERE uid=? AND folder=? AND del_time=0 ORDER BY name', array((int)$uid, (int)$fid));
    foreach ($files as $f) {
        $abs = file_abs($f);
        if ($abs === '') { continue; }
        if ($zip->add_file($abs, ($prefix !== '' ? $prefix . '/' : '') . $f['name'], (int)$f['add_time'])) { $n++; }
    }
    $kids = db_rows('SELECT * FROM wc_folder WHERE uid=? AND parent=? ORDER BY name', array((int)$uid, (int)$fid));
    foreach ($kids as $k) {
        $n += zip_add_folder($zip, $uid, (int)$k['id'], ($prefix !== '' ? $prefix . '/' : '') . $k['name'], $depth + 1);
    }
    return $n;
}

function zip_add_files($zip, $ids, $prefix = '')
{
    $n = 0;
    foreach (intarr($ids) as $id) {
        $f = db_row('SELECT * FROM wc_file WHERE id=? AND del_time=0', array($id));
        if (!$f) { continue; }
        $abs = file_abs($f);
        if ($abs === '') { continue; }
        if ($zip->add_file($abs, ($prefix !== '' ? $prefix . '/' : '') . $f['name'], (int)$f['add_time'])) { $n++; }
    }
    return $n;
}
