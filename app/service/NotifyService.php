<?php

declare(strict_types=1);

namespace app\service;

/**
 * 系统报警通知服务
 * 修复：原版 sendWxPusher / sendDingTalk 均为空实现（直接 return true）
 * 现已实现真实 HTTP 推送逻辑，支持 WxPusher 和 钉钉 Webhook 两个渠道
 */
class NotifyService
{
    /**
     * 发送系统通知/故障报警
     *
     * @param string $title   消息标题
     * @param string $content 消息正文
     * @param string $channel 通知渠道：wxpusher | dingtalk | both
     * @return bool
     */
    public function sendAlert(string $title, string $content, string $channel = 'wxpusher'): bool
    {
        $results = [];

        if (in_array($channel, ['wxpusher', 'both'], true)) {
            $results[] = $this->sendWxPusher($title, $content);
        }

        if (in_array($channel, ['dingtalk', 'both'], true)) {
            $results[] = $this->sendDingTalk($title, $content);
        }

        // 任意一个渠道成功即视为发送成功
        return in_array(true, $results, true);
    }

    /**
     * 通过 WxPusher 发送微信消息推送
     * 文档：https://wxpusher.zjiecode.com/docs
     */
    protected function sendWxPusher(string $title, string $content): bool
    {
        $appToken = (string)config('notify.wxpusher_app_token', '');
        $uids     = (array)config('notify.wxpusher_uids', []);

        if (empty($appToken) || empty($uids)) {
            return false;
        }

        $payload = [
            'appToken'    => $appToken,
            'content'     => "【{$title}】\n\n{$content}\n\n时间：" . date('Y-m-d H:i:s'),
            'contentType' => 1,  // 1=文字，2=HTML，3=Markdown
            'uids'        => $uids,
        ];

        return $this->httpPost('https://wxpusher.zjiecode.com/api/send/message', $payload);
    }

    /**
     * 通过 钉钉 自定义机器人 Webhook 发送告警
     * 文档：https://open.dingtalk.com/document/robots/custom-robot-access
     */
    protected function sendDingTalk(string $title, string $content): bool
    {
        $webhook = (string)config('notify.dingtalk_webhook', '');
        $secret  = (string)config('notify.dingtalk_secret', '');

        if (empty($webhook)) {
            return false;
        }

        // 如果配置了加签密钥，计算签名（钉钉安全设置要求）
        if (!empty($secret)) {
            $timestamp = (string)(round(microtime(true) * 1000));
            $signStr   = $timestamp . "\n" . $secret;
            $sign      = urlencode(base64_encode(hash_hmac('sha256', $signStr, $secret, true)));
            $webhook   = "{$webhook}&timestamp={$timestamp}&sign={$sign}";
        }

        $payload = [
            'msgtype'  => 'markdown',
            'markdown' => [
                'title' => $title,
                'text'  => "## {$title}\n\n{$content}\n\n> {$this->getServerIp()} | " . date('Y-m-d H:i:s'),
            ],
            'at' => [
                'isAtAll' => false,
            ],
        ];

        return $this->httpPost($webhook, $payload);
    }

    /**
     * 发起 HTTPS POST JSON 请求（带超时保护）
     */
    protected function httpPost(string $url, array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            return false;
        }

        // HTTP 2xx 视为成功
        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * 获取当前服务器 IP（用于告警内容标识来源机器）
     */
    protected function getServerIp(): string
    {
        return (string)(gethostbyname(gethostname()) ?: 'unknown');
    }
}
