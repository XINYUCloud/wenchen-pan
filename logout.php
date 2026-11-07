<?php
/** 退出登录 */
require_once __DIR__ . '/core/bootstrap.php';
wc_try_logout();
wc_go('index.php');
