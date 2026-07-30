<?php

declare(strict_types=1);

namespace app\service\AlertChannelDriver;

/**
 * 通用 HTTP Webhook 通知驱动
 *
 * 支持 Bark、飞书、钉钉等任何接受 HTTP POST JSON 的通知服务。
 * 消息以 POST JSON 方式发送：{ "title": "...", "content": "...", "timestamp": 1234567890 }
 * 可配置额外的 Header（如 Authorization: Bearer xxx）。
 */
class WebhookDriver
{
    /**
     * @param array $config {
     *   url: string,           // Webhook 目标 URL
     *   headers?: string,      // 额外请求头，格式 "Key: Value\nKey2: Value2"
     *   body_template?: string // 可选 JSON 模板，{title} {content} {timestamp} 会被替换
     * }
     */
    public function send(string $subject, string $bodyText, array $config): bool
    {
        $url = trim((string)($config['url'] ?? ''));
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            error_log('[WebhookDriver] url 格式不合法或为空');
            return false;
        }

        $timestamp = time();

        // 如果提供了自定义模板，使用模板；否则用标准格式
        $template = trim((string)($config['body_template'] ?? ''));
        if ($template !== '') {
            $bodyJson = str_replace(
                ['{title}', '{content}', '{timestamp}'],
                [addslashes($subject), addslashes($bodyText), $timestamp],
                $template
            );
        } else {
            $bodyJson = json_encode([
                'title'     => $subject,
                'content'   => $bodyText,
                'timestamp' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);
        }

        // 解析额外 Header
        $extraHeaders = ['Content-Type: application/json'];
        $rawHeaders   = trim((string)($config['headers'] ?? ''));
        if ($rawHeaders !== '') {
            foreach (explode("\n", $rawHeaders) as $h) {
                $h = trim($h);
                if ($h !== '' && str_contains($h, ':')) {
                    $extraHeaders[] = $h;
                }
            }
        }

        return $this->post($url, (string)$bodyJson, $extraHeaders);
    }

    private function post(string $url, string $body, array $headers): bool
    {
        if (!function_exists('curl_init')) {
            error_log('[WebhookDriver] cURL 扩展未安装');
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
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

        if ($errno !== 0 || !is_string($response) || $httpCode < 200 || $httpCode >= 300) {
            error_log("[WebhookDriver] HTTP {$httpCode} errno={$errno} url={$url}");
            return false;
        }
        return true;
    }
}
