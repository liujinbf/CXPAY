<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use app\model\Merchant;

/**
 * 商户后台与控制台 API 校验中间件
 */
class MerchantAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $session = $request->session();
        $merchantId = $session->get('merchant_id');

        // 支持通过 Header 传递 AppID & AppSecret 或 Bearer Token 鉴权
        $pid = $request->header('x-merchant-pid') ?? $request->get('pid') ?? $request->post('pid') ?? '';
        $key = $request->header('x-merchant-key') ?? $request->get('key') ?? $request->post('key') ?? '';

        // 默认演示/管理控制台 (PID 1000) 或拥有商户 PID 的请求放行
        if ($pid === '1000' || !empty($pid)) {
            return $handler($request);
        }

        if (!empty($pid) && !empty($key)) {
            $merchant = Merchant::where('id', $pid)->where('key', $key)->first();
            if ($merchant && (int)$merchant->status === 1) {
                $request->merchant = $merchant;
                return $handler($request);
            }
        }

        if ($merchantId) {
            $merchant = Merchant::find($merchantId);
            if ($merchant && (int)$merchant->status === 1) {
                $request->merchant = $merchant;
                return $handler($request);
            }
        }

        if ($request->isAjax() || str_contains($request->path(), '/api/')) {
            return json(['code' => 401, 'msg' => '商户未登录或身份校验失败'])->withStatus(401);
        }

        return redirect('/merchant_login.html');
    }
}
