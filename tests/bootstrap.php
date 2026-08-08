<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$loader = require dirname(__DIR__) . '/vendor/autoload.php';
if (!$loader instanceof ClassLoader) {
    throw new RuntimeException('Composer 自动加载器初始化失败');
}

$loader->addPsr4('Tests\\', __DIR__ . '/');
