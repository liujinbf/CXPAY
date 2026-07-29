<?php
/**
 * 标准易支付 - API 接口支付入口（mapi.php）
 *
 * 大部分易支付对接系统会自动在「接口地址」后拼接 /mapi.php。
 * 转发到 gateway/Submit/mapi，返回 JSON（含二维码/支付链接）。
 * 伪装 SCRIPT_NAME=index.php，使 ThinkPHP 的 baseUrl/pathinfo 与经 index.php 访问一致。
 */
$__target = '/gateway/Submit/mapi';
$_GET['s'] = $_REQUEST['s'] = $__target;
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
unset($_SERVER['PATH_INFO']);
$_SERVER['REQUEST_URI'] = '/index.php?s=' . $__target . (empty($_SERVER['QUERY_STRING']) ? '' : '&' . $_SERVER['QUERY_STRING']);
require __DIR__ . '/index.php';
