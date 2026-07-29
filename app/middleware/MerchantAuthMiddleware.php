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
        if (!$this->isSameOrigin($request)) {
            return json(['code' => 403, 'msg' => '请求来源校验失败'])->withStatus(403);
        }
        $session = $request->session();
        $merchantId = $session->get('merchant_id');

        if ($merchantId) {
            $merchant = Merchant::find($merchantId);
            if ($merchant && (int)$merchant->status === 1) {
                $request->context['merchant'] = $merchant;
                return $handler($request);
            }
        }

        if ($request->isAjax() || str_contains($request->path(), '/api/')) {
            return json(['code' => 401, 'msg' => '商户未登录或身份校验失败'])->withStatus(401);
        }

        return redirect('/merchant_login.html');
    }

    private function isSameOrigin(Request $request): bool
    {
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $origin = trim((string)$request->header('origin'));
        if ($origin === '') {
            return true;
        }
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $requestHost = strtolower(explode(':', $request->host())[0]);
        return $originHost !== '' && hash_equals($requestHost, $originHost);
    }
}
