<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * 云端多租户 IP 代理池与指纹隔离守护服务 (Proxy & Fingerprint Isolation Guard)
 */
class ProxyIsolationService
{
    /**
     * 为指定商户/通道分配或获取独立的住宅代理 IP 与客户端 Header 指纹
     *
     * @param int $channelId 通道ID
     * @param int $merchantId 商户ID
     * @return array [proxy_url => string, headers => array]
     */
    public function getIsolatedContext(int $channelId, int $merchantId): array
    {
        // 1. 生成基于通道/商户唯一哈希的客户端 User-Agent 指纹 (隔离不同设备的客户端特征)
        $userAgent = $this->generateUniqueUserAgent($channelId, $merchantId);

        // 2. 获取或配置动态代理 IP (支持 SOCKS5 / HTTP 住宅代理)
        $proxyUrl = (string)config('payment.proxy_pool_url', '');

        $headers = [
            'User-Agent'      => $userAgent,
            'Accept-Language' => 'zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'X-Forwarded-For' => $this->generateRandomChinaIp($channelId),
            'Client-IP'       => $this->generateRandomChinaIp($channelId),
        ];

        return [
            'proxy'   => $proxyUrl,
            'headers' => $headers,
        ];
    }

    /**
     * 生成基于商户账号哈希的独立 User-Agent
     */
    protected function generateUniqueUserAgent(int $channelId, int $merchantId): string
    {
        $hash = crc32("{$channelId}_{$merchantId}");
        $models = [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 MicroMessenger/8.0.48(0x18003029)',
            'Mozilla/5.0 (Linux; Android 14; 23117RK66C Build/UKQ1.230804.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/116.0.5845.163 Mobile Safari/537.36 XWEB/1160065 MMWEBSDK/20240301 MicroMessenger/8.0.47',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 MicroMessenger/8.0.42',
        ];

        return $models[$hash % count($models)];
    }

    /**
     * 生成随机中国国内家宽 IP 字符串 (用于 Header 散列)
     */
    protected function generateRandomChinaIp(int $seed): string
    {
        $ranges = [
            ['113.64.0.0', '113.111.255.255'],  // 广东电信
            ['183.232.0.0', '183.240.255.255'],// 广东移动
            ['222.160.0.0', '222.175.255.255'],// 山东联通
            ['114.240.0.0', '114.255.255.255'],// 北京联通
        ];

        $index = $seed % count($ranges);
        $start = ip2long($ranges[$index][0]);
        $end   = ip2long($ranges[$index][1]);

        return long2ip(mt_rand($start, $end));
    }
}
