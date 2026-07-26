<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 商户 API 请求签名校验中间件 (HMAC-SHA256 / MD5)
 */
class ApiAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $params = $request->get() + $request->post();

        if (empty($params['pid']) || empty($params['sign'])) {
            return response(json_encode([
                'code' => -1,
                'msg'  => '商户ID(pid)与签名(sign)不能为空',
            ], JSON_UNESCAPED_UNICODE), 400, ['Content-Type' => 'application/json']);
        }

        // 模拟从数据库/Redis 根据 pid 获取商户 app_secret
        $appSecret = 'mock_secret_123456'; 

        $sign = $params['sign'];
        unset($params['sign'], $params['sign_type']);

        $expectedSign = $this->generateSign($params, $appSecret);

        if (strtolower($sign) !== strtolower($expectedSign)) {
            return response(json_encode([
                'code' => -1,
                'msg'  => '签名校验失败，请检查密钥与签名算法',
            ], JSON_UNESCAPED_UNICODE), 403, ['Content-Type' => 'application/json']);
        }

        return $handler($request);
    }

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
}
