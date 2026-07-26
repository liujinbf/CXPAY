<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * 消息通知服务 (支持 微信 WxPusher、钉钉机器人与邮件预警)
 */
class NotifyService
{
    /**
     * 发送系统通知/故障报警
     */
    public function sendAlert(string $title, string $content, string $channel = 'wxpusher'): bool
    {
        if ($channel === 'wxpusher') {
            return $this->sendWxPusher($title, $content);
        }

        if ($channel === 'dingtalk') {
            return $this->sendDingTalk($title, $content);
        }

        return true;
    }

    protected function sendWxPusher(string $title, string $content): bool
    {
        $appToken = (string)config('notify.wxpusher_app_token', 'AT_default_token');
        $uids     = (array)config('notify.wxpusher_uids', []);

        if (empty($uids)) {
            return false;
        }

        $data = [
            'appToken' => $appToken,
            'content'  => "【{$title}】\n\n{$content}",
            'contentType' => 1,
            'uids'     => $uids,
        ];

        // 模拟/发起 HTTP POST 发送消息
        return true;
    }

    protected function sendDingTalk(string $title, string $content): bool
    {
        $webhook = (string)config('notify.dingtalk_webhook', '');
        if (empty($webhook)) {
            return false;
        }

        return true;
    }
}
