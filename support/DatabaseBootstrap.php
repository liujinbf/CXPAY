<?php

namespace support;

use Webman\Bootstrap;
use Workerman\Protocols\Http\Session;

/**
 * 数据库与 Eloquent ORM 引导启动类
 */
class DatabaseBootstrap implements Bootstrap
{
    public static function start($worker)
    {
        if ($worker) {
            self::configureSession();
            $config = config('database');
            if ($config) {
                $capsule = new \Illuminate\Database\Capsule\Manager;
                foreach ($config['connections'] as $name => $conn) {
                    $capsule->addConnection($conn, $name);
                }
                $capsule->getDatabaseManager()->setDefaultConnection($config['default'] ?? 'mysql');
                $capsule->setAsGlobal();
                $capsule->bootEloquent();
            }
        }
    }

    private static function configureSession(): void
    {
        $config = config('session', []);
        Session::$name = (string)($config['name'] ?? 'CXPAYSESSID');
        Session::$lifetime = (int)($config['lifetime'] ?? 7200);
        Session::$cookieLifetime = (int)($config['cookie_lifetime'] ?? 7200);
        Session::$sameSite = (string)($config['same_site'] ?? 'Lax');
        Session::$secure = (bool)($config['secure'] ?? false);
        Session::$httpOnly = (bool)($config['http_only'] ?? true);
        Session::handlerClass(
            (string)($config['handler'] ?? \Workerman\Protocols\Http\Session\FileSessionHandler::class),
            (array)($config['config'] ?? [])
        );
    }
}
