<?php

declare(strict_types=1);

namespace app\middleware;

use app\model\Merchant;
use Webman\Http\Response;
use Webman\Http\Request;
use Webman\MiddlewareInterface;
use support\IpWhitelist;

/**
 * 商户开放 API 签名校验中间件。
 */
class ApiAuthMiddleware implements MiddlewareInterface
{
    /** 时间戳允许偏差（秒） */
    private const TIMESTAMP_TOLERANCE = 300;

    /** nonce 有效期（秒） */
    private const NONCE_TTL = 600;

    private const NONCE_PREFIX = 'cx:nonce:';

    public function process(Request $request, callable $handler): Response
    {
        $params = $request->get() + $request->post();
        $pid = trim((string)($params['pid'] ?? ''));
        $sign = trim((string)($params['sign'] ?? ''));
        $timestampRaw = $params['timestamp'] ?? null;
        $nonce = trim((string)($params['nonce'] ?? ''));

        if ($pid === '' || $sign === '' || $timestampRaw === null || $nonce === '') {
            return $this->fail('pid、sign、timestamp 与 nonce 均不能为空', 400);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pid)) {
            return $this->fail('商户ID格式不正确', 400);
        }
        if (!preg_match('/^[a-fA-F0-9]{32}$/', $sign)) {
            return $this->fail('签名格式不正确', 400);
        }
        if (!is_scalar($timestampRaw) || !preg_match('/^\d{10}$/', (string)$timestampRaw)) {
            return $this->fail('timestamp 必须是秒级 Unix 时间戳', 400);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            return $this->fail('nonce 必须为16至128位字母、数字、下划线或短横线', 400);
        }
        if (abs(time() - (int)$timestampRaw) > self::TIMESTAMP_TOLERANCE) {
            return $this->fail('请求时间戳偏差过大，请同步服务器时间', 403);
        }
        if (!$this->containsOnlyScalarValues($params)) {
            return $this->fail('签名参数只允许标量值', 400);
        }

        $merchant = Merchant::where('pid', $pid)->where('status', 1)->first();
        if (!$merchant || trim((string)$merchant->key) === '') {
            return $this->fail('商户不存在或已被停用', 403);
        }
        if (!IpWhitelist::allows($request->getRemoteIp(), (string)($merchant->ip_white ?? ''))) {
            return $this->fail('当前请求 IP 不在商户白名单中', 403);
        }

        $signParams = $params;
        unset($signParams['sign'], $signParams['sign_type']);
        $expectedSign = $this->generateSign($signParams, (string)$merchant->key);
        if (!hash_equals($expectedSign, strtolower($sign))) {
            return $this->fail('签名校验失败，请检查密钥与签名算法', 403);
        }

        // 必须在验签成功后原子占用 nonce，防止伪造请求抢占合法 nonce。
        try {
            $redis = \Webman\Redis\Client::connection();
            $nonceKey = self::NONCE_PREFIX . $pid . ':' . $nonce;
            $stored = $redis->set($nonceKey, '1', ['NX', 'EX' => self::NONCE_TTL]);
        } catch (\Throwable $e) {
            error_log('[ApiAuthMiddleware] Redis nonce 校验失败: ' . $e->getMessage());
            return $this->fail('防重放服务暂时不可用，请稍后重试', 503);
        }
        if ($stored !== true && $stored !== 'OK') {
            return $this->fail('nonce 已被使用，请勿重复提交', 409);
        }

        $request->context['merchant'] = $merchant;
        return $handler($request);
    }

    /**
     * MD5 签名算法（兼容易支付参数排序规范）。
     */
    protected function generateSign(array $params, string $secret): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null && $key !== 'sign' && $key !== 'sign_type') {
                $pairs[] = $key . '=' . (string)$value;
            }
        }
        return md5(implode('&', $pairs) . $secret);
    }

    private function containsOnlyScalarValues(array $params): bool
    {
        foreach ($params as $value) {
            if ($value !== null && !is_scalar($value)) {
                return false;
            }
        }
        return true;
    }

    private function fail(string $message, int $status): Response
    {
        return response(
            json_encode(['code' => -1, 'msg' => $message], JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}
