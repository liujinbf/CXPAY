<?php

declare(strict_types=1);

namespace app\payment;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\PaymentDriverInterface;

/**
 * 个人固定收款码挂机助手驱动基类。
 *
 * 手机挂机助手统一极简配置：商户仅需提供收款二维码内容，监控设备ID与HMAC密钥全自动生成与免配置同步。
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
                    'placeholder' => '请粘贴收款码解析链接或直接点击上方按钮上传收款码图片',
                ],
                [
                    'name' => 'device_id',
                    'title' => '绑定的监控设备 ID（已自动生成，免手动填写）',
                    'type' => 'string',
                    'required' => false,
                ],
                [
                    'name' => 'notify_secret',
                    'title' => '监控端 HMAC 推送密钥（已自动生成，免手动填写）',
                    'type' => 'password',
                    'required' => false,
                ],
            ],
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        $qrField = $this->qrField();
        $qr = trim((string)($config[$qrField] ?? ''));
        $isDataUrl = str_starts_with($qr, 'data:image/');
        $maxLen = $isDataUrl ? 2 * 1024 * 1024 : 8192;
        if ($qr === '' || strlen($qr) > $maxLen) {
            return ['code' => -1, 'msg' => $this->qrTitle() . '不能为空且不能超过 2MB 图片大小限制'];
        }
        if (!$isDataUrl && preg_match('/[\x00-\x1F\x7F]/', $qr)) {
            return ['code' => -1, 'msg' => $this->qrTitle() . '不能包含控制字符'];
        }

        $mchId = $channelRow['merchant_id'] ?? 1;
        $deviceId = trim((string)($config['device_id'] ?? ''));
        if ($deviceId === '') {
            $deviceId = "AND_MCH_{$mchId}";
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $deviceId)) {
            return ['code' => -1, 'msg' => '请绑定合法的监控设备 ID'];
        }

        $secret = trim((string)($config['notify_secret'] ?? ''));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
        }
        if (strlen($secret) < 32 || strlen($secret) > 128) {
            return ['code' => -1, 'msg' => '监控端 HMAC 推送密钥长度必须为32至128个字符'];
        }

        $config[$qrField] = $qr;
        $config['device_id'] = $deviceId;
        $config['notify_secret'] = $secret;

        return [
            'code' => 1,
            'msg' => '配置校验成功',
            'data' => $config,
        ];
    }
}
