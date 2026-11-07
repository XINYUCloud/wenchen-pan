<?php
/**
 * 文谶云盘 配置文件模板
 * Copyright (C) 2026 文谶 (fohok.xin)，遵循 GPL-3.0-or-later 分发。
 * 安装向导 install/index.php 会复制本文件并写入 config.php
 * 手动修改后请删除 data/installed.lock 之外的缓存，或直接重新访问安装页
 */
if (!defined('WC_PAN')) { exit('No direct access'); }

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'wcpan');
define('DB_CHARSET', 'utf8mb4');

define('SITE_NAME', '文谶云盘');
// 末尾必须带斜杠，例如 http://pan.example.com/
define('SITE_URL', 'http://127.0.0.1:8093/');

define('DATA_DIR', dirname(__DIR__) . '/data');
define('TIMEZONE', 'Asia/Shanghai');

// Redis：连不上时系统自动降级为 MySQL 存储（wc_kv 表），功能不受影响
define('REDIS_ENABLE', true);
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASS', '');
define('REDIS_DB', 0);
define('REDIS_TIMEOUT', 1.0);

define('SESSION_NAME', 'WCSESSION');
define('COOKIE_SECURE', false);
define('DEBUG', false);
