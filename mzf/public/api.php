<?php
/**
 * 标准易支付 - 订单查询接口入口（api.php）
 *
 * 兼容易支付 api.php?act=query|order|orders 约定，转发到 gateway/Submit/api。
 * 伪装 SCRIPT_NAME=index.php，使 ThinkPHP 的 baseUrl/pathinfo 与经 index.php 访问一致。
 */
$__target = '/gateway/Submit/api';
$_GET['s'] = $_REQUEST['s'] = $__target;
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
unset($_SERVER['PATH_INFO']);
$_SERVER['REQUEST_URI'] = '/index.php?s=' . $__target . (empty($_SERVER['QUERY_STRING']) ? '' : '&' . $_SERVER['QUERY_STRING']);
require __DIR__ . '/index.php';
