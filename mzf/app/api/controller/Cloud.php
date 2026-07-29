<?php

namespace app\api\controller;

use Throwable;
use app\common\controller\Frontend;
use app\core\CloudClient;
use app\core\CloudAuth;

/**
 * 云端 → 本站 的主动通知入口。
 *
 * 后台在授权站保存 license 后，会向本站 POST /api/cloud/refresh（带 server:1 头 + 签名），
 * 本站校验签名后立即强制刷新授权缓存，使功能授权/禁用变更即时生效（无需等心跳）。
 */
class Cloud extends Frontend
{
    protected array $noNeedLogin = ['refresh'];

    protected array $noNeed2FA = ['refresh'];

    /** 时间戳容差(秒) */
    protected int $tolerance = 300;

    public function initialize(): void
    {
        parent::initialize();
    }

    public function refresh(): void
    {
        $raw    = file_get_contents('php://input');
        $params = json_decode((string) $raw, true);
        if (!is_array($params)) {
            $this->error('请求格式错误', [], 400);
        }
        $timestamp = (int) ($params['timestamp'] ?? 0);
        if (abs(time() - $timestamp) > $this->tolerance) {
            $this->error('请求已过期', [], 400);
        }
        $authcode = CloudClient::authcode();
        if ($authcode === '') {
            $this->error('本站未配置授权码', [], 403);
        }
        // 验签（secret = 本站 authcode，与授权站签名一致）
        $sign   = (string) ($params['sign'] ?? '');
        $expect = CloudClient::sign($params, $authcode); // sign() 内部会去掉 sign 字段
        if ($sign === '' || !hash_equals($expect, $sign)) {
            $this->error('签名校验失败', [], 403);
        }

        try {
            CloudAuth::refresh(true); // 强制拉取最新授权包
        } catch (Throwable $e) {
            $this->error('刷新失败: ' . $e->getMessage());
        }
        $this->success('已刷新授权');
    }
}
