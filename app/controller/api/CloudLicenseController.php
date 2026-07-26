<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\CloudLicenseService;
use app\service\CloudAuthSecurityService;
use app\service\WeChatAuthService;
use support\Response;
use Throwable;

/**
 * 云端授权中心 API 控制器 (含 QQ / 微信扫码授权与邮箱绑定)
 */
class CloudLicenseController
{
    protected CloudLicenseService $licenseService;
    protected CloudAuthSecurityService $authSecurityService;
    protected WeChatAuthService $wxAuthService;

    public function __construct()
    {
        $this->licenseService       = new CloudLicenseService();
        $this->authSecurityService = new CloudAuthSecurityService();
        $this->wxAuthService        = new WeChatAuthService();
    }

    /**
     * 发起微信扫码登录授权会话 /api/cloud/wx_login_qr
     */
    public function getWxLoginQr(): Response
    {
        $res = $this->wxAuthService->createWxLoginSession();
        return json($res);
    }

    /**
     * 轮询微信扫码状态 /api/cloud/poll_wx_login
     */
    public function pollWxLogin(object $request): Response
    {
        $sessionId = (string)($request->get('session_id') ?? $request->post('session_id') ?? '');
        $res = $this->wxAuthService->pollWxLoginSession($sessionId);
        return json($res);
    }

    public function getQqLoginQr(): Response
    {
        $res = $this->authSecurityService->createQqLoginSession();
        return json($res);
    }

    public function pollQqLogin(object $request): Response
    {
        $sessionId = (string)($request->get('session_id') ?? $request->post('session_id') ?? '');
        $res = $this->authSecurityService->pollQqLoginSession($sessionId);
        return json($res);
    }

    public function sendEmailCode(object $request): Response
    {
        $email = (string)($request->post('email') ?? '');
        $res   = $this->authSecurityService->sendEmailVerifyCode($email);
        return json($res);
    }

    public function bindQq(object $request): Response
    {
        $email      = (string)($request->post('email') ?? '');
        $verifyCode = (string)($request->post('code') ?? '');
        $qq         = (string)($request->post('qq') ?? '');

        $res = $this->authSecurityService->verifyEmailAndBindQq($email, $verifyCode, $qq);
        return json($res);
    }

    public function downloadPackage(object $request): Response
    {
        try {
            $domain = (string)($request->get('domain') ?? $request->host() ?? 'm.fcwan.cn');
            $res = $this->licenseService->buildWatermarkedDownloadPackage($domain);
            return json(['code' => 1, 'msg' => '生成水印下载包成功', 'data' => $res]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function traceLeaked(object $request): Response
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

    public function getSiteInfo(object $request): Response
    {
        $domain = (string)($request->get('domain') ?? $request->host() ?? 'm.fcwan.cn');
        $authKey = (string)($request->get('auth_key') ?? '');

        $res = $this->licenseService->validateSiteAuth($domain, $authKey, 'wx_protocol_cloud');
        return json([
            'code' => 1,
            'data' => [
                'domain'      => $domain,
                'auth_key'    => $authKey ?: 'a61463******2893',
                'status'      => 'authorized',
                'agent'       => 'XLPAY 官方代理',
                'bound_qq'    => '1008611',
                'bound_wx'    => 'wx_openid_99887766',
                'module_check'=> $res,
            ]
        ]);
    }

    public function renewModule(object $request): Response
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

    public function resetKey(object $request): Response
    {
        try {
            $domain = (string)($request->post('domain') ?? $request->host() ?? 'm.fcwan.cn');
            $newKey = $this->licenseService->resetAuthKey($domain);
            return json(['code' => 1, 'msg' => '授权 Key 重置成功！', 'new_key' => $newKey]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function changeDomain(object $request): Response
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
}
