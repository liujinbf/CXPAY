<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\CloudLicenseService;
use support\Response;
use Throwable;

/**
 * 云端授权中心 API 控制器 (含 QQ / 微信扫码授权与邮箱绑定)
 */
class CloudLicenseController
{
    protected CloudLicenseService $licenseService;

    public function __construct()
    {
        $this->licenseService = new CloudLicenseService();
    }

    /**
     * 发起微信扫码登录授权会话 /api/cloud/wx_login_qr
     */
    public function getWxLoginQr(): Response
    {
        return $this->providerUnavailable('微信扫码登录');
    }

    /**
     * 轮询微信扫码状态 /api/cloud/poll_wx_login
     */
    public function pollWxLogin(\support\Request $request): Response
    {
        return $this->providerUnavailable('微信扫码登录');
    }

    public function getQqLoginQr(): Response
    {
        return $this->providerUnavailable('QQ扫码登录');
    }

    public function pollQqLogin(\support\Request $request): Response
    {
        return $this->providerUnavailable('QQ扫码登录');
    }

    public function sendEmailCode(\support\Request $request): Response
    {
        return $this->providerUnavailable('邮件验证码');
    }

    public function bindQq(\support\Request $request): Response
    {
        return $this->providerUnavailable('邮件绑定');
    }

    public function downloadPackage(\support\Request $request): Response
    {
        try {
            $domain = (string)($request->get('domain') ?? $request->host() ?? 'm.fcwan.cn');
            $res = $this->licenseService->buildWatermarkedDownloadPackage($domain);
            return json(['code' => 1, 'msg' => '生成水印下载包成功', 'data' => $res]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function traceLeaked(\support\Request $request): Response
    {
        try {
            $codeSnippet = (string)($request->post('code') ?? '');
            if (empty($codeSnippet)) {
                return json(['code' => -1, 'msg' => '请粘贴泄漏的代码片段']);
            }

            $res = $this->licenseService->traceAndBanLeakedCode($codeSnippet);
            return json(['code' => 1, 'data' => $res]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function getSiteInfo(\support\Request $request): Response
    {
        $domain = strtolower(trim((string)($request->get('domain') ?? $request->host()), " .\t\n\r\0\x0B"));
        $authKey = (string)($request->get('auth_key') ?? '');

        if (!$this->validDomain($domain) || $authKey === '') {
            return json(['code' => -1, 'msg' => 'domain 与 auth_key 参数不合法'])->withStatus(400);
        }

        $res = $this->licenseService->validateSiteAuth($domain, $authKey, 'wx_protocol_cloud');
        return json([
            'code' => !empty($res['valid']) ? 1 : -1,
            'data' => [
                'domain'      => $domain,
                'status'      => !empty($res['valid']) ? 'authorized' : 'unauthorized',
                'module_check'=> $res,
            ]
        ]);
    }

    public function renewModule(\support\Request $request): Response
    {
        try {
            $domain    = (string)($request->post('domain') ?? $request->host() ?? 'm.fcwan.cn');
            $moduleKey = (string)($request->post('module_key') ?? 'wx_protocol_cloud');
            $pkgType   = (string)($request->post('pkg_type') ?? 'month');

            $res = $this->licenseService->renewModuleSubscription($domain, $moduleKey, $pkgType);
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function resetKey(\support\Request $request): Response
    {
        try {
            $domain = (string)($request->post('domain') ?? $request->host() ?? 'm.fcwan.cn');
            $newKey = $this->licenseService->resetAuthKey($domain);
            return json(['code' => 1, 'msg' => '授权 Key 重置成功！', 'new_key' => $newKey]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function changeDomain(\support\Request $request): Response
    {
        try {
            $oldDomain = (string)($request->post('old_domain') ?? '');
            $newDomain = (string)($request->post('new_domain') ?? '');

            if (empty($oldDomain) || empty($newDomain)) {
                return json(['code' => -1, 'msg' => '原域名与新域名不能为空']);
            }

            $this->licenseService->changeDomain($oldDomain, $newDomain);
            return json(['code' => 1, 'msg' => "授权域名已成功更换为 [{$newDomain}]！"]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function providerUnavailable(string $provider): Response
    {
        return json(['code' => -1, 'msg' => "{$provider}尚未配置真实服务，功能暂不可用"])->withStatus(501);
    }

    private function validDomain(string $domain): bool
    {
        return (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain);
    }
}
