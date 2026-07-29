<?php

declare(strict_types=1);

namespace app\payment\Drivers\WxpayOfficial;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 微信支付原生 V3 驱动
 */
class Driver implements PaymentDriverInterface
{
    public function pay(array $params, array $config): array
    {
        return [
            'type'         => 'qrcode',
            'trade_no'     => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount'       => $params['money'],
            'pay_url'      => 'weixin://wxpay/bizpayurl',
        ];
    }

    /**
     * 微信支付 V3 回调签名验证
     * 文档：https://pay.weixin.qq.com/wiki/doc/apiv3/wechatpay/wechatpay4_1.shtml
     *
     * V3 回调通过 HTTP Header 中的 Wechatpay-Signature 做 RSA-OAEP 验签。
     * 由于 $params 只能获取 POST body 字段，此处做降级处理：
     * 1. 若 config 中配置了 v3_key（API v3 密钥），用 AES-256-GCM 解密 resource 报文
     * 2. 从解密后的 JSON 中取 out_trade_no / transaction_id / amount
     */
    public function notify(array $params, array $config): array
    {
        $v3Key = $config['v3_key'] ?? '';

        // 微信 V3 的回调 body 是 JSON 格式，$params 是已解析的数组
        // resource 字段包含加密的支付结果
        if (!empty($params['resource']) && !empty($v3Key)) {
            $resource = is_array($params['resource'])
                ? $params['resource']
                : (json_decode($params['resource'], true) ?: []);

            $decrypted = $this->decryptResource($resource, $v3Key);
            if ($decrypted !== null) {
                $isPaid = ($decrypted['trade_state'] ?? '') === 'SUCCESS';
                return [
                    'success'      => $isPaid,
                    'out_trade_no' => $decrypted['out_trade_no'] ?? '',
                    'trade_no'     => $decrypted['transaction_id'] ?? '',
                    'amount'       => (float)(($decrypted['amount']['total'] ?? 0)) / 100,
                ];
            }
        }

        return [
            'success'      => false,
            'out_trade_no' => '',
            'trade_no'     => '',
            'amount'       => 0.0,
        ];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => false];
    }

    public function getMeta(): array
    {
        return [
            'name'        => 'wxpay_official',
            'title'       => '微信支付原生 V3 驱动',
            'description' => '尚未完成微信 Native 下单与平台证书回调验签，当前不可启用',
            'available'   => false,
            'inputs'      => [
                ['name' => 'mch_id',    'title' => '微信商户号 (MCHID)',   'type' => 'string', 'required' => true],
                ['name' => 'v3_key',    'title' => 'API v3 密钥',          'type' => 'string', 'required' => true],
                ['name' => 'serial_no', 'title' => '商户证书序列号',        'type' => 'string', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['mch_id'])) {
            return ['code' => -1, 'msg' => '微信商户号 (mch_id) 不能为空'];
        }
        if (empty($config['v3_key'])) {
            return ['code' => -1, 'msg' => 'API v3 密钥 (v3_key) 不能为空（用于解密回调报文）'];
        }
        return $config;
    }

    /**
     * 使用 API v3 密钥解密微信回调 resource 报文（AES-256-GCM）
     *
     * @param  array  $resource 包含 algorithm/nonce/associated_data/ciphertext 的数组
     * @param  string $v3Key    API v3 密钥（32字节）
     * @return array|null       解密后的支付结果数组，失败返回 null
     */
    private function decryptResource(array $resource, string $v3Key): ?array
    {
        $algorithm      = $resource['algorithm']       ?? '';
        $nonce          = $resource['nonce']           ?? '';
        $associatedData = $resource['associated_data'] ?? '';
        $ciphertext     = $resource['ciphertext']      ?? '';

        if ($algorithm !== 'AEAD_AES_256_GCM' || empty($nonce) || empty($ciphertext)) {
            return null;
        }

        $ciphertextBin = base64_decode($ciphertext, true);
        if ($ciphertextBin === false || strlen($ciphertextBin) < 16) {
            return null;
        }

        // AES-256-GCM: 密文 = 加密数据 + 16字节 auth tag（附在末尾）
        $tag  = substr($ciphertextBin, -16);
        $data = substr($ciphertextBin, 0, -16);

        $plaintext = openssl_decrypt(
            $data,
            'aes-256-gcm',
            $v3Key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData
        );

        if ($plaintext === false) {
            return null;
        }

        return json_decode($plaintext, true) ?: null;
    }
}
