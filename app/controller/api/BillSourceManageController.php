<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\BillSourceEvent;
use app\model\Channel;
use app\model\Merchant;
use app\payment\PaymentManager;
use Illuminate\Database\Capsule\Manager as DB;
use support\Authcode;
use support\IpWhitelist;
use support\Request;
use support\Response;
use Throwable;

/** 管理员/商户生成账单源令牌并查看队列状态与通道列表。 */
class BillSourceManageController
{
    /**
     * 管理端：获取所有支持挂机助手/账单源采集的通道列表及状态概览
     */
    public function adminList(Request $request): Response
    {
        try {
            $keyword   = trim((string)$request->get('keyword', ''));
            $payType   = trim((string)$request->get('pay_type', ''));
            $online    = $request->get('online'); // 1 | 0 | null
            $merchantPid = trim((string)$request->get('merchant_pid', ''));

            PaymentManager::flush();

            $query = Channel::query();

            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%")
                      ->orWhere('id', $keyword)
                      ->orWhere('c_type', 'like', "%{$keyword}%");
                });
            }

            if ($payType !== '') {
                $query->where('pay_category', $payType);
            }

            if ($online !== null && $online !== '') {
                $query->where('online_status', (int)$online);
            }

            if ($merchantPid !== '') {
                $m = Merchant::where('pid', $merchantPid)->first();
                if ($m) {
                    $query->where('merchant_id', $m->id);
                } else {
                    $query->where('merchant_id', -1);
                }
            }

            $channels = $query->orderBy('id', 'desc')->get();

            // 获取所有通道的事件统计
            $eventCounts = BillSourceEvent::groupBy('channel_id')
                ->selectRaw('channel_id, count(*) as count')
                ->pluck('count', 'channel_id')
                ->toArray();

            // 预加载所有商户信息
            $merchantIds = $channels->pluck('merchant_id')->unique()->toArray();
            $merchants = Merchant::whereIn('id', $merchantIds)->get()->keyBy('id');

            $list = [];
            $totalEvents = 0;
            $onlineCount = 0;

            foreach ($channels as $channel) {
                $cType = (string)$channel->c_type;
                $requiresHeartbeat = PaymentManager::has($cType) && PaymentManager::requiresHeartbeat($cType);
                
                // 如果不是显式的 requiresHeartbeat，但属于个人免挂助手/APP助手驱动，也纳入展示
                $isMonitorCandidate = $requiresHeartbeat || str_contains($cType, 'appasst') || str_contains($cType, 'pad') || str_contains($cType, 'dy') || str_contains($cType, 'pc');

                $config = $this->decryptConfig((string)$channel->config);
                $eventCount = (int)($eventCounts[$channel->id] ?? 0);
                $totalEvents += $eventCount;

                $isOnline = (int)($channel->online_status ?? 0) === 1;
                if ($isOnline) {
                    $onlineCount++;
                }

                $merchant = $merchants->get($channel->merchant_id);

                // 在线时长格式化
                $onlineDurationFormat = '--';
                if ($isOnline && !empty($channel->online_since)) {
                    $durationSec = max(0, time() - (int)$channel->online_since);
                    $h = floor($durationSec / 3600);
                    $m = floor(($durationSec % 3600) / 60);
                    $onlineDurationFormat = $h > 0 ? "{$h}小时{$m}分" : "{$m}分钟";
                } elseif (!$isOnline && !empty($channel->last_online_duration)) {
                    $durationSec = (int)$channel->last_online_duration;
                    $h = floor($durationSec / 3600);
                    $m = floor(($durationSec % 3600) / 60);
                    $onlineDurationFormat = "曾在线 " . ($h > 0 ? "{$h}小时{$m}分" : "{$m}分钟");
                }

                $list[] = [
                    'id'                      => (int)$channel->id,
                    'name'                    => (string)$channel->name,
                    'c_type'                  => $cType,
                    'pay_type'                => (string)$channel->pay_category,
                    'merchant_id'             => (int)$channel->merchant_id,
                    'merchant_pid'            => $merchant ? (string)$merchant->pid : '--',
                    'merchant_name'           => $merchant ? (string)$merchant->name : '未知商户',
                    'status'                  => (int)$channel->status,
                    'online_status'           => (int)$channel->online_status,
                    'online_duration_format'  => $onlineDurationFormat,
                    'last_heartbeat'          => !empty($channel->last_heartbeat) ? date('Y-m-d H:i:s', (int)$channel->last_heartbeat) : '--',
                    'device_id'               => (string)($config['device_id'] ?? ''),
                    'collector_id'            => (string)($config['collector_id'] ?? ''),
                    'ingest_ip_white'         => (string)($config['ingest_ip_white'] ?? ''),
                    'ingest_token_configured' => strlen((string)($config['ingest_secret'] ?? '')) >= 32,
                    'feed_token_configured'   => strlen((string)($config['feed_token'] ?? '')) >= 32,
                    'event_count'             => $eventCount,
                    'requires_heartbeat'      => $isMonitorCandidate,
                ];
            }

            return json([
                'code' => 1,
                'data' => [
                    'list' => $list,
                    'stats' => [
                        'total_channels' => count($channels),
                        'online_channels' => $onlineCount,
                        'total_events' => $totalEvents,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            error_log('[BillSourceManageController] 通道列表获取失败: ' . $e->getMessage());
            return $this->fail('获取通道列表失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 管理端：获取指定通道的账单事件流水明细
     */
    public function adminEvents(Request $request): Response
    {
        try {
            $channelId = (int)$request->get('channel_id', 0);
            if ($channelId <= 0) {
                return $this->fail('无效的通道 ID', 400);
            }

            $page = max(1, (int)$request->get('page', 1));
            $pageSize = max(1, min(100, (int)$request->get('page_size', 20)));

            $query = BillSourceEvent::where('channel_id', $channelId);
            $total = $query->count();

            $events = $query->orderBy('id', 'desc')
                ->forPage($page, $pageSize)
                ->get()
                ->map(function ($event) {
                    return [
                        'id'             => (int)$event->id,
                        'channel_id'     => (int)$event->channel_id,
                        'source_bill_id' => (string)$event->source_bill_id,
                        'money'          => number_format((float)$event->money, 2, '.', ''),
                        'pay_type'       => (string)$event->pay_type,
                        'remark'         => (string)($event->remark ?? ''),
                        'collector_id'   => (string)($event->collector_id ?? ''),
                        'occurred_at'    => !empty($event->occurred_at) ? date('Y-m-d H:i:s', (int)$event->occurred_at) : '--',
                        'create_time'    => !empty($event->create_time) ? date('Y-m-d H:i:s', (int)$event->create_time) : '--',
                    ];
                });

            return json([
                'code' => 1,
                'data' => [
                    'list' => $events,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                ],
            ]);
        } catch (Throwable $e) {
            error_log('[BillSourceManageController] 事件列表获取失败: ' . $e->getMessage());
            return $this->fail('获取事件明细失败', 500);
        }
    }

    /**
     * 管理端：模拟上报一条测试账单（用于全链路联调联试）
     */
    public function adminTestIngest(Request $request): Response
    {
        try {
            $channelId = (int)$request->post('channel_id', 0);
            $money = number_format((float)$request->post('money', 0.01), 2, '.', '');
            $remark = trim((string)$request->post('remark', '管理员后台模拟账单'));

            $channel = Channel::find($channelId);
            if (!$channel) {
                return $this->fail('通道不存在', 404);
            }

            $sourceBillId = 'TEST_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $event = BillSourceEvent::create([
                'channel_id'     => $channelId,
                'source_bill_id' => $sourceBillId,
                'pay_type'       => (string)$channel->pay_category,
                'money'          => $money,
                'occurred_at'    => time(),
                'remark'         => $remark,
                'collector_id'   => 'ADMIN_SIMULATOR',
                'create_time'    => time(),
            ]);

            return json([
                'code' => 1,
                'msg' => "测试账单已写入事件队列，流水单号: {$sourceBillId}",
                'data' => [
                    'event_id' => (int)$event->id,
                    'source_bill_id' => $sourceBillId,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->fail('模拟写入账单失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 管理端：清空指定通道的账单事件队列
     */
    public function adminClearEvents(Request $request): Response
    {
        try {
            $channelId = (int)$request->post('channel_id', 0);
            if ($channelId <= 0) {
                return $this->fail('无效的通道 ID', 400);
            }

            $deleted = BillSourceEvent::where('channel_id', $channelId)->delete();

            return json([
                'code' => 1,
                'msg' => "已清空通道 #{$channelId} 的历史账单事件（共清理 {$deleted} 条）",
            ]);
        } catch (Throwable $e) {
            return $this->fail('清空队列失败: ' . $e->getMessage(), 500);
        }
    }

    public function adminStatus(Request $request): Response
    {
        return $this->status($request, null);
    }

    public function merchantStatus(Request $request): Response
    {
        return $this->status($request, $this->merchantId($request));
    }

    public function adminRotate(Request $request): Response
    {
        return $this->rotate($request, null);
    }

    public function merchantRotate(Request $request): Response
    {
        return $this->rotate($request, $this->merchantId($request));
    }

    private function status(Request $request, ?int $merchantId): Response
    {
        try {
            $channel = $this->findChannel((int)$request->get('id', 0), $merchantId);
            if (!$channel) {
                return $this->fail('通道不存在、无权访问或不支持监控助手', 404);
            }
            $config = $this->decryptConfig((string)$channel->config);
            $gatewayBase = rtrim((string)config('app.url', ''), '/');
            if (!filter_var($gatewayBase, FILTER_VALIDATE_URL)) {
                $gatewayBase = '';
            }

            return json([
                'code' => 1,
                'data' => [
                    'channel_id'              => (int)$channel->id,
                    'channel_name'            => (string)$channel->name,
                    'pay_type'                => (string)$channel->pay_category,
                    'device_id'               => (string)($config['device_id'] ?? ''),
                    'collector_id'            => (string)($config['collector_id'] ?? ''),
                    'ingest_ip_white'         => (string)($config['ingest_ip_white'] ?? ''),
                    'ingest_token_configured' => strlen((string)($config['ingest_secret'] ?? '')) >= 32,
                    'feed_token_configured'   => strlen((string)($config['feed_token'] ?? '')) >= 32,
                    'event_count'             => BillSourceEvent::where('channel_id', $channel->id)->count(),
                    'ingest_path'             => '/api/bill-source/ingest',
                    'feed_path'               => '/api/bill-source/poll',
                    'gateway_base'            => $gatewayBase,
                ],
            ]);
        } catch (Throwable $e) {
            error_log('[BillSourceManageController] 状态查询失败: ' . $e->getMessage());
            return $this->fail('账单源状态查询失败', 500);
        }
    }

    private function rotate(Request $request, ?int $merchantId): Response
    {
        $channelId = (int)$request->post('id', 0);
        $scope = trim((string)$request->post('scope', ''));
        if (!in_array($scope, ['ingest', 'feed'], true)) {
            return $this->fail('scope 只允许 ingest 或 feed', 400);
        }

        try {
            $result = DB::connection()->transaction(function () use ($request, $merchantId, $channelId, $scope): array {
                $query = Channel::where('id', $channelId);
                if ($merchantId !== null) {
                    $query->where('merchant_id', $merchantId);
                }
                $channel = $query->lockForUpdate()->first();
                if (!$channel) {
                    throw new \InvalidArgumentException('通道不存在或无权访问');
                }

                $config = $this->decryptConfig((string)$channel->config);
                if ($scope === 'ingest') {
                    $collectorId = trim((string)$request->post('collector_id', $config['collector_id'] ?? ''));
                    if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $collectorId)) {
                        throw new \InvalidArgumentException('生成写入令牌前必须设置合法的 collector_id');
                    }
                    $ipWhite = IpWhitelist::normalize((string)$request->post(
                        'ingest_ip_white',
                        $config['ingest_ip_white'] ?? ''
                    ));
                    if ($ipWhite === null) {
                        throw new \InvalidArgumentException('采集端 IP 白名单格式不合法');
                    }
                    $config['collector_id'] = $collectorId;
                    $config['ingest_ip_white'] = $ipWhite;
                }

                $tokenName = $scope === 'ingest' ? 'ingest_secret' : 'feed_token';
                $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
                $config[$tokenName] = $token;
                $channel->config = $this->encryptConfig($config);
                $channel->save();
                return [$channel, $token];
            });

            [$channel, $token] = $result;
            return json([
                'code' => 1,
                'message' => '令牌已轮换；旧令牌立即失效，请现在保存新令牌',
                'data' => [
                    'channel_id' => (int)$channel->id,
                    'scope' => $scope,
                    'token' => $token,
                    'path' => $scope === 'ingest' ? '/api/bill-source/ingest' : '/api/bill-source/poll',
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (Throwable $e) {
            error_log('[BillSourceManageController] 令牌轮换失败: ' . $e->getMessage());
            return $this->fail('账单源令牌生成失败', 500);
        }
    }

    private function findChannel(int $id, ?int $merchantId): ?Channel
    {
        $query = Channel::where('id', $id);
        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }
        return $query->first();
    }

    private function merchantId(Request $request): int
    {
        $merchant = $request->context['merchant'] ?? null;
        return $merchant instanceof Merchant ? (int)$merchant->id : -1;
    }

    private function decryptConfig(string $raw): array
    {
        $config = json_decode($raw, true) ?: [];
        $authcode = new Authcode();
        foreach ($config as $key => $value) {
            if (is_string($value) && $value !== '') {
                $config[$key] = $authcode->decryptStored($value);
            }
        }
        return $config;
    }

    private function encryptConfig(array $config): string
    {
        $authcode = new Authcode();
        foreach ($config as $key => $value) {
            if (is_string($value) && $value !== '') {
                $config[$key] = $authcode->encrypt($value);
            }
        }
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function fail(string $message, int $status): Response
    {
        return json(['code' => -1, 'message' => $message])->withStatus($status);
    }
}
