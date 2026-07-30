<?php

declare(strict_types=1);

namespace app\service\AlertChannelDriver;

/**
 * 企业微信群机器人 Webhook 通知驱动
 *
 * 文档：https://developer.work.weixin.qq.com/document/path/91770
 */
class WxWorkDriver
{
    /**
     * @param array $config { webhook_url: string }
     */
    public function send(string $subject, string $bodyText, array $config): bool
    {
        $url = trim((string)($config['webhook_url'] ?? ''));
        if (empty($url)) {
            return false;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            error_log('[WxWorkDriver] webhook_url 格式不合法');
            return false;
        }

        // 转换文本为企业微信 Markdown 格式
        $markdownContent = "### {$subject}\n" . str_replace("\n", "\n> ", "\n" . $bodyText);

        $payload = json_encode([
            'msgtype'  => 'markdown',
            'markdown' => [
                'content' => $markdownContent,
            ],
        ], JSON_UNESCAPED_UNICODE);

        return $this->post($url, $payload, 'application/json');
    }

    private function post(string $url, string $body, string $contentType): bool
    {
        if (!function_exists('curl_init')) {
            error_log('[WxWorkDriver] cURL 扩展未安装');
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ["Content-Type: {$contentType}"],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            error_log("[WxWorkDriver] HTTP {$httpCode} errno={$errno}");
            return false;
        }
        $json = json_decode(is_string($response) ? $response : '', true);
        if (($json['errcode'] ?? -1) !== 0) {
            error_log('[WxWorkDriver] API 返回错误: ' . ($json['errmsg'] ?? '未知'));
            return false;
        }
        return true;
    }
}
