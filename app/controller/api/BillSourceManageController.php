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

/** 管理员/商户生成账单源令牌并查看队列状态。 */
class BillSourceManageController
{
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
            return json([
                'code' => 1,
                'data' => [
                    'channel_id' => (int)$channel->id,
                    'pay_type' => (string)$channel->pay_category,
                    'device_id' => (string)($config['device_id'] ?? ''),
                    'collector_id' => (string)($config['collector_id'] ?? ''),
                    'ingest_ip_white' => (string)($config['ingest_ip_white'] ?? ''),
                    'ingest_token_configured' => strlen((string)($config['ingest_secret'] ?? '')) >= 32,
                    'feed_token_configured' => strlen((string)($config['feed_token'] ?? '')) >= 32,
                    'event_count' => BillSourceEvent::where('channel_id', $channel->id)->count(),
                    'ingest_path' => '/api/bill-source/ingest',
                    'feed_path' => '/api/bill-source/poll',
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
                if (!$channel || !PaymentManager::has((string)$channel->c_type)
                    || !PaymentManager::requiresHeartbeat((string)$channel->c_type)) {
                    throw new \InvalidArgumentException('通道不存在、无权访问或不支持监控助手');
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
        $channel = $query->first();
        if (!$channel || !PaymentManager::has((string)$channel->c_type)
            || !PaymentManager::requiresHeartbeat((string)$channel->c_type)) {
            return null;
        }
        return $channel;
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
