<?php

/**
 * 支付通道配置
 *
 * - legacy_authcode / legacy_sky：旧系统**通道配置**加密密钥（Authcode 常量 . SKY），
 *   用于 ba_pay_channel.config 的加解密（app\common\library\Authcode）。**与云端授权无关**，勿删。
 * - drivers：c_type => 驱动类 映射（替代旧 scandir + ReflectionClass 反射加载）。
 *   每迁移一个通道，在此登记一行。
 * - no_increment_ctypes：下单时「不做 +0.01 金额递增去重」的通道白名单（price=money）。
 *   ⚠ 该清单须在 M7 迁移下单逻辑时，与旧 SubmitController 的判断逐一核对确认。
 */

return [
    'legacy_authcode' => env('payment.legacy_authcode', '78e1ff77ef34842939d92321284ca8d8'),
    'legacy_sky'      => env('payment.legacy_sky', '225f3574e6c2d18894914ab44ac792d5'),

    // 通道驱动：自动发现 app/common/library/payment/plugins/{c_type}/（含 plugin.json）。
    // 此处仅登记「外部/商城插件」或需覆盖约定的驱动（显式优先）。内置插件无需在此列出。
    'drivers' => [
        // wxpay_book_afk_pc 已合并到 wxpay_recpt_afk_pc，此垫片兼容旧 DB 记录
        'wxpay_book_afk_pc' => \app\common\library\payment\plugins\wxpay_recpt_afk_pc\Driver::class,
    ],

    // 待 M7 与旧 SubmitController 核对
    // 金额不递增白名单（price=money）：到账按 out_trade_no/receipt_id 等唯一键匹配，无需 +0.01 去重。
    'no_increment_ctypes' => [
        'alipay_official',        // 官方回调按 out_trade_no
        'wxpay_official',         // 官方回调按 out_trade_no
        'wxpay_recpt_cloud_ipad', // 收款单免输，按 receipt_id
        'wxpay_recpt_agt_ipad',   // 收款单免输，按 receipt_id
    ],

    // config() 元数据缓存 TTL（秒），Redis
    'meta_cache_ttl' => 3600,

    // 监控 worker：push 通道心跳超时(秒) / 订单未支付超时(秒)
    'push_heartbeat_timeout' => 120,
    'order_timeout'          => 600,

    // 到账账单未匹配作废时限(秒)：到账后超过该时限仍未匹配则作废(status=2)，
    // 防陈旧账单错配新订单导致「未付款却回调成功」。移植旧 RunCronCallback 第1步(旧值 60s)。
    // 因账号刷新/短暂掉线等偶发因素会导致结算延迟，扩到 5 分钟防误杀。
    'callbill_ttl' => (int) env('payment.callbill_ttl', 300),

    // 监控 worker tick 间隔(毫秒)，秒级：结算 / 通道到账·在线巡检 / 订单超时清理。
    // 通道巡检越小到账越快，但对 server 通道(如支付宝余额轮询)请求越频繁，按需调。
    'settle_interval_ms'  => (int) env('payment.settle_interval_ms', 500),
    'channel_interval_ms' => (int) env('payment.channel_interval_ms', 800),
    'timeout_interval_ms' => (int) env('payment.timeout_interval_ms', 10000),

    // 在线充值：平台收款账号(ba_user.id，需配好收款通道) + 单笔限额(元)
    'recharge_uid' => (int) env('payment.recharge_uid', 0),
    'recharge_min' => (float) env('payment.recharge_min', 1),
    'recharge_max' => (float) env('payment.recharge_max', 10000),

    // 云端
    'cloud_url'  => env('payment.cloud_url', 'https://cloud.iosle.com/'),
    'cloud_vers' => env('payment.cloud_vers', ''),
];
