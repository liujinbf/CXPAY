<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Channel;
use app\payment\PaymentManager;
use app\payment\Contracts\OperationsStatusInterface;
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
        $services = [];
        $channels = [];
        $warnings = [];
        foreach (Channel::orderBy('id')->get() as $channel) {
            try {
                $driver = PaymentManager::make((string)$channel->c_type);
                if (!$driver instanceof OperationsStatusInterface) {
                    continue;
                }
                $config = $this->channelConfig($channel);
                $cacheKey = hash('sha256', get_class($driver) . '|'
                    . (string)($config['monitor_base_url'] ?? '') . '|'
                    . (string)($config['client_id'] ?? ''));
                if (!isset($services[$cacheKey])) {
                    $services[$cacheKey] = $driver->operationsStatus($config);
                }
                $service = $services[$cacheKey];
                $accountId = (string)($config['account_id'] ?? '');
                $account = null;
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
                    'channel_id' => (int)$channel->id,
                    'pay_type' => (string)$channel->pay_category,
                    'remark' => (string)$channel->remark,
                    'enabled' => (int)$channel->status === 1,
                    'account_id' => $accountId,
                    'account' => $account,
                    'collector' => $collector,
                    'metrics' => is_array($account) ? (array)($account['metrics'] ?? []) : [],
                    'server_time' => (int)($service['server_time'] ?? 0),
                ];
            } catch (Throwable $e) {
                $message = '通道 #' . (int)$channel->id . '：' . $e->getMessage();
                $warnings[] = $message;
                Log::warning('读取微信云监控运维状态失败', [
                    'channel_id' => (int)$channel->id,
                    'error' => $e->getMessage(),
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
