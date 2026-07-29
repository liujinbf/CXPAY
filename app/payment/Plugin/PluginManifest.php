<?php

declare(strict_types=1);

namespace app\payment\Plugin;

final class PluginManifest
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PluginException('插件清单不是合法 JSON', 0, $e);
        }
        if (!is_array($data)) {
            throw new PluginException('插件清单必须是 JSON 对象');
        }

        self::validate($data);
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function id(): string
    {
        return (string)$this->data['id'];
    }

    public function slug(): string
    {
        return (string)$this->data['slug'];
    }

    public function name(): string
    {
        return (string)$this->data['name'];
    }

    public function version(): string
    {
        return (string)$this->data['version'];
    }

    public function publisher(): string
    {
        return (string)$this->data['publisher'];
    }

    /** @return list<array{code:string,class:string,file:string}> */
    public function drivers(): array
    {
        return $this->data['drivers'];
    }

    /** @param array<string, mixed> $data */
    private static function validate(array &$data): void
    {
        if (($data['schema'] ?? null) !== 1) {
            throw new PluginException('不支持的插件清单版本');
        }
        self::match($data, 'id', '/^cxpay\.[a-z0-9][a-z0-9._-]{2,80}$/', '插件 ID 不合法');
        self::match($data, 'slug', '/^[a-z0-9][a-z0-9_-]{2,50}$/', '插件目录标识不合法');
        self::match($data, 'version', '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', '插件版本必须使用语义化版本');
        self::match($data, 'publisher', '/^[a-z0-9][a-z0-9._-]{2,80}$/', '插件发布者不合法');

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new PluginException('插件名称不能为空且不能超过 100 个字符');
        }
        if (($data['collection_mode'] ?? '') !== 'personal_qr') {
            throw new PluginException('CXPAY 只允许安装个人收款码插件');
        }
        if (!in_array($data['payment_type'] ?? '', ['alipay', 'wxpay', 'qqpay'], true)) {
            throw new PluginException('插件支付类型不受支持');
        }
        if (!in_array($data['monitor_mode'] ?? '', ['push', 'callback', 'server'], true)) {
            throw new PluginException('插件监控方式不受支持');
        }

        $drivers = $data['drivers'] ?? null;
        if (!is_array($drivers) || $drivers === [] || count($drivers) > 10) {
            throw new PluginException('插件必须声明 1 至 10 个支付驱动');
        }
        $normalized = [];
        $driverCodes = [];
        foreach ($drivers as $driver) {
            if (!is_array($driver)) {
                throw new PluginException('支付驱动声明格式不合法');
            }
            $code = (string)($driver['code'] ?? '');
            $class = (string)($driver['class'] ?? '');
            $file = self::normalizeRelativePath((string)($driver['file'] ?? ''));
            if (!preg_match('/^(?:alipay|wxpay|qqpay)_[a-z0-9_]{2,42}$/', $code)) {
                throw new PluginException("支付驱动标识不合法: {$code}");
            }
            if (!str_starts_with($code, (string)$data['payment_type'] . '_')) {
                throw new PluginException("支付驱动与插件支付类型不一致: {$code}");
            }
            if (isset($driverCodes[$code])) {
                throw new PluginException("插件重复声明支付驱动: {$code}");
            }
            if (!preg_match('/^plugin\\\\cxpay\\\\[A-Za-z0-9_\\\\]+$/', $class)) {
                throw new PluginException("支付驱动类命名空间不合法: {$class}");
            }
            $namespacePrefix = 'plugin\\cxpay\\' . (string)$data['slug'] . '\\';
            if (!str_starts_with($class, $namespacePrefix)) {
                throw new PluginException("支付驱动类必须位于插件自己的命名空间: {$class}");
            }
            if (!str_ends_with(strtolower($file), '.php')) {
                throw new PluginException('支付驱动入口必须是 PHP 文件');
            }
            $normalized[] = ['code' => $code, 'class' => $class, 'file' => $file];
            $driverCodes[$code] = true;
        }
        $data['drivers'] = $normalized;

        $requires = is_array($data['requires'] ?? null) ? $data['requires'] : [];
        if (isset($requires['php']) && !self::matchesVersionConstraint(PHP_VERSION, (string)$requires['php'])) {
            throw new PluginException('当前 PHP 版本不满足插件要求');
        }
        $cxpayVersion = function_exists('config') ? (string)config('app.version', '1.0.0') : '1.0.0';
        if (isset($requires['cxpay'])
            && !self::matchesVersionConstraint($cxpayVersion, (string)$requires['cxpay'])) {
            throw new PluginException('当前 CXPAY 版本不满足插件要求');
        }
        foreach ((array)($requires['extensions'] ?? []) as $extension) {
            if (!is_string($extension) || !preg_match('/^[a-z0-9_-]+$/i', $extension)) {
                throw new PluginException('插件扩展依赖声明不合法');
            }
            if (!extension_loaded($extension)) {
                throw new PluginException("缺少 PHP 扩展: {$extension}");
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function match(array $data, string $key, string $pattern, string $message): void
    {
        if (!preg_match($pattern, (string)($data[$key] ?? ''))) {
            throw new PluginException($message);
        }
    }

    public static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new PluginException('插件文件路径不合法');
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, "\0")) {
                throw new PluginException('插件文件路径包含危险片段');
            }
        }
        return implode('/', $parts);
    }

    private static function matchesVersionConstraint(string $current, string $constraint): bool
    {
        $parts = preg_split('/\s+/', trim($constraint)) ?: [];
        if ($parts === []) {
            throw new PluginException('版本约束不能为空');
        }
        foreach ($parts as $part) {
            if (!preg_match('/^(>=|<=|>|<|==|=)?([0-9]+(?:\.[0-9]+){1,2})$/', $part, $matches)) {
                throw new PluginException('版本约束格式不受支持');
            }
            $operator = $matches[1] !== '' ? $matches[1] : '==';
            if (!version_compare($current, $matches[2], $operator)) {
                return false;
            }
        }
        return true;
    }
}
