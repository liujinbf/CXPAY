<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Channel;
use app\model\Order;
use app\service\CallbillService;
use support\Authcode;
use support\Request;
use support\Response;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 微信官方企业微信自建应用 Webhook 回调控制器
 *
 * 支持：
 * 1. 平台全局统一企业微信免挂托管（商户无需注册企业微信，统一由平台 Webhook 接收并智能撮合核销）；
 * 2. 商户独立企业微信自建应用直连（/api/wecom/webhook/{channel_id}）。
 */
class WecomWebhookController
{
    protected Authcode $authcode;
    protected CallbillService $callbillService;

    public function __construct()
    {
        $this->authcode = new Authcode();
        $this->callbillService = new CallbillService();
    }

    /**
     * Webhook 入口 (统一支持 GET 握手验证与 POST 消息推送)
     */
    public function handle(Request $request, string $channel_id = ''): Response
    {
        $channelId = (int)($channel_id ?: $request->get('channel_id', 0));
        $channel = null;
        if ($channelId > 0) {
            $channel = Channel::find($channelId);
        }

        // 详细记录接收到的所有请求（供实时排查）
        try {
            DB::table('cx_audit_log')->insert([
                'operator'   => 'wecom_webhook',
                'action'     => $request->method(),
                'context'    => json_encode([
                    'uri'      => $request->uri(),
                    'query'    => $request->get(),
                    'raw_len'  => strlen($request->rawBody()),
                    'raw_body' => substr($request->rawBody(), 0, 1000),
                ], JSON_UNESCAPED_UNICODE),
                'result'     => 'received',
                'ip'         => (string)($request->getRealIp() ?: '127.0.0.1'),
                'created_at' => time(),
            ]);
        } catch (\Throwable) {}

        // 获取平台全局企业微信托管配置
        $platformConfigs = [];
        try {
            $platformConfigs = DB::table('cx_config')
                ->whereIn('name', [
                    'platform_wecom_enabled',
                    'platform_wecom_token',
                    'platform_wecom_encoding_aes_key',
                    'platform_wecom_corp_id',
                ])
                ->pluck('value', 'name');
        } catch (\Throwable) {}

        $platformToken   = trim((string)($platformConfigs['platform_wecom_token'] ?? ''));
        $platformAesKey  = trim((string)($platformConfigs['platform_wecom_encoding_aes_key'] ?? ''));
        $platformCorpId  = trim((string)($platformConfigs['platform_wecom_corp_id'] ?? ''));

        // ==========================================
        // 1. GET 请求：响应企业微信 URL 有效性握手验证
        // ==========================================
        if ($request->method() === 'GET') {
            $echostr = (string)$request->get('echostr', '');
            if ($echostr === '') {
                return response('CXPAY WeCom Webhook Gateway Active', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
            }
            // A. 若指定了通道 ID，优先使用通道自身配置
            if ($channel) {
                $rawConfig = json_decode((string)$channel->config, true) ?: [];
                $config = [];
                foreach ($rawConfig as $k => $v) {
                    $config[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
                }
                $token = (string)($config['token'] ?? $platformToken);
                $encodingAESKey = (string)($config['encoding_aes_key'] ?? $platformAesKey);
                $resp = $this->handleGetValidation($request, $token, $encodingAESKey);
                if ($resp->getStatusCode() === 200) {
                    $channel->online_status = 1;
                    $channel->last_heartbeat_time = time();
                    if (!$channel->online_since) {
                        $channel->online_since = time();
                    }
                    $channel->save();
                }
                return $resp;
            }

            // B. 全局 Webhook 握手：优先匹配平台统一托管 Token
            if ($platformToken !== '') {
                $resp = $this->handleGetValidation($request, $platformToken, $platformAesKey);
                if ($resp->getStatusCode() === 200) {
                    return $resp;
                }
            }

            // C. 兜底探测：遍历所有已配置的企业微信通道进行 Token 签名自动匹配探测
            $wecomChannels = Channel::where('c_type', 'wechat_wecom_app')->get();
            $msgSignature = (string)$request->get('msg_signature', '');
            $timestamp    = (string)$request->get('timestamp', '');
            $nonce        = (string)$request->get('nonce', '');
            $echostr      = (string)$request->get('echostr', '');

            foreach ($wecomChannels as $ch) {
                $rawConfig = json_decode((string)$ch->config, true) ?: [];
                $cfg = [];
                foreach ($rawConfig as $k => $v) {
                    $cfg[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
                }
                $tok = (string)($cfg['token'] ?? '');
                $aes = (string)($cfg['encoding_aes_key'] ?? '');
                if ($tok !== '' && $this->calcSignature($tok, $timestamp, $nonce, $echostr) === $msgSignature) {
                    $decrypted = ($aes !== '' && strlen($aes) === 43) ? $this->decryptAES($echostr, $aes) : null;
                    $echoOutput = $decrypted !== null ? $decrypted : $echostr;
                    $ch->online_status = 1;
                    $ch->last_heartbeat_time = time();
                    if (!$ch->online_since) {
                        $ch->online_since = time();
                    }
                    $ch->save();
                    return response($echoOutput, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
                }
            }

            return response('channel not found or signature mismatch', 403);
        }

        // ==========================================
        // 2. POST 请求：接收企业微信推送的收款消息与事件
        // ==========================================
        $token = $platformToken;
        $encodingAESKey = $platformAesKey;
        $corpId = $platformCorpId;

        if ($channel) {
            $rawConfig = json_decode((string)$channel->config, true) ?: [];
            $config = [];
            foreach ($rawConfig as $k => $v) {
                $config[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
            }
            if (!empty($config['token'])) {
                $token = (string)$config['token'];
            }
            if (!empty($config['encoding_aes_key'])) {
                $encodingAESKey = (string)$config['encoding_aes_key'];
            }
            if (!empty($config['corp_id'])) {
                $corpId = (string)$config['corp_id'];
            }
        }

        return $this->handlePostMessage($request, $channel, $token, $encodingAESKey, $corpId);
    }

    /**
     * 处理企业微信 GET URL 握手验证
     */
    private function handleGetValidation(Request $request, string $token, string $encodingAESKey): Response
    {
        $msgSignature = (string)$request->get('msg_signature', '');
        $timestamp    = (string)$request->get('timestamp', '');
        $nonce        = (string)$request->get('nonce', '');
        $echostr      = (string)$request->get('echostr', '');

        if ($echostr === '') {
            return response('echostr missing', 400);
        }

        // 校验签名
        if ($msgSignature !== '' && $token !== '') {
            $calcSign = $this->calcSignature($token, $timestamp, $nonce, $echostr);
            if ($calcSign !== $msgSignature) {
                @file_put_contents('/tmp/wecom_req.log', sprintf("[GET Sign Mismatch] token=%s, calc=%s, msg_sig=%s, echostr=%s\n", $token, $calcSign, $msgSignature, $echostr), FILE_APPEND);
                return response('signature verification failed', 403);
            }
        }

        // 解密 echostr（如果配置了 AES 密钥且 echostr 为密文）
        $decryptedEcho = $echostr;
        if ($encodingAESKey !== '' && strlen($encodingAESKey) === 43) {
            $decrypted = $this->decryptAES($echostr, $encodingAESKey);
            if ($decrypted !== null) {
                $decryptedEcho = $decrypted;
            } else {
                @file_put_contents('/tmp/wecom_req.log', sprintf("[GET Decrypt Failed] key=%s, echostr=%s\n", $encodingAESKey, $echostr), FILE_APPEND);
            }
        }

        @file_put_contents('/tmp/wecom_req.log', sprintf("[GET Validation OK] decryptedEcho=%s\n", $decryptedEcho), FILE_APPEND);
        return response($decryptedEcho, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * 处理企业微信 POST 消息推送 (含智能多商户订单撮合引擎)
     */
    private function handlePostMessage(
        Request $request,
        ?Channel $channel,
        string $token,
        string $encodingAESKey,
        string $corpId
    ): Response {
        $body = (string)$request->rawBody();
        if ($body === '') {
            return response('success');
        }

        $msgSignature = (string)$request->get('msg_signature', '');
        $timestamp    = (string)$request->get('timestamp', '');
        $nonce        = (string)$request->get('nonce', '');

        // 解析外层 XML
        $encryptContent = '';
        if (str_starts_with(trim($body), '<')) {
            if (preg_match('/<Encrypt><!\[CDATA\[(.*?)\]\]><\/Encrypt>/si', $body, $m) || preg_match('/<Encrypt>(.*?)<\/Encrypt>/si', $body, $m)) {
                $encryptContent = trim($m[1]);
            }
        } elseif (str_starts_with(trim($body), '{')) {
            $jsonData = json_decode($body, true) ?: [];
            $encryptContent = (string)($jsonData['Encrypt'] ?? '');
        }

        $xmlMessage = $body;
        if ($encryptContent !== '' && $encodingAESKey !== '') {
            // 校验消息签名
            if ($msgSignature !== '' && $token !== '') {
                $calcSign = $this->calcSignature($token, $timestamp, $nonce, $encryptContent);
                if ($calcSign !== $msgSignature) {
                    @file_put_contents($logFile, sprintf("[%s] Sign mismatch! calc=%s, given=%s\n", date('Y-m-d H:i:s'), $calcSign, $msgSignature), FILE_APPEND);
                    return response('invalid signature', 403);
                }
            }
            $decrypted = $this->decryptAES($encryptContent, $encodingAESKey);
            if ($decrypted !== null) {
                $xmlMessage = $decrypted;
            }
        }

        // 解析消息文本内容
        $content = '';
        if (preg_match('/<Content><!\[CDATA\[(.*?)\]\]><\/Content>/si', $xmlMessage, $m) || preg_match('/<Content>(.*?)<\/Content>/si', $xmlMessage, $m)) {
            $content = trim($m[1]);
        } elseif (preg_match('/"content"\s*:\s*"([^"]+)"/si', $xmlMessage, $m)) {
            $content = trim($m[1]);
        } else {
            $content = strip_tags($xmlMessage);
        }

        // 提取收款金额、流水号与备注
        $parsed = $this->extractPaymentInfo($content, $xmlMessage);
        @file_put_contents($logFile, sprintf(
            "[%s] Decrypted XML: %s | Extracted Content: %s | Parsed Result: %s\n",
            date('Y-m-d H:i:s'),
            $xmlMessage,
            $content,
            json_encode($parsed, JSON_UNESCAPED_UNICODE)
        ), FILE_APPEND);

        if ($parsed !== null) {
            $occurredAt = $parsed['time'] ?: time();
            $billId     = $parsed['bill_id'];
            $amount     = (float)$parsed['amount'];
            $remark     = $parsed['remark'];
            $rawHash    = md5($billId . '_' . $amount . '_' . $occurredAt);

            $targetChannelId = $channel ? (int)$channel->id : 0;

            // 智能多商户订单撮合：若全局 Webhook 未指定单通道，在全量企业微信通道中寻找对应金额的待支付订单
            if ($targetChannelId <= 0) {
                $now = time();
                $priceFormatted = number_format($amount, 2, '.', '');
                $matchedOrder = Order::where('status', 0)
                    ->where('price', $priceFormatted)
                    ->where('create_time', '<=', $occurredAt + 120)
                    ->where('expire_time', '>=', $occurredAt)
                    ->where('expire_time', '>', $now)
                    ->whereHas('channel', function ($q) {
                        $q->whereIn('c_type', ['wechat_wecom_app', 'wechat_dy_bill']);
                    })
                    ->orderBy('id', 'desc')
                    ->first();

                if ($matchedOrder) {
                    $targetChannelId = (int)$matchedOrder->channel_id;
                } else {
                    // 若无精确匹配订单，归属到首个启用的企微通道记录日志
                    $fallbackChannel = Channel::where('c_type', 'wechat_wecom_app')->where('status', 1)->first()
                        ?: Channel::where('c_type', 'wechat_wecom_app')->first();
                    $targetChannelId = $fallbackChannel ? (int)$fallbackChannel->id : 0;
                }
            }

            if ($targetChannelId > 0) {
                $this->callbillService->processPush(
                    'wechat_wecom_app',
                    'wecom-webhook',
                    $amount,
                    $remark,
                    $targetChannelId,
                    $billId,
                    $occurredAt,
                    $rawHash,
                    'wecom-webhook/platform-hosted'
                );

                // 更新对应通道在线状态
                $targetChannel = Channel::find($targetChannelId);
                if ($targetChannel) {
                    $targetChannel->online_status = 1;
                    $targetChannel->last_heartbeat_time = time();
                    if (!$targetChannel->online_since) {
                        $targetChannel->online_since = time();
                    }
                    $targetChannel->save();
                }
            }
        }

        return response('success', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * 从微信收款通知文本中正则提取金额、单号与时间
     */
    private function extractPaymentInfo(string $text, string $rawXml): ?array
    {
        // 匹配各种微信支付收款播报格式：
        // 1. 微信支付收款0.01元 / 收到转账0.01元 / 微信收款小账本收到0.01元
        // 2. 收款金额：￥0.01 / 到账金额 0.01
        $amount = 0.0;
        if (preg_match('/(?:收款|转账|到账|收入|支付|金额)\s*[:：￥¥]?\s*([0-9]+\.[0-9]{2})/u', $text, $m)) {
            $amount = (float)$m[1];
        } elseif (preg_match('/([0-9]+\.[0-9]{2})\s*元/u', $text, $m)) {
            $amount = (float)$m[1];
        }

        if ($amount <= 0) {
            return null;
        }

        // 匹配单号/流水号（如 28 位微信支付订单号或随机唯一业务键）
        $billId = '';
        if (preg_match('/(?:单号|流水号|交易单号|支付单号)\s*[:：]?\s*([0-9a-zA-Z]{16,64})/u', $text, $m)) {
            $billId = trim($m[1]);
        } elseif (preg_match('/<MsgId>([0-9]+)<\/MsgId>/si', $rawXml, $m)) {
            $billId = 'WECOM_MSG_' . trim($m[1]);
        } else {
            $billId = 'WECOM_' . md5($text . '_' . number_format($amount, 2, '.', '') . '_' . date('YmdHi'));
        }

        // 提取备注
        $remark = '';
        if (preg_match('/(?:备注|付款说明|留言)\s*[:：]?\s*([^\r\n,，。]+)/u', $text, $m)) {
            $remark = trim($m[1]);
        }

        return [
            'amount'  => number_format($amount, 2, '.', ''),
            'bill_id' => $billId,
            'remark'  => $remark,
            'time'    => time(),
        ];
    }

    /**
     * 计算企业微信 SHA1 签名
     */
    private function calcSignature(string $token, string $timestamp, string $nonce, string $encrypt): string
    {
        $arr = [$token, $timestamp, $nonce, $encrypt];
        sort($arr, SORT_STRING);
        return sha1(implode('', $arr));
    }

    /**
     * AES-256-CBC 企业微信官方解密 (带 PKCS#7 解填充)
     */
    private function decryptAES(string $encryptedBase64, string $encodingAESKey): ?string
    {
        try {
            $aesKey = base64_decode($encodingAESKey . '=', true);
            if (!$aesKey || strlen($aesKey) !== 32) {
                return null;
            }
            $iv = substr($aesKey, 0, 16);
            $ciphertext = base64_decode($encryptedBase64, true);
            if (!$ciphertext) {
                return null;
            }

            $decrypted = openssl_decrypt(
                $ciphertext,
                'AES-256-CBC',
                $aesKey,
                OPENSSL_RAW_DATA | OPENSSL_NO_PADDING,
                $iv
            );
            if ($decrypted === false || strlen($decrypted) < 20) {
                return null;
            }

            // 去除 PKCS#7 补位
            $pad = ord(substr($decrypted, -1));
            if ($pad < 1 || $pad > 32) {
                $pad = 0;
            }
            $decrypted = substr($decrypted, 0, strlen($decrypted) - $pad);

            // 前 16 字节为随机字符串，接 4 字节为明文内容长度（网络字节序），后面是明文内容与 ReceiveId/CorpId
            $contentLen = unpack('N', substr($decrypted, 16, 4))[1] ?? 0;
            if ($contentLen <= 0 || (20 + $contentLen) > strlen($decrypted)) {
                return substr($decrypted, 20);
            }

            return substr($decrypted, 20, $contentLen);
        } catch (\Throwable) {
            return null;
        }
    }
}
