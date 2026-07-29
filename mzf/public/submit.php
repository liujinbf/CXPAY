<?php
/**
 * 标准易支付 - 页面跳转支付入口（submit.php）
 *
 * 大部分易支付对接系统会自动在「接口地址」后拼接 /submit.php。
 * 转发到 gateway/Submit/index，参数(pid/type/out_trade_no/name/money/sign 等)原样透传。
 * 伪装 SCRIPT_NAME=index.php，使 ThinkPHP 的 baseUrl/pathinfo 与经 index.php 访问一致。
 */
$__target = '/gateway/Submit/index';
$_GET['s'] = $_REQUEST['s'] = $__target;
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
unset($_SERVER['PATH_INFO']);
$_SERVER['REQUEST_URI'] = '/index.php?s=' . $__target . (empty($_SERVER['QUERY_STRING']) ? '' : '&' . $_SERVER['QUERY_STRING']);
require __DIR__ . '/index.php';
