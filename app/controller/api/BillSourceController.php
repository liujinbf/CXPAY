<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\BillSourceEvent;
use app\model\Channel;
use app\payment\PaymentManager;
use InvalidArgumentException;
use support\Authcode;
use support\BillSourceProtocol;
use support\IpWhitelist;
use support\Request;
use support\Response;
use Throwable;

/**
 * 授权账单源：采集端写入真实账单，PC 监控端按单调游标拉取。
 */
class BillSourceController
{
    public function ingest(Request $request): Response
    {
        try {
            $params = $request->post();
            $channelId = (int)($params['channel_id'] ?? 0);
            [$channel, $config] = $this->authorizedChannel(
                $channelId,
                (string)$request->header('authorization'),
                'ingest_secret'
            );
            if (!IpWhitelist::allows($request->getRemoteIp(), (string)($config['ingest_ip_white'] ?? ''))) {
                return $this->fail('当前采集端 IP 不在白名单中', 403);
            }

            $bill = BillSourceProtocol::normalizeBill($params, (string)$channel->pay_category);
            $boundCollectorId = trim((string)($config['collector_id'] ?? ''));
            if ($boundCollectorId === '' || !hash_equals($boundCollectorId, $bill['collector_id'])) {
                return $this->fail('采集端未绑定或 collector_id 不匹配', 403);
            }

            $event = BillSourceEvent::firstOrCreate(
                [
                    'channel_id' => $channelId,
                    'source_bill_id' => $bill['source_bill_id'],
                ],
                [
                    'pay_type' => $bill['pay_type'],
                    'money' => $bill['money'],
                    'occurred_at' => $bill['occurred_at'],
                    'remark' => $bill['remark'],
                    'collector_id' => $bill['collector_id'],
                    'create_time' => time(),
                ]
            );

            if (!$event->wasRecentlyCreated
                && (!$this->sameMoney((string)$event->money, $bill['money'])
                    || (int)$event->occurred_at !== $bill['occurred_at']
                    || !hash_equals((string)$event->pay_type, $bill['pay_type'])
                    || !hash_equals((string)$event->collector_id, $bill['collector_id'])
                    || !hash_equals((string)$event->remark, $bill['remark']))) {
                return $this->fail('相同 source_bill_id 的账单内容发生冲突', 409);
            }

            return json([
                'code' => 1,
                'message' => $event->wasRecentlyCreated ? '账单已写入' : '重复账单已幂等接收',
                'data' => [
                    'event_id' => (int)$event->id,
                    'duplicate' => !$event->wasRecentlyCreated,
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (BillSourceAuthorizationException $e) {
            return $this->fail($e->getMessage(), 403);
        } catch (Throwable $e) {
            error_log('[BillSourceController] 账单写入失败: ' . $e->getMessage());
            return $this->fail('账单源写入失败', 500);
        }
    }

    public function poll(Request $request): Response
    {
        try {
            $channelId = (int)$request->get('channel_id', 0);
            [$channel, $config] = $this->authorizedChannel(
                $channelId,
                (string)$request->header('authorization'),
                'feed_token'
            );
            $deviceId = trim((string)$request->get('device_id', ''));
            $payType = trim((string)$request->get('pay_type', ''));
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $deviceId)
                || !hash_equals((string)($config['device_id'] ?? ''), $deviceId)) {
                return $this->fail('PC 设备未绑定到当前通道', 403);
            }
            if (!hash_equals((string)$channel->pay_category, $payType)) {
                return $this->fail('支付类型与当前通道不一致', 403);
            }

            $cursor = BillSourceProtocol::cursor($request->get('cursor'));
            $limit = max(1, min(BillSourceProtocol::MAX_BATCH_SIZE, (int)$request->get('limit', 50)));
            $events = BillSourceEvent::where('channel_id', $channelId)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            $nextCursor = $cursor;
            $data = [];
            foreach ($events as $event) {
                $nextCursor = (int)$event->id;
                $data[] = [
                    'source_bill_id' => (string)$event->source_bill_id,
                    'money' => number_format((float)$event->money, 2, '.', ''),
                    'occurred_at' => (int)$event->occurred_at,
                    'remark' => (string)$event->remark,
                    'pay_type' => (string)$event->pay_type,
                ];
            }

            return json([
                'code' => 1,
                'message' => 'ok',
                'cursor' => (string)$nextCursor,
                'data' => $data,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (BillSourceAuthorizationException $e) {
            return $this->fail($e->getMessage(), 403);
        } catch (Throwable $e) {
            error_log('[BillSourceController] 账单拉取失败: ' . $e->getMessage());
            return $this->fail('账单源拉取失败', 500);
        }
    }

    private function authorizedChannel(int $channelId, string $authorization, string $tokenName): array
    {
        $token = BillSourceProtocol::bearerToken($authorization);
        if ($channelId <= 0 || $token === null) {
            throw new InvalidArgumentException('通道ID或 Bearer Token 格式不正确');
        }
        $channel = Channel::where('id', $channelId)->where('status', 1)->first();
        if (!$channel || !PaymentManager::has((string)$channel->c_type)
            || !PaymentManager::requiresHeartbeat((string)$channel->c_type)) {
            throw new BillSourceAuthorizationException('通道不存在、未启用或不支持监控助手');
        }
        $config = $this->decryptConfig((string)$channel->config);
        $expected = trim((string)($config[$tokenName] ?? ''));
        if (strlen($expected) < 32 || !hash_equals($expected, $token)) {
            throw new BillSourceAuthorizationException('账单源访问令牌无效或尚未配置');
        }
        return [$channel, $config];
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

    private function sameMoney(string $left, string $right): bool
    {
        return hash_equals(number_format((float)$left, 2, '.', ''), number_format((float)$right, 2, '.', ''));
    }

    private function fail(string $message, int $status): Response
    {
        return json(['code' => -1, 'message' => $message])->withStatus($status);
    }
}

final class BillSourceAuthorizationException extends \RuntimeException
{
}
