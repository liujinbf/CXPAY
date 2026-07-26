<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\payment\PaymentManager;
use support\Authcode;
use support\Response;
use Exception;

/**
 * 通道动态参数与 inputs 表单配置控制 API
 */
class ChannelAdminController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 获取指定 c_type 驱动的 inputs 动态表单项定义
     */
    public function getConfigInputs(object $request): string
    {
        $cType = $request->get('c_type') ?? $request->post('c_type') ?? '';

        if (empty($cType) || !PaymentManager::has($cType)) {
            return json_encode([
                'code' => 1,
                'data' => [
                    'c_type' => $cType,
                    'inputs' => [
                        ['name' => 'pid', 'title' => '商户 PID / 账号', 'type' => 'string'],
                        ['name' => 'key', 'title' => '私钥 / 密钥 KEY', 'type' => 'textarea'],
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);
        }

        $driver = PaymentManager::make($cType);
        $meta   = $driver->getMeta();

        return json_encode([
            'code' => 1,
            'data' => [
                'c_type' => $cType,
                'title'  => $meta['title'] ?? $cType,
                'inputs' => $meta['inputs'] ?? [],
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}
