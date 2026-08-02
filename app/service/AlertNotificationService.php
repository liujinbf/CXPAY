<?php

declare(strict_types=1);

namespace app\service;

use app\service\AlertChannelDriver\EmailDriver;
use app\service\AlertChannelDriver\WxWorkDriver;
use app\service\AlertChannelDriver\WebhookDriver;
use Illuminate\Database\Capsule\Manager as DB;
use support\Authcode;

/**
 * 平台告警通知分发服务
 *
 * 负责：
 *   1. 读取 cx_config 中的通知配置
 *   2. 根据事件类型判断是否需要发送
 *   3. 将通知任务推入 Redis 队列（cx:alert_notify_queue）异步发送
 *   4. 提供 processQueue() 供定时器消费
 *
 * 事件类型（event）：
 *   - admin_login       管理员登录成功
 *   - merchant_login    商户登录成功
 *   - order_paid        订单核销成功（账单到账）
 *   - channel_offline   通道掉线
 *   - order_timeout     订单超时关闭
 *
 * 接收方（target）：
 *   - admin             管理员通知（alert_admin_*）
 *   - merchant:{pid}    某商户通知（alert_merchant_{pid}_*）
 */
class AlertNotificationService
{
    private const QUEUE_KEY     = 'cx:alert_notify_queue';
    private const MAX_RETRIES   = 2;
    private const CONFIG_PREFIX = 'alert_';

    private Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    // ───────────────────────────── 对外触发接口 ─────────────────────────────

    /**
     * 分发管理员通知
     *
     * @param string $event  事件名（admin_login / channel_offline / order_paid / order_timeout）
     * @param array  $extra  额外上下文（如 ip, channel_name, trade_no, amount 等）
     */
    public function dispatchAdmin(string $event, array $extra = []): void
    {
        $this->dispatch('admin', $event, $extra);
    }

    /**
     * 分发商户通知
     *
     * @param string $merchantPid 商户 PID
     * @param string $event       事件名（merchant_login / order_paid）
     * @param array  $extra       额外上下文
     */
    public function dispatchMerchant(string $merchantPid, string $event, array $extra = []): void
    {
        $this->dispatch('merchant:' . $merchantPid, $event, $extra);
    }

    // ──────────────────────────── 队列消费 ────────────────────────────────

    /**
     * 由定时进程周期调用，消费 Redis 队列中的待发任务
     */
    public function processQueue(): void
    {
        try {
            $redis = \Webman\Redis\Client::connection();
        } catch (\Throwable) {
            return;
        }

        $processed = 0;
        $maxBatch  = 30;

        while ($processed < $maxBatch) {
            $item = $redis->lpop(self::QUEUE_KEY);
            if (!$item) {
                break;
            }
            $task = json_decode($item, true);
            if (empty($task['target']) || empty($task['event'])) {
                $processed++;
                continue;
            }

            // 退避延迟
            $nextAt = (int)($task['next_at'] ?? 0);
            if ($nextAt > time()) {
                $redis->rpush(self::QUEUE_KEY, $item);
                $processed++;
                continue;
            }

            $ok = $this->sendTask($task);
            if (!$ok) {
                $retries = (int)($task['retries'] ?? 0);
                if ($retries < self::MAX_RETRIES) {
                    $task['retries'] = $retries + 1;
                    $task['next_at'] = time() + 10 * (2 ** $retries); // 10s / 20s
                    $redis->rpush(self::QUEUE_KEY, json_encode($task, JSON_UNESCAPED_UNICODE));
                }
            }
            $processed++;
        }
    }

    // ───────────────────────────── 内部实现 ─────────────────────────────────

