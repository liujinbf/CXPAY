<?php

namespace app\common\library\payment\lib;

use app\core\CloudClient;

/**
 * 协议云端客户端（ProtocolCloud）
 *
 * 封装与 cloud 授权站的 yydopen.* 协议通信（走 CloudClient）。
 * 替代旧的 CloudsYydopen，作为共享库供所有协议类通道使用。
 */
class ProtocolCloud
{
    /**
     * 健康检查
     */
    public function health(): array
    {
        $result = CloudClient::request('yydopen.health');
        return $this->normalizeResponse($result);
    }

    /**
     * 创建扫码登录会话
     *
     * @param bool $as_base64 是否返回base64编码的二维码
     * @return array ['code'=>1,'session_id'=>'...','qr_base64'=>'...']
     */
    public function getqrlogin(bool $as_base64 = true): array
    {
        $result = CloudClient::request('yydopen.getqrlogin', [
            'as_base64' => $as_base64,
        ]);
        return $this->normalizeResponse($result);
    }

    /**
     * 轮询扫码状态
     *
     * @param string $session_id 会话ID
     * @return array ['code'=>1,'status'=>'pending|scanned|confirmed|expired|cancelled','data'=>...]
     */
    public function pollQr(string $session_id): array
    {
        // pollQr 是长轮询请求，云端会保持连接打开等待用户扫码
        // 需要更长的超时时间（60秒），避免 HTTP 客户端提前超时
        $result = CloudClient::request('yydopen.pollQr', [
            'session_id' => $session_id,
        ], 60); // 60秒超时
        return $this->normalizeResponse($result);
    }

    /**
     * 确认扫码登录并获取账号信息
     *
     * @param string $session_id 会话ID
     * @return array ['code'=>1,'openid'=>'...','uin'=>'...','nickname'=>'...']
     */
    public function confirmQr(string $session_id): array
    {
        $result = CloudClient::request('yydopen.confirmQr', [
            'session_id' => $session_id,
        ]);
        return $this->normalizeResponse($result);
    }

    /**
     * 获取账号列表
     *
     * @return array ['code'=>1,'accounts'=>[...]]
     */
    public function getAccounts(): array
    {
        $result = CloudClient::request('yydopen.getAccounts');
        return $this->normalizeResponse($result);
    }

    /**
     * 刷新账号状态
     *
     * @param string $ref 账号引用（uin或openid）
     * @return array ['code'=>1,'status'=>'...']
     */
    public function refreshAccount(string $ref): array
    {
        $result = CloudClient::request('yydopen.refreshAccount', [
            'ref' => $ref,
        ]);
        return $this->normalizeResponse($result);
    }

    /**
     * 重新同步账号资料
     *
     * @param string $ref 账号引用（uin或openid）
     * @return array ['code'=>1,'uin'=>'...','nickname'=>'...']
     */
    public function resyncAccount(string $ref): array
    {
        $result = CloudClient::request('yydopen.resyncAccount', [
            'ref' => $ref,
        ]);
        return $this->normalizeResponse($result);
    }

    /**
     * 删除账号
     *
     * @param string $ref 账号引用（uin或openid）
     * @return array ['code'=>1]
     */
    public function deleteAccount(string $ref): array
    {
        $result = CloudClient::request('yydopen.deleteAccount', [
            'ref' => $ref,
        ]);
        return $this->normalizeResponse($result);
    }

    /**
     * 获取小程序code（用于换取SID）
     *
     * @param string $ref 账号引用（uin或openid）
     * @param string $app_id 小程序app_id
     * @return array ['code'=>1,'wxapp_code'=>'...']
     */
    public function getWxappCode(string $ref, string $app_id): array
    {
        $result = CloudClient::request('yydopen.getWxappCode', [
            'ref' => $ref,
            'app_id' => $app_id,
        ]);

        $normalized = $this->normalizeResponse($result);

        // yydopen API 返回格式：data.result.code
        // 需要提取到 wxapp_code 字段供 WeChatProtocolCloud 使用
        if ($normalized['code'] === 1 && isset($normalized['data']['result']['code'])) {
            $normalized['data']['wxapp_code'] = $normalized['data']['result']['code'];
        }

        return $normalized;
    }

    /**
     * 换取 SID：通过 cloud 端调用微信官方 API 换取 SID
     *
     * @param string $wxapp_code_or_ref 小程序 code（book）或 账号ref（recpt）
     * @param string $app_type 应用类型（book 或 recpt）
     * @return array ['code'=>1,'data'=>['sid'=>'...','openid'=>'...']]
     */
    public function exchangeSid(string $wxapp_code_or_ref, string $app_type): array
    {
        $params = ['app_type' => $app_type];
        if ($app_type === 'book') {
            $params['wxapp_code'] = $wxapp_code_or_ref;
        } else {
            // recpt: 云端直接从 yydopen 获取新鲜 code
            $params['ref'] = $wxapp_code_or_ref;
        }

        $result = CloudClient::request('yydopen.exchangeSid', $params);
        return $this->normalizeResponse($result);
    }

    /**
     * 获取手机号
     *
     * @param string $ref 账号引用（uin或openid）
     * @param string $app_id 小程序app_id
     * @return array ['code'=>1,'phone'=>'...']
     */
    public function getPhoneNumber(string $ref, string $app_id): array
    {
        $result = CloudClient::request('yydopen.getPhoneNumber', [
            'ref' => $ref,
            'app_id' => $app_id,
        ]);
        return $this->normalizeResponse($result);
    }

    /**
     * 执行小程序云函数
     *
     * @param string $ref 账号引用（uin或openid）
     * @param string $app_id 小程序app_id
     * @param array $payload 云函数参数
     * @return array ['code'=>1,'data'=>...]
     */
    public function operateWxData(string $ref, string $app_id, array $payload): array
    {
        $result = CloudClient::request('yydopen.operateWxData', [
            'ref' => $ref,
            'app_id' => $app_id,
            'payload' => $payload,
        ]);
        return $this->normalizeResponse($result);
    }

    /**
     * 规范化响应格式：将 CloudClient 返回的 ['ok'=>bool,'msg'=>'...','data'=>...]
     * 转为插件统一格式 ['code'=>1/-1,'msg'=>'...','data'=>...] （兼容旧 Clouds*）
     */
    private function normalizeResponse($result): array
    {
        if (!is_array($result)) {
            return ['code' => -1, 'msg' => '云端响应格式错误'];
        }

        $ok = $result['ok'] ?? false;
        $code = $ok ? 1 : -1;

        return [
            'code' => $code,
            'msg' => $result['msg'] ?? ($ok ? 'success' : '云端错误'),
            'data' => $result['data'] ?? null,
        ] + $result; // 保留原始所有字段
    }
}
