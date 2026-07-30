<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use RuntimeException;

/**
 * 企业微信 Webhook 官方 API 接收解密器
 * 负责接收腾讯微信官方服务器推送的加密到账通知，进行 SHA1 验签、AES 解密并提取交易账单。
 * 100% 官方公开 API，零封号风险。
 */
final class WorkWechatCallbackReceiver
{
    private string $token;
    private string $encodingAesKey;
    private string $receiveId;

    public function __construct(string $token, string $encodingAesKey, string $receiveId = '')
    {
        if (strlen($encodingAesKey) !== 43) {
            throw new RuntimeException('EncodingAESKey 必须为 43 位字符');
        }
        $this->token = $token;
        $this->encodingAesKey = $encodingAesKey;
        $this->receiveId = $receiveId;
    }

    /**
     * 响应企业微信官方 URL 验证请求 (GET)
     */
    public function verifyUrl(string $msgSignature, string $timestamp, string $nonce, string $echostr): string
    {
        $signature = $this->signature($this->token, $timestamp, $nonce, $echostr);
        if (!hash_equals($signature, $msgSignature)) {
            throw new RuntimeException('企业微信 URL 验证签名错误');
        }
        return $this->decrypt($echostr);
    }

    /**
     * 解析解密企业微信官方推送的 XML 回调数据 (POST)
     *
     * @return array{source_bill_id: string, amount: string, occurred_at: int, merchant_name: string}
     */
    public function handlePost(string $msgSignature, string $timestamp, string $nonce, string $postData): array
    {
        if (trim($postData) === '') {
            throw new RuntimeException('POST 数据不能为空');
        }

        // 解析加密 XML
        $encrypt = $this->extractXmlNode($postData, 'Encrypt');
        if ($encrypt === '') {
            throw new RuntimeException('未找到 Encrypt 节点');
        }

        // 验证签名
        $signature = $this->signature($this->token, $timestamp, $nonce, $encrypt);
        if (!hash_equals($signature, $msgSignature)) {
            throw new RuntimeException('消息签名校验失败');
        }

        // 解密纯文本 XML
        $decryptedXml = $this->decrypt($encrypt);

        // 提取账单关键数据
        return $this->parsePaymentEventXml($decryptedXml);
    }

    private function decrypt(string $encrypt): string
    {
        $key = base64_decode($this->encodingAesKey . '=', true);
        $iv  = substr($key, 0, 16);
        $decrypted = openssl_decrypt(base64_decode($encrypt), 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($decrypted === false) {
            throw new RuntimeException('AES 解密失败');
        }

        // 剥除 PKCS#7 填充
        $pad = ord(substr($decrypted, -1));
        if ($pad < 1 || $pad > 32) {
            $pad = 0;
        }
        $result = substr($decrypted, 0, strlen($decrypted) - $pad);

        // 剥除随机 16 字节前缀与 4 字节 msg_len
        $content = substr($result, 16);
        $len = unpack('N', substr($content, 0, 4))[1];
        return substr($content, 4, $len);
    }

    private function signature(string $token, string $timestamp, string $nonce, string $data): string
    {
        $array = [$token, $timestamp, $nonce, $data];
        sort($array, SORT_STRING);
        return sha1(implode('', $array));
    }

    private function extractXmlNode(string $xml, string $node): string
    {
        if (preg_match("/<{$node}><!\[CDATA\[(.*?)\]\]><\/{$node}>|<{$node}>(.*?)<\/{$node}>/s", $xml, $matches)) {
            return trim($matches[1] !== '' ? $matches[1] : $matches[2]);
        }
        return '';
    }

    private function parsePaymentEventXml(string $xml): array
    {
        $billId = $this->extractXmlNode($xml, 'transcationid');
        if ($billId === '') {
            $billId = $this->extractXmlNode($xml, 'MsgID');
        }
        if ($billId === '') {
            $billId = 'WEMSG-' . bin2hex(random_bytes(10));
        }

        // 金额解析
        $amount = '0.00';
        if (preg_match('/<feedesc>¥([\d.]+)/', $xml, $m) || preg_match('/<amount>([\d.]+)/', $xml, $m)) {
            $amount = number_format((float)$m[1], 2, '.', '');
        }

        $merchantName = $this->extractXmlNode($xml, 'MerchantName');

        return [
            'source_bill_id' => $billId,
            'amount'         => $amount,
            'occurred_at'    => time(),
            'merchant_name'  => $merchantName,
        ];
    }
}