    private function dispatch(string $target, string $event, array $extra): void
    {
        // 检查全局开关
        $globalEnabled = $this->getConfig('enabled', 'admin') === '1';
        if (!$globalEnabled) {
            return;
        }

        // 检查事件开关
        $eventsJson = $this->getConfig('events', $this->configScope($target));
        $events     = is_string($eventsJson) ? (json_decode($eventsJson, true) ?? []) : [];
        if (!($events[$event] ?? false)) {
            return;
        }

        $task = [
            'target'    => $target,
            'event'     => $event,
            'extra'     => $extra,
            'created_at'=> time(),
            'retries'   => 0,
            'next_at'   => time(),
        ];

        try {
            $redis = \Webman\Redis\Client::connection();
            $redis->rpush(self::QUEUE_KEY, json_encode($task, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // Redis 不可用时同步发送（降级）
            error_log('[Alert] Redis 不可用，同步发送: ' . $e->getMessage());
            $this->sendTask($task);
        }
    }

    private function sendTask(array $task): bool
    {
        $target = (string)($task['target'] ?? '');
        $event  = (string)($task['event'] ?? '');
        $extra  = (array)($task['extra'] ?? []);

        [$subject, $body] = $this->buildMessage($event, $extra);
        $scope = $this->configScope($target);

        $sent = false;

        // Email
        $emailCfg = $this->getJsonConfig('email_config', $scope);
        if (!empty($emailCfg['enabled'])) {
            $emailCfg = $this->decryptSensitive($emailCfg, ['password']);
            $sent = (new EmailDriver())->send($subject, $body, $emailCfg) || $sent;
        }

        // 企业微信 Webhook
        $wxCfg = $this->getJsonConfig('wxwork_config', $scope);
        if (!empty($wxCfg['enabled'])) {
            $sent = (new WxWorkDriver())->send($subject, $body, $wxCfg) || $sent;
        }

        // 通用 Webhook
        $hookCfg = $this->getJsonConfig('webhook_config', $scope);
        if (!empty($hookCfg['enabled'])) {
            $sent = (new WebhookDriver())->send($subject, $body, $hookCfg) || $sent;
        }

        return $sent;
    }

    /** 将事件 + 上下文翻译为可读标题和正文 (支持自定义模板与变量替换) */
    private function buildMessage(string $event, array $extra): array
    {
        $siteName = $this->getSiteName();
        $now      = date('Y-m-d H:i:s');

        // 默认预设消息结构
        $defaults = [
            'admin_login' => [
                'title' => "【{$siteName}】管理员登录通知",
                'body'  => "管理员账号已成功登录。\n\nIP 地址：" . ($extra['ip'] ?? '未知') . "\n时间：{$now}",
            ],
            'merchant_login' => [
                'title' => "【{$siteName}】商户登录通知",
                'body'  => "商户 PID：" . ($extra['pid'] ?? '未知') . " 已登录控制台。\n\nIP 地址：" . ($extra['ip'] ?? '未知') . "\n时间：{$now}",
            ],
            'order_paid' => [
                'title' => "【{$siteName}】账单到账通知 ¥" . ($extra['amount'] ?? '0.00'),
                'body'  => "订单已成功核销到账！\n\n平台流水号：" . ($extra['trade_no'] ?? '-') . ($extra['pid'] ?? '' ? "\n商户 PID：" . $extra['pid'] : '') . "\n到账金额：¥" . ($extra['amount'] ?? '0.00') . "\n时间：{$now}",
            ],
            'channel_offline' => [
                'title' => "【{$siteName}】⚠️ 通道掉线告警",
                'body'  => "检测到支付通道已掉线，请及时处理！\n\n通道名称：" . ($extra['channel_title'] ?? '未知') . "\n通道类型：" . ($extra['c_type'] ?? '未知') . "\n时间：{$now}",
            ],
            'order_timeout' => [
                'title' => "【{$siteName}】订单超时关闭通知",
                'body'  => "订单超时未支付已自动关闭。\n\n平台流水号：" . ($extra['trade_no'] ?? '-') . "\n金额：¥" . ($extra['amount'] ?? '0.00') . "\n时间：{$now}",
            ],
            'low_balance' => [
                'title' => "【{$siteName}】⚠️ 服务费余额不足预警",
                'body'  => "您的商户服务费余额已低于预警阈值！\n\n当前服务费余额：¥" . ($extra['balance'] ?? '0.00') . "\n预警触发线：¥" . ($extra['threshold'] ?? '0.00') . "\n请及时充值，以免影响订单轮询与正常收款。\n时间：{$now}",
            ],
        ];

        // 尝试读取全局自定义模板
        $customTmpls = $this->readConfig('admin')['custom_templates'] ?? [];
        $tmpl = $customTmpls[$event] ?? null;

        $titleTmpl = !empty($tmpl['title']) ? $tmpl['title'] : ($defaults[$event]['title'] ?? "【{$siteName}】系统通知");
        $bodyTmpl  = !empty($tmpl['body'])  ? $tmpl['body']  : ($defaults[$event]['body']  ?? "事件：{$event}\n时间：{$now}");

        // 变量映射表
        $vars = [
            '{site_name}'     => $siteName,
            '{time}'          => $now,
            '{ip}'            => (string)($extra['ip'] ?? '127.0.0.1'),
            '{pid}'           => (string)($extra['pid'] ?? '-'),
            '{trade_no}'      => (string)($extra['trade_no'] ?? '-'),
            '{amount}'        => (string)($extra['amount'] ?? '0.00'),
            '{channel_title}' => (string)($extra['channel_title'] ?? $extra['channel_name'] ?? '未知通道'),
            '{balance}'       => (string)($extra['balance'] ?? '0.00'),
            '{threshold}'     => (string)($extra['threshold'] ?? '0.00'),
        ];

        $title = strtr($titleTmpl, $vars);
        $body  = strtr($bodyTmpl, $vars);

        return [$title, $body];
    }

    // ─────────────────────────── 配置读取工具 ───────────────────────────────

    /** target -> scope 前缀 */
    private function configScope(string $target): string
    {
        if ($target === 'admin') {
            return 'admin';
        }
        // merchant:{pid} → merchant_{pid}
        $pid = str_replace('merchant:', '', $target);
        return 'merchant_' . preg_replace('/[^A-Za-z0-9_]/', '_', $pid);
    }

    private function getConfig(string $key, string $scope): string
    {
        $row = DB::table('cx_config')
            ->where('name', self::CONFIG_PREFIX . $scope . '_' . $key)
            ->first();
        return $row ? (string)$row->value : '';
    }

    private function getJsonConfig(string $key, string $scope): array
    {
        $raw = $this->getConfig($key, $scope);
        return $raw !== '' ? (json_decode($raw, true) ?? []) : [];
    }

    private function decryptSensitive(array $config, array $fields): array
    {
        foreach ($fields as $f) {
            if (isset($config[$f]) && is_string($config[$f]) && $config[$f] !== '') {
                $config[$f] = $this->authcode->decryptStored($config[$f]);
            }
        }
        return $config;
    }

    private function getSiteName(): string
    {
        try {
            $row = DB::table('cx_config')->where('name', 'site_name')->first();
            return $row ? (string)$row->value : 'CXPAY';
        } catch (\Throwable) {
            return 'CXPAY';
        }
    }

    // ──────────────── 公开：保存配置（管理员/商户后台调用）──────────────────

    /**
     * 保存通知配置（管理员调用）
     * @return array{code:int, msg:string}
     */
    public function saveAdminConfig(array $data): array
    {
        return $this->saveConfig('admin', $data);
    }

    /**
     * 保存商户自己的通知配置
     * @return array{code:int, msg:string}
     */
    public function saveMerchantConfig(string $pid, array $data): array
    {
        return $this->saveConfig('merchant_' . preg_replace('/[^A-Za-z0-9_]/', '_', $pid), $data);
    }

    /**
     * 读取通知配置（管理员）
     */
    public function getAdminConfig(): array
    {
        return $this->readConfig('admin');
    }

    /**
     * 读取通知配置（商户）
     */
    public function getMerchantConfig(string $pid): array
    {
        return $this->readConfig('merchant_' . preg_replace('/[^A-Za-z0-9_]/', '_', $pid));
    }

    /** 发送测试通知 */
    public function sendTest(string $scope, string $channel): bool
    {
        $siteName = $this->getSiteName();
        $subject  = "【{$siteName}】测试通知";
        $body     = "这是一条测试消息，说明通知渠道配置正确。\n\n时间：" . date('Y-m-d H:i:s');

        $cfg = $this->getJsonConfig($channel . '_config', $scope);
        if (empty($cfg)) {
            return false;
        }
        return match ($channel) {
            'email'   => (new EmailDriver())->send($subject, $body, $this->decryptSensitive($cfg, ['password'])),
            'wxwork'  => (new WxWorkDriver())->send($subject, $body, $cfg),
            'webhook' => (new WebhookDriver())->send($subject, $body, $cfg),
            default   => false,
        };
    }

    private function saveConfig(string $scope, array $data): array
    {
        // 全局开关
        if (array_key_exists('enabled', $data)) {
            $this->upsertConfig($scope . '_enabled', $data['enabled'] ? '1' : '0', '告警通知全局开关');
        }

        // 事件开关
        if (array_key_exists('events', $data) && is_array($data['events'])) {
            $allowed = ['admin_login', 'merchant_login', 'order_paid', 'channel_offline', 'order_timeout', 'low_balance'];
            $events  = [];
            foreach ($allowed as $ev) {
                $events[$ev] = (bool)($data['events'][$ev] ?? false);
            }
            $this->upsertConfig($scope . '_events', json_encode($events, JSON_UNESCAPED_UNICODE), '事件开关');
        }

        // 低余额预警阈值
        if (array_key_exists('low_balance_threshold', $data)) {
            $val = max(0, (float)$data['low_balance_threshold']);
            $this->upsertConfig($scope . '_low_balance_threshold', (string)$val, '服务费余额低额预警阈值');
        }

        // 邮件配置
        if (array_key_exists('email_config', $data) && is_array($data['email_config'])) {
            $emailCfg = $this->validateEmailConfig($data['email_config']);
            if ($emailCfg === null) {
                return ['code' => -1, 'msg' => '邮件配置格式不合法'];
            }
            // 加密密码
            if (!empty($emailCfg['password'])) {
                $emailCfg['password'] = $this->authcode->encryptForStorage($emailCfg['password']);
            }
            $this->upsertConfig($scope . '_email_config', json_encode($emailCfg, JSON_UNESCAPED_UNICODE), '邮件通知配置');
        }

        // 企业微信配置
        if (array_key_exists('wxwork_config', $data) && is_array($data['wxwork_config'])) {
            $wxCfg = $this->validateSimpleWebhookConfig($data['wxwork_config'], 'webhook_url');
            if ($wxCfg === null) {
                return ['code' => -1, 'msg' => '企业微信 Webhook URL 格式不合法'];
            }
            $this->upsertConfig($scope . '_wxwork_config', json_encode($wxCfg, JSON_UNESCAPED_UNICODE), '企业微信通知配置');
        }

        // 通用 Webhook 配置
        if (array_key_exists('webhook_config', $data) && is_array($data['webhook_config'])) {
            $hookCfg = $this->validateSimpleWebhookConfig($data['webhook_config'], 'url');
            if ($hookCfg === null) {
                return ['code' => -1, 'msg' => '通用 Webhook URL 格式不合法'];
            }
            $this->upsertConfig($scope . '_webhook_config', json_encode($hookCfg, JSON_UNESCAPED_UNICODE), '通用 Webhook 通知配置');
        }

        // 自定义消息模板配置
        if (array_key_exists('custom_templates', $data) && is_array($data['custom_templates'])) {
            $this->upsertConfig($scope . '_custom_templates', json_encode($data['custom_templates'], JSON_UNESCAPED_UNICODE), '自定义消息模板配置');
        }

        return ['code' => 1, 'msg' => '通知与模板配置已成功保存！'];
    }

    private function readConfig(string $scope): array
    {
        $prefix  = self::CONFIG_PREFIX . $scope . '_';
        $rows    = DB::table('cx_config')
            ->where('name', 'like', $prefix . '%')
            ->get()
            ->pluck('value', 'name');

        $result = [
            'enabled'               => ($rows[$prefix . 'enabled'] ?? '0') === '1',
            'low_balance_threshold' => (float)($rows[$prefix . 'low_balance_threshold'] ?? 10.00),
            'events'                => json_decode((string)($rows[$prefix . 'events'] ?? '{}'), true) ?? [],
            'email_config'          => [],
            'wxwork_config'         => [],
            'webhook_config'        => [],
            'custom_templates'      => json_decode((string)($rows[$prefix . 'custom_templates'] ?? '{}'), true) ?? [],
        ];

        // 邮件配置脱敏返回（不回显密码）
        $emailRaw = json_decode((string)($rows[$prefix . 'email_config'] ?? '{}'), true) ?? [];
        if (!empty($emailRaw)) {
            $emailRaw['password'] = $emailRaw['password'] !== '' ? '••••••••' : '';
            $result['email_config'] = $emailRaw;
        }

        $result['wxwork_config']  = json_decode((string)($rows[$prefix . 'wxwork_config'] ?? '{}'), true) ?? [];
        $result['webhook_config'] = json_decode((string)($rows[$prefix . 'webhook_config'] ?? '{}'), true) ?? [];

        return $result;
    }

    private function upsertConfig(string $key, string $value, string $title): void
    {
        $fullKey = self::CONFIG_PREFIX . $key;
        $exists  = DB::table('cx_config')->where('name', $fullKey)->exists();
        if ($exists) {
            DB::table('cx_config')->where('name', $fullKey)->update(['value' => $value]);
        } else {
            DB::table('cx_config')->insert(['name' => $fullKey, 'value' => $value, 'title' => $title]);
        }
    }

    private function validateEmailConfig(array $cfg): ?array
    {
        if (empty($cfg['host'])) {
            return null;
        }
        $toAddrs = array_filter(array_map('trim', (array)($cfg['to_addrs'] ?? [])));
        foreach ($toAddrs as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                return null;
            }
        }
        return [
            'enabled'     => (bool)($cfg['enabled'] ?? false),
            'host'        => trim((string)$cfg['host']),
            'port'        => (int)($cfg['port'] ?? 465),
            'username'    => trim((string)($cfg['username'] ?? '')),
            'password'    => trim((string)($cfg['password'] ?? '')),
            'encryption'  => in_array($cfg['encryption'] ?? 'ssl', ['ssl', 'tls', ''], true)
                ? ($cfg['encryption'] ?? 'ssl') : 'ssl',
            'from_name'   => mb_substr(trim((string)($cfg['from_name'] ?? 'CXPAY 通知')), 0, 50),
            'from_addr'   => trim((string)($cfg['from_addr'] ?? $cfg['username'] ?? '')),
            'to_addrs'    => array_values($toAddrs),
        ];
    }

    private function validateSimpleWebhookConfig(array $cfg, string $urlKey): ?array
    {
        $url = trim((string)($cfg[$urlKey] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $result            = $cfg;
        $result[$urlKey]   = $url;
        $result['enabled'] = (bool)($cfg['enabled'] ?? false);
        return $result;
    }
}
