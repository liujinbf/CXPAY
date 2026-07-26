<?php

declare(strict_types=1);

namespace app\payment;

use app\payment\Contracts\PaymentDriverInterface;
use InvalidArgumentException;

/**
 * 支付驱动加载与生命周期管理器
 */
class PaymentManager
{
    /** @var array<string, string> c_type => 驱动类全名 */
    protected static array $drivers = [];

    /** @var array<string, PaymentDriverInterface> 进程内单例缓存 */
    protected static array $instances = [];

    /**
     * 注册支付驱动类
     *
     * @param string $cType 通道类型标识 (如 alipay_official)
     * @param string $class 实现了 PaymentDriverInterface 的驱动类
     */
    public static function register(string $cType, string $class): void
    {
        if (!is_subclass_of($class, PaymentDriverInterface::class)) {
            throw new InvalidArgumentException("驱动类 {$class} 必须实现 PaymentDriverInterface");
        }
        static::$drivers[$cType] = $class;
    }

    /**
     * 获取驱动实例
     */
    public static function make(string $cType): PaymentDriverInterface
    {
        if (isset(static::$instances[$cType])) {
            return static::$instances[$cType];
        }

        if (!isset(static::$drivers[$cType])) {
            // 自动解析约定目录 app\payment\Drivers\{StudlyCase}\Driver
            $studlyName = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $cType)));
            $defaultClass = "app\\payment\\Drivers\\{$studlyName}\\Driver";
            if (class_exists($defaultClass) && is_subclass_of($defaultClass, PaymentDriverInterface::class)) {
                static::$drivers[$cType] = $defaultClass;
            } else {
                throw new InvalidArgumentException("未注册的支付通道驱动: {$cType}");
            }
        }

        $class = static::$drivers[$cType];
        return static::$instances[$cType] = new $class();
    }

    /**
     * 获取所有已发现/已注册驱动元数据列表
     */
    public static function getRegisteredDrivers(): array
    {
        $list = [];
        foreach (static::$drivers as $cType => $class) {
            $list[$cType] = $class::getMeta();
        }
        return $list;
    }
}
