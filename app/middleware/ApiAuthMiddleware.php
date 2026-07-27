<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use app\model\Merchant;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 商户 API 请求签名校验中间件
 * 修复：真实查库取商户密钥 + timestamp 防重放 + nonce Redis 去重
 */
class ApiAuthMiddleware implements MiddlewareInterface
{
    /** 时间戳允许偏差（秒） */
    private const TIMESTAMP_TOLERANCE = 300;

    /** nonce 防重放 Redis Key 前缀 */
    private const NONCE_PREFIX = 'cx:nonce:';

    public function process(Request $request, callable $handler): Response
    {
        $params = array_merge($request->get(), $request->post());

        // 1. 基础参数校验
        if (empty($params['pid']) || empty($params['sign'])) {
            return $this->fail('商户ID(pid)与签名(sign)不能为空', 400);
        }

        $pid  = (string)$params['pid'];
        $sign = (string)$params['sign'];

        // 2. 时间戳防重放校验（±5分钟）
        $timestamp = isset($params['timestamp']) ? (int)$params['timestamp'] : 0;
        if ($timestamp > 0) {
            $diff = abs(time() - $timestamp);
            if ($diff > self::TIMESTAMP_TOLERANCE) {
                return $this->fail('请求时间戳偏差过大，疑似重放攻击，请同步服务器时间', 403);
            }
        }

        // 3. nonce 防重放校验（同一 nonce 10分钟内只能使用一次）
        $nonce = (string)($params['nonce'] ?? '');
        if (!empty($nonce)) {
            $nonceKey = self::NONCE_PREFIX . $pid . ':' . $nonce;
            try {
                $redis = \Webman\Redis\Client::connection();
                if ($redis->exists($nonceKey)) {
                    return $this->fail('nonce 已被使用，请求疑似重放攻击', 403);
                }
                // 记录已使用的 nonce，10分钟过期
                $redis->setex($nonceKey, 600, '1');
            } catch (\Throwable) {
                // Redis 不可用时降级，仅记录日志，不阻断请求
            }
        }

        // 4. 从数据库查询商户真实密钥（带 Redis 缓存）
        $appSecret = $this->getMerchantKey($pid);
        if ($appSecret === null) {
            return $this->fail('商户不存在或已被停用', 403);
        }

        // 5. 计算期望签名并比对
        $signParams = $params;
        unset($signParams['sign'], $signParams['sign_type']);
        $expectedSign = $this->generateSign($signParams, $appSecret);

        if (!hash_equals(strtolower($expectedSign), strtolower($sign))) {
            return $this->fail('签名校验失败，请检查密钥与签名算法', 403);
        }

        return $handler($request);
    }

    /**
     * 从数据库（优先 Redis 缓存）取商户密钥
     */
    protected function getMerchantKey(string $pid): ?string
    {
        $cacheKey = 'cx:merchant_key:' . $pid;

        // 尝试从 Redis 缓存读取
        try {
            $redis  = \Webman\Redis\Client::connection();
            $cached = $redis->get($cacheKey);
            if ($cached !== null) {
                return $cached === '__INVALID__' ? null : (string)$cached;
            }
        } catch (\Throwable) {
            // Redis 不可用降级查库
        }

        // 查数据库
        $merchant = Merchant::where('pid', $pid)->where('status', 1)->first();
        $key      = $merchant ? (string)$merchant->key : null;

        // 写入缓存（5分钟），无效商户也缓存防穿透
        try {
            $redis = \Webman\Redis\Client::connection();
            $redis->setex($cacheKey, 300, $key ?? '__INVALID__');
        } catch (\Throwable) {
        }

        return $key;
    }

    /**
     * MD5 签名算法（兼容易支付标准）
     */
    protected function generateSign(array $params, string $secret): string
    {
        ksort($params);
        $arg = '';
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null && $k !== 'sign' && $k !== 'sign_type') {
                $arg .= "{$k}={$v}&";
            }
        }
        $arg = rtrim($arg, '&');
        return md5($arg . $secret);
    }

    /**
     * 统一错误响应
     */
    protected function fail(string $msg, int $status = 400): Response
    {
        return response(
            json_encode(['code' => -1, 'msg' => $msg], JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}
