<?php

return [
    // 支付插件代码目录。安装器只允许在该目录下创建版本化目录。
    'path' => base_path() . '/plugin/cxpay',
    // 运行时注册表不保存任何支付账号密钥，仅保存插件版本与启停状态。
    'registry' => base_path() . '/runtime/payment_plugins/registry.json',
    // 每个可信发布者对应一个 PEM 公钥文件，例如 cxpay.official.pem。
    'trusted_keys' => base_path() . '/config/plugin_keys',
    'max_package_size' => 20 * 1024 * 1024,
    'max_file_size' => 5 * 1024 * 1024,
    'max_files' => 500,
];
