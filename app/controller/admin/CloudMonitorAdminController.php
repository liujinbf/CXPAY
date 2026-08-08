<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Channel;
use app\payment\PaymentManager;
use support\Authcode;
use support\Log;
use support\Response;
use Throwable;

final class CloudMonitorAdminController
{
    private Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    public function status(): Response
    {
        $services    = [];
        $channels    = [];
        $warnings    = [];
        $globalStart = microtime(true);
        // 单个云服务调用允许的最长耗时（秒）
        // 注：ProviderClient 内 Guzzle 已设 connect_timeout=3/timeout=5，此处作额外保护
        $perServiceTimeout = 6.0;

        foreach (Channel::whereIn('c_type', ['wxpay_cloud_adapter', 'alipay_scan_monitor'])->orderBy('id')->get() as $channel) {
            // 全局累计已超过 20 秒时，停止继续查询剩余通道
            if ((microtime(true) - $globalStart) > 20.0) {
                $warnings[] = '⏱ 全局超时（20s），剩余通道已跳过';
                break;
            }

            try {
                $config   = $this->channelConfig($channel);
                $cacheKey = hash('sha256', (string)($config['monitor_base_url'] ?? '') . '|'
                    . (string)($config['client_id'] ?? ''));

                if (!isset($services[$cacheKey])) {
                    $driver = PaymentManager::make((string)$channel->c_type);
                    if (!method_exists($driver, 'operationsStatus')) {
                        throw new \RuntimeException('已安装的云监控插件版本不支持运维状态接口，请升级插件');
                    }
                    $t0 = microtime(true);
                    $services[$cacheKey] = $driver->operationsStatus($config);
                    $elapsed = round((microtime(true) - $t0) * 1000);

                    // 记录慢响应警告（>3s）
                    if ($elapsed > 3000) {
                        $warnings[] = "⚠️ 云服务 {$cacheKey} 响应较慢（{$elapsed}ms）";
                    }
                }

                $service   = $services[$cacheKey];
                $accountId = (string)($config['account_id'] ?? '');
                $account   = null;
                foreach ((array)($service['accounts'] ?? []) as $candidate) {
                    if (hash_equals($accountId, (string)($candidate['id'] ?? ''))) {
                        $account = $candidate;
                        break;
                    }
                }
                $collector = null;
                if (is_array($account)) {
                    foreach ((array)($service['collectors'] ?? []) as $candidate) {
                        if (hash_equals((string)($account['collector_id'] ?? ''), (string)($candidate['id'] ?? ''))) {
                            $collector = $candidate;
                            break;
                        }
                    }
                }
                $channels[] = [
                    'channel_id'  => (int)$channel->id,
                    'pay_type'    => (string)$channel->pay_category,
                    'remark'      => (string)$channel->remark,
                    'enabled'     => (int)$channel->status === 1,
                    'account_id'  => $accountId,
                    'account'     => $account,
                    'collector'   => $collector,
                    'metrics'     => is_array($account) ? (array)($account['metrics'] ?? []) : [],
                    'server_time' => (int)($service['server_time'] ?? 0),
                ];
            } catch (Throwable $e) {
                $message  = '通道 #' . (int)$channel->id . '：' . $e->getMessage();
                $warnings[] = $message;
                Log::warning('读取微信云监控运维状态失败', [
                    'channel_id' => (int)$channel->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
        return json(['code' => 1, 'data' => ['channels' => $channels, 'warnings' => $warnings]]);
    }


    /** @return array<string, mixed> */
    private function channelConfig(Channel $channel): array
    {
        $config = [];
        foreach (json_decode((string)$channel->config, true) ?: [] as $key => $value) {
            $config[$key] = is_string($value) ? $this->authcode->decryptStored($value) : $value;
        }
        return $config;
    }
}
