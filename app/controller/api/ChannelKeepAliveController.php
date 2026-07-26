<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\AlipayKeepAliveService;
use Throwable;

/**
 * 支付通道防掉线心跳检测 API 控制器
 */
class ChannelKeepAliveController
{
    protected AlipayKeepAliveService $keepAliveService;

    public function __construct()
    {
        $this->keepAliveService = new AlipayKeepAliveService();
    }

    /**
     * 手动/定时触发支付宝与通道心跳检测 /api/channel/keepalive
     */
    public function keepalive()
    {
        $res = $this->keepAliveService->detectAllChannels();
        if (function_exists('json')) {
            return json($res);
        }
        return json_encode($res, JSON_UNESCAPED_UNICODE);
    }
}
