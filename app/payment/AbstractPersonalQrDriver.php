<?php

declare(strict_types=1);

namespace app\payment;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\PaymentDriverInterface;

/**
 * 个人固定收款码挂机助手驱动基类。
 *
 * 三种钱包共享相同的出码、设备绑定和安全校验，只在元数据与二维码字段上存在差异。
 */
abstract class AbstractPersonalQrDriver implements PaymentDriverInterface, MonitorableDriverInterface
{
    abstract protected function cType(): string;

    abstract protected function title(): string;

    abstract protected function description(): string;

    abstract protected function qrField(): string;

    abstract protected function qrTitle(): string;

    abstract protected function platform(): string;

    public function monitorMode(): string
    {
        return MonitorableDriverInterface::MODE_PUSH;
    }

    public function pay(array $params, array $config): array
    {
        $qr = trim((string)($config[$this->qrField()] ?? ''));

        return [
            'type' => 'qrcode',
            'trade_no' => (string)($params['trade_no'] ?? ''),
            'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
            'amount' => $params['money'] ?? '0.00',
            'pay_url' => $qr,
        ];
    }

    /** 助手账单只允许走 /api/appasst/push，通用上游回调始终拒绝。 */
    public function notify(array $params, array $config): array
    {
        return [
            'success' => false,
            'out_trade_no' => '',
            'trade_no' => '',
            'amount' => 0.0,
        ];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => false];
    }

    public function getMeta(): array
    {
        return [
            'name' => $this->cType(),
            'title' => $this->title(),
            'description' => $this->description(),
            'pay_category' => $this->platform(),
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs' => [
                [
                    'name' => $this->qrField(),
                    'title' => $this->qrTitle(),
                    'type' => 'string',
                    'required' => true,
                ],
                [
                    'name' => 'device_id',
                    'title' => '绑定的监控设备 ID',
                    'type' => 'string',
                    'required' => true,
                ],
                [
                    'name' => 'notify_secret',
                    'title' => '监控端 HMAC 推送密钥（32～128位）',
                    'type' => 'password',
                    'required' => true,
                ],
                [
                    'name' => 'collector_id',
                    'title' => '授权账单采集端 ID',
                    'type' => 'string',
                    'required' => false,
                ],
                [
                    'name' => 'ingest_secret',
                    'title' => '账单源写入令牌（通过令牌生成接口创建）',
                    'type' => 'password',
                    'required' => false,
                ],
                [
                    'name' => 'feed_token',
                    'title' => 'PC 账单源拉取令牌（通过令牌生成接口创建）',
                    'type' => 'password',
                    'required' => false,
                ],
                [
                    'name' => 'ingest_ip_white',
                    'title' => '采集端 IP 白名单（可选，逗号分隔）',
                    'type' => 'string',
                    'required' => false,
                ],
            ],
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        $qrField = $this->qrField();
        $qr = trim((string)($config[$qrField] ?? ''));
        if ($qr === '' || strlen($qr) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $qr)) {
            return ['code' => -1, 'msg' => $this->qrTitle() . '不能为空、不能含控制字符且最长4096字节'];
        }

        $deviceId = trim((string)($config['device_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $deviceId)) {
            return ['code' => -1, 'msg' => '请绑定合法的监控设备 ID'];
        }

        $secret = (string)($config['notify_secret'] ?? '');
        if (strlen($secret) < 32 || strlen($secret) > 128) {
            return ['code' => -1, 'msg' => '监控端 HMAC 推送密钥长度必须为32至128个字符'];
        }

        $collectorId = trim((string)($config['collector_id'] ?? ''));
        if ($collectorId !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $collectorId)) {
            return ['code' => -1, 'msg' => '授权账单采集端 ID 格式不合法'];
        }
        foreach (['ingest_secret', 'feed_token'] as $tokenName) {
            $token = trim((string)($config[$tokenName] ?? ''));
            if ($token !== '' && (strlen($token) < 32 || strlen($token) > 128)) {
                return ['code' => -1, 'msg' => $tokenName . ' 长度必须为32至128位'];
            }
        }
        $ipWhitelist = \support\IpWhitelist::normalize((string)($config['ingest_ip_white'] ?? ''));
        if ($ipWhitelist === null) {
            return ['code' => -1, 'msg' => '采集端 IP 白名单格式不合法'];
        }

        $config[$qrField] = $qr;
        $config['device_id'] = $deviceId;
        $config['notify_secret'] = $secret;
        if (array_key_exists('collector_id', $config)) {
            $config['collector_id'] = $collectorId;
        }
        if (array_key_exists('ingest_secret', $config)) {
            $config['ingest_secret'] = trim((string)$config['ingest_secret']);
        }
        if (array_key_exists('feed_token', $config)) {
            $config['feed_token'] = trim((string)$config['feed_token']);
        }
        if (array_key_exists('ingest_ip_white', $config)) {
            $config['ingest_ip_white'] = $ipWhitelist;
        }
        return $config;
    }
}
