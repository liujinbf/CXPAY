<?php

declare(strict_types=1);

namespace app\payment;

use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\AccountCapabilityDetectorInterface;
use app\payment\Contracts\AccountAuthorizationInterface;
use app\payment\Plugin\PluginManager;
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

    /** @var array<string, string> c_type => 插件 ID */
    protected static array $driverPlugins = [];

    /**
     * 注册支付驱动类
     *
     * @param string $cType 通道类型标识（如 alipay_app_asst）
     * @param string $class 实现了 PaymentDriverInterface 的驱动类
     */
    public static function register(string $cType, string $class): void
    {
        if (!is_subclass_of($class, PaymentDriverInterface::class)) {
            throw new InvalidArgumentException("驱动类 {$class} 必须实现 PaymentDriverInterface");
        }
        static::$drivers[$cType] = $class;
    }

    public static function registerPluginDriver(string $cType, string $class, string $pluginId): void
    {
        if (!is_subclass_of($class, PaymentDriverInterface::class)) {
            throw new InvalidArgumentException("插件驱动类 {$class} 必须实现 PaymentDriverInterface");
        }
        if (isset(static::$drivers[$cType])
            && (static::$driverPlugins[$cType] ?? '') !== $pluginId) {
            throw new InvalidArgumentException("插件驱动标识与现有驱动冲突: {$cType}");
        }
        static::$drivers[$cType] = $class;
        static::$driverPlugins[$cType] = $pluginId;
    }

    /**
     * 获取驱动实例
     */
    public static function make(string $cType): PaymentDriverInterface
    {
        static::discoverDrivers();
        if (!static::driverIsEnabled($cType)) {
            throw new InvalidArgumentException("支付通道插件已停用: {$cType}");
        }
        if (isset(static::$instances[$cType])) {
            return static::$instances[$cType];
        }

        if (!isset(static::$drivers[$cType])) {
            // 自动解析约定目录 app\payment\Drivers\{StudlyCase}\Driver
            $studlyName = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $cType)));
            $defaultClass = "app\\payment\\Drivers\\{$studlyName}\\Driver";
            if (static::classIsAvailable($defaultClass)) {
                static::$drivers[$cType] = $defaultClass;
            } else {
                throw new InvalidArgumentException("未注册的支付通道驱动: {$cType}");
            }
        }

        $class = static::$drivers[$cType];
        if (!static::classIsAvailable($class)) {
            unset(static::$drivers[$cType], static::$instances[$cType]);
            throw new InvalidArgumentException("支付通道驱动尚未完成或已停用: {$cType}");
        }
        return static::$instances[$cType] = new $class();
    }

    /**
     * 判断驱动是否已注册或可按目录约定自动发现。
     */
    public static function has(string $cType): bool
    {
        static::discoverDrivers();
        if (!static::driverIsEnabled($cType)) {
            return false;
        }
        if (isset(static::$drivers[$cType])) {
            return static::classIsAvailable(static::$drivers[$cType]);
        }

        $studlyName = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $cType)));
        $defaultClass = "app\\payment\\Drivers\\{$studlyName}\\Driver";

        return static::classIsAvailable($defaultClass);
    }

    /**
     * 获取通道明确声明的监控方式；未声明的普通驱动按无需监控处理。
     */
    public static function monitorMode(string $cType): string
    {
        $driver = static::make($cType);
        if (!$driver instanceof MonitorableDriverInterface) {
            return MonitorableDriverInterface::MODE_NONE;
        }

        $mode = $driver->monitorMode();
        $allowed = [
            MonitorableDriverInterface::MODE_NONE,
            MonitorableDriverInterface::MODE_PUSH,
            MonitorableDriverInterface::MODE_CALLBACK,
            MonitorableDriverInterface::MODE_SERVER,
        ];
        if (!in_array($mode, $allowed, true)) {
            throw new InvalidArgumentException("支付通道驱动返回了非法监控方式: {$cType}");
        }
        return $mode;
    }

    /** 挂机助手推送类通道必须依靠心跳决定在线状态。 */
    public static function requiresHeartbeat(string $cType): bool
    {
        return static::monitorMode($cType) === MonitorableDriverInterface::MODE_PUSH;
    }

    /** @return array{status:string,message:string,capabilities?:array<string,bool>} */
    public static function detectAccountCapabilities(string $cType, array $config): array
    {
        $driver = static::make($cType);
        if (!$driver instanceof AccountCapabilityDetectorInterface) {
            return [
                'status' => AccountCapabilityDetectorInterface::STATUS_UNKNOWN,
                'message' => '当前支付插件不支持账号能力探测',
            ];
        }
        $result = $driver->detectAccountCapabilities($config);
        $allowed = [
            AccountCapabilityDetectorInterface::STATUS_UNKNOWN,
            AccountCapabilityDetectorInterface::STATUS_RECEIPT_AVAILABLE,
            AccountCapabilityDetectorInterface::STATUS_RECEIPT_NOT_OPENED,
            AccountCapabilityDetectorInterface::STATUS_BOOK_AVAILABLE,
            AccountCapabilityDetectorInterface::STATUS_REAUTH_REQUIRED,
            AccountCapabilityDetectorInterface::STATUS_TEMPORARY_ERROR,
        ];
        if (!in_array($result['status'] ?? '', $allowed, true)) {
            throw new InvalidArgumentException("支付插件返回了非法账号能力状态: {$cType}");
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public static function startAccountAuthorization(string $cType, array $config): array
    {
        $driver = static::make($cType);
        if (!$driver instanceof AccountAuthorizationInterface) {
            throw new InvalidArgumentException('当前支付插件不支持扫码授权');
        }
        return $driver->startAccountAuthorization($config);
    }

    /** @return array<string, mixed> */
    public static function pollAccountAuthorization(string $cType, string $sessionId, array $config): array
    {
        $driver = static::make($cType);
        if (!$driver instanceof AccountAuthorizationInterface) {
            throw new InvalidArgumentException('当前支付插件不支持扫码授权');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $sessionId)) {
            throw new InvalidArgumentException('授权会话 ID 不合法');
        }
        return $driver->pollAccountAuthorization($sessionId, $config);
    }

    /**
     * 获取所有已发现/已注册驱动元数据列表
     *
     * 修复：原来用 $class::getMeta() 静态调用实例方法，PHP 8 严格模式下会产生 Deprecation
     *        改为先 new $class() 实例化再调用实例方法
     */
    public static function getRegisteredDrivers(): array
    {
        static::discoverDrivers();
        $list = [];
        foreach (static::$drivers as $cType => $class) {
            if (!static::driverIsEnabled($cType) || !static::classIsAvailable($class)) {
                continue;
            }
            try {
                // 使用单例缓存（如果已实例化则复用）
                $instance     = static::$instances[$cType] ?? new $class();
                $meta = $instance->getMeta();
                if ($instance instanceof MonitorableDriverInterface) {
                    $meta['monitor_mode'] = static::monitorMode($cType);
                }
                $list[$cType] = $meta;
            } catch (\Throwable $e) {
                // 驱动报错不影响其他驱动列表
                $list[$cType] = ['name' => $cType, 'title' => $cType . ' (load error)'];
            }
        }
        return $list;
    }

    /**
     * 按约定目录发现内置驱动，避免后台展示不存在的硬编码驱动。
     */
    protected static function discoverDrivers(): void
    {
        $driverFiles = glob(app_path('payment/Drivers/*/Driver.php')) ?: [];
        foreach ($driverFiles as $driverFile) {
            $directory = basename(dirname($driverFile));
            $class = "app\\payment\\Drivers\\{$directory}\\Driver";
            if (!static::classIsAvailable($class)) {
                continue;
            }
            try {
                $instance = new $class();
                $meta = $instance->getMeta();
                $cType = trim((string)($meta['name'] ?? ''));
                if ($cType !== '') {
                    static::$drivers[$cType] = $class;
                }
            } catch (\Throwable) {
                // 单个驱动初始化失败不影响其余驱动发现。
            }
        }
        PluginManager::discoverEnabledDrivers();
    }

    public static function flush(): void
    {
        static::$drivers = [];
        static::$instances = [];
        static::$driverPlugins = [];
    }

    public static function pluginId(string $cType): ?string
    {
        static::discoverDrivers();
        return static::$driverPlugins[$cType] ?? null;
    }

    private static function driverIsEnabled(string $cType): bool
    {
        $pluginId = static::$driverPlugins[$cType] ?? null;
        return $pluginId === null || PluginManager::isEnabled($pluginId);
    }

    private static function classIsAvailable(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, PaymentDriverInterface::class)) {
            return false;
        }
        try {
            $meta = (new $class())->getMeta();
            return ($meta['available'] ?? true) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
