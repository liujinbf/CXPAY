<?php

declare(strict_types=1);

namespace WxCollector;

use RuntimeException;

/**
 * 微信 PC Hook 收款回调接收器。
 *
 * WeChat-Hook 启动时通过 CallBackURL 参数注册推送地址。
 * 当微信收到消息时，Hook DLL 向该 URL POST JSON 数据。
 *
 * 本接收器解析推送内容，过滤收款类消息（MsgType=49, appmsgtype=2000），
 * 提取稳定账单号（transcationid）和金额后写入 WxPcBillStore。
 *
 * 使用方式：在 collector.php 的 Workerman Worker 中添加一个 HTTP 路由：
 *
 *   $worker->onMessage = function($conn, $request) use ($receiver) {
 *       if ($request->method() === 'POST' && $request->path() === '/wx-hook-callback') {
 *           $conn->send($receiver->handle((string)$request->rawBody()));
 *       }
 *   };
 *
 * 回调 JSON 格式（WeChat-Hook 4.1.10.27）：
 * {
 *   "MsgSvrID": 1234567890,
 *   "Type": 49,
 *   "StrTalker": "wxid_xxx",
 *   "StrContent": "<msg>...</msg>",   // XML 内容
 *   "CreateTime": 1753000000
 * }
 */
final class WxPcCallbackReceiver
{
    public function __construct(
        private readonly WxPcBillStore $store,
        private readonly string $accountRef,  // 当前登录账号的 wxid_hash
    ) {
    }

    /**
     * 处理一次 CallBackURL POST 请求体。
     * 返回 "ok" 让 Hook DLL 知道回调已接收。
     */
    public function handle(string $rawBody): string
    {
        if ($rawBody === '') {
            return 'ok';
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return 'ok'; // 解析失败忽略
        }

        // 只处理 MsgType=49（App 消息，包含转账/收款）
        if ((int)($data['Type'] ?? 0) !== 49) {
            return 'ok';
        }

        $content = (string)($data['StrContent'] ?? '');
        $this->processPaymentContent($content, (int)($data['CreateTime'] ?? time()), (array)$data);

        return 'ok';
    }

    /**
     * 解析收款 XML 内容，写入账单队列。
     *
     * @param array<string, mixed> $rawMsg 原始回调数据（会被加密存储）
     */
    private function processPaymentContent(string $xml, int $createTime, array $rawMsg): void
    {
        // 必须是转账收款消息
        if (!str_contains($xml, '<type>2000</type>')) {
            return;
        }

        // 只处理收款（paysubtype=1），忽略退款（3）和自己发出的转账（4）
        if (!preg_match('/<paysubtype>1<\/paysubtype>/', $xml)) {
            return;
        }

        // 提取金额
        if (!preg_match('/<feedesc>¥([\d.]+)/', $xml, $amtMatch)) {
            return;
        }
        $amount = number_format((float)$amtMatch[1], 2, '.', '');

        // 提取微信服务器账单号（transcationid）
        if (!preg_match('/<transcationid>([\d]+)<\/transcationid>/', $xml, $tidMatch)) {
            return;
        }
        $billId = $tidMatch[1];

        if ($this->store->exists($billId)) {
            return; // 幂等
        }

        $this->store->insert($billId, $this->accountRef, $amount, $createTime, [
            'source'     => 'callback',
            'msg_svr_id' => (string)($rawMsg['MsgSvrID'] ?? ''),
            'talker'     => (string)($rawMsg['StrTalker'] ?? ''),
        ]);
    }
}
