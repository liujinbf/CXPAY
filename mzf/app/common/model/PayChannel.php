<?php

namespace app\common\model;

use think\Model;
use app\common\library\payment\PaymentManager;

/**
 * 通道实例模型（ba_pay_channel）
 *
 * config 字段以 authcode 加密 JSON 存库（兼容旧格式）。
 * 读写加解密统一走 PaymentManager / Authcode 兼容层，不在这里自动转换，
 * 由控制器按场景显式处理（列表不解密、编辑表单才解密）。
 */
class PayChannel extends Model
{
    protected $name = 'pay_channel';

    protected $autoWriteTimestamp = true;

    /**
     * 将明文配置数组加密为存库 JSON
     */
    public static function encryptConfig(array $plainConfig): string
    {
        $enc = \app\common\library\Authcode::legacy()->encryptArray($plainConfig);
        return json_encode($enc, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 将存库 config 解密为明文数组
     */
    public static function decryptConfig(?string $config): array
    {
        if (!$config) {
            return [];
        }
        return PaymentManager::decryptChannelConfig($config, 1);
    }
}
