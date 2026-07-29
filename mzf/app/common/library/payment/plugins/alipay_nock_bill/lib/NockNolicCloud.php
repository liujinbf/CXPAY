<?php

namespace app\common\library\payment\plugins\alipay_nock_bill\lib;

use app\core\CloudClient;

/**
 * 免CK 商家账单自动配置云端客户端
 *
 * 已改造为走 cloud 新协议：三个操作经 app\core\CloudClient::request('nock.*') 请求授权站，
 * 由 cloud 端 CloudNockService + AlipayOpenClass 完成支付宝应用免CK自动配置。
 * 云端地址/授权码/域名统一由 ba_cloud_setting 提供（不再指向 peak.h364.cn）。
 * 返回结构与老 NockNolicCloud 一致（供 api\Channel::nockNolic 调用）。
 */
class NockNolicCloud
{
    public function __construct(?string $cloudUrl = null)
    {
        // no-op：单一 cloud 授权站，云地址/授权码由 CloudClient 统一提供
    }

    /** 统一请求（透传云端详细错误，不再隐藏为通用提示） */
    protected function req(string $op, array $data, int $failCode): array
    {
        $res = CloudClient::request('nock.' . $op, $data, 120);
        if (!$res['ok']) {
            // 透传云端的详细错误（如 未配置授权码/云端不可达/域名未授权/签名校验失败/会员到期）
            $msg = $res['msg'] ?: '连接不上配置免CK服务器';
            return ['code' => $failCode, 'msg' => '免CK配置失败：' . $msg];
        }
        return $res['data'];
    }

    /**
     * 检测是否有已过审应用
     * code=1 有已过审应用 / 2 无
     */
    public function BusinessLicense(string $cookie = '', $checkCode = 0, $email = 0): array
    {
        return $this->req('BusinessLicense', ['cookie' => $cookie, 'checkCode' => $checkCode, 'email' => $email], -3);
    }

    /** 申请应用 DEV_TYPE 有值则创建并等待审核 */
    public function Create(string $cookie = '', $DEV_TYPE = ''): array
    {
        return $this->req('Create', ['cookie' => $cookie, 'DEV_TYPE' => $DEV_TYPE], -2);
    }

    /** 验证码设置公钥验证码 也是最终获取应用所有信息(alipayId/appId/appPrivateKey) */
    public function VerifyAckCode(string $cookie = '', $DEV_TYPE = '', $checkCode = '123456'): array
    {
        return $this->req('VerifyAckCode', ['cookie' => $cookie, 'DEV_TYPE' => $DEV_TYPE, 'checkCode' => $checkCode], -2);
    }
}
