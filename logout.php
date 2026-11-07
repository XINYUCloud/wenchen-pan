<?php
/** 退出登录 */
require_once __DIR__ . '/core/bootstrap.php';
try_logout();
go('index.php');
