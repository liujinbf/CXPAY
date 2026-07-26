<?php

namespace support;

use Webman\Bootstrap;

/**
 * 数据库与 Eloquent ORM 引导启动类
 */
class DatabaseBootstrap implements Bootstrap
{
    public static function start($worker)
    {
        if ($worker) {
            $config = config('database');
            if ($config) {
                $capsule = new \Illuminate\Database\Capsule\Manager;
                foreach ($config['connections'] as $name => $conn) {
                    $capsule->addConnection($conn, $name);
                }
                $capsule->setAsGlobal();
                $capsule->bootEloquent();
            }
        }
    }
}
