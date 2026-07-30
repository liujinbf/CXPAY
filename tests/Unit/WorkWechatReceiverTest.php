<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WxMonitorCloud\PersonalWechatCallbackReceiver;
use WxMonitorCloud\WorkWechatCallbackReceiver;

require_once __DIR__ . '/../../services/wx-monitor-cloud/src/WorkWechatCallbackReceiver.php';
require_once __DIR__ . '/../../services/wx-monitor-cloud/src/PersonalWechatCallbackReceiver.php';

final class WorkWechatReceiverTest extends TestCase
{
    public function testWorkWechatEncryptionAndDecryption(): void
    {
        $token = 'wx_token_123456';
        $encodingAesKey = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG'; // 43 字符
        $receiver = new WorkWechatCallbackReceiver($token, $encodingAesKey);

        $xml = '<xml><transcationid>100003780120260730999</transcationid><feedesc>¥66.00</feedesc><MerchantName>测试商户</MerchantName></xml>';

        // 构造 AES 加密数据
        $key = base64_decode($encodingAesKey . '=', true);
        $iv = substr($key, 0, 16);
        $random = random_bytes(16);
        $msgLen = pack('N', strlen($xml));
        $raw = $random . $msgLen . $xml;
        $pad = 32 - (strlen($raw) % 32);
        if ($pad === 0) $pad = 32;
        $raw .= str_repeat(chr($pad), $pad);

        $encrypted = base64_encode(openssl_encrypt($raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv));
        $timestamp = (string)time();
        $nonce = '12345678';
        $array = [$token, $timestamp, $nonce, $encrypted];
        sort($array, SORT_STRING);
        $msgSignature = sha1(implode('', $array));

        $postXml = "<xml><ToUserName><![CDATA[toUser]]></ToUserName><Encrypt><![CDATA[{$encrypted}]]></Encrypt></xml>";

        $result = $receiver->handlePost($msgSignature, $timestamp, $nonce, $postXml);
        self::assertSame('100003780120260730999', $result['source_bill_id']);
        self::assertSame('66.00', $result['amount']);
        self::assertSame('测试商户', $result['merchant_name']);
    }

    public function testPersonalWechatPayloadParsing(): void
    {
        $receiver = new PersonalWechatCallbackReceiver();
        $payload = [
            'Type' => 49,
            'StrContent' => '<msg><appmsg><type>2000</type><wcpayinfo><paysubtype>1</paysubtype><feedesc>¥99.99</feedesc><transcationid>100003780120260730888</transcationid></wcpayinfo></appmsg></msg>',
            'CreateTime' => 1753000100,
            'MsgSvrID' => 123456789,
        ];

        $res = $receiver->parseMsgPayload($payload);
        self::assertSame('100003780120260730888', $res['source_bill_id']);
        self::assertSame('99.99', $res['amount']);
    }
}
