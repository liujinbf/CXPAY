<?php

declare(strict_types=1);

namespace support;

/**
 * 全局统一错误码定义
 *
 * 分层规则：
 *   1xxx — 通用/参数错误
 *   2xxx — 认证/权限错误
 *   3xxx — 商户相关错误
 *   4xxx — 订单相关错误
 *   5xxx — 通道/支付相关错误
 *   9xxx — 系统内部错误
 */
final class ErrorCode
{
    // ─── 通用错误 ─────────────────────────────────────────────────────────
    /** 操作成功 */
    public const OK = 1;
    /** 通用失败 */
    public const FAIL = -1;
    /** 参数缺失或格式不合法 */
    public const INVALID_PARAMS = 1001;
    /** 请求频率超限 */
    public const RATE_LIMITED = 1002;
    /** 请求来源不合法（CSRF/Origin 校验失败） */
    public const INVALID_ORIGIN = 1003;

    // ─── 认证/权限错误 ────────────────────────────────────────────────────
    /** 未登录 / Token 缺失 */
    public const UNAUTHENTICATED = 2001;
    /** Token 签名无效或已过期 */
    public const TOKEN_INVALID = 2002;
    /** Token 版本已失效（密码已修改） */
    public const TOKEN_REVOKED = 2003;
    /** 二次验证码错误或已过期 */
    public const VERIFY_CODE_INVALID = 2004;
    /** 权限不足 */
    public const FORBIDDEN = 2005;
    /** IP 不在白名单 */
    public const IP_NOT_ALLOWED = 2006;
    /** 签名校验失败 */
    public const SIGN_INVALID = 2007;

    // ─── 商户相关错误 ─────────────────────────────────────────────────────
    /** 商户不存在或已停用 */
    public const MERCHANT_NOT_FOUND = 3001;
    /** 商户余额不足 */
    public const MERCHANT_BALANCE_INSUFFICIENT = 3002;
    /** 商户 VIP 套餐已过期 */
    public const MERCHANT_VIP_EXPIRED = 3003;
    /** 商户 API 密钥错误 */
    public const MERCHANT_KEY_INVALID = 3004;

    // ─── 订单相关错误 ─────────────────────────────────────────────────────
    /** 订单不存在 */
    public const ORDER_NOT_FOUND = 4001;
    /** 商户订单号重复且属性不一致 */
    public const ORDER_DUPLICATE = 4002;
    /** 订单状态不允许当前操作 */
    public const ORDER_STATUS_INVALID = 4003;
    /** 支付金额不合法 */
    public const ORDER_AMOUNT_INVALID = 4004;
    /** 回调地址格式不合法 */
    public const ORDER_NOTIFY_URL_INVALID = 4005;
    /** 订单重发通知频率超限 */
    public const ORDER_RESEND_RATE_LIMITED = 4006;

    // ─── 通道/支付相关错误 ────────────────────────────────────────────────
    /** 暂无可用支付通道 */
    public const CHANNEL_UNAVAILABLE = 5001;
    /** 支付通道金额超出限制 */
    public const CHANNEL_AMOUNT_OUT_OF_RANGE = 5002;
    /** 今日通道额度已满 */
    public const CHANNEL_DAY_LIMIT_REACHED = 5003;
    /** 支付驱动不存在或已停用 */
    public const CHANNEL_DRIVER_NOT_FOUND = 5004;
    /** 通道识别金额已占满 */
    public const CHANNEL_PRICE_EXHAUSTED = 5005;

    // ─── 系统错误 ─────────────────────────────────────────────────────────
    /** 系统内部错误 */
    public const INTERNAL_ERROR = 9001;
    /** 依赖服务暂时不可用（Redis/DB 等） */
    public const SERVICE_UNAVAILABLE = 9002;
    /** 功能尚未实现 */
    public const NOT_IMPLEMENTED = 9003;

    /**
     * 返回错误码对应的标准描述
     */
    public static function message(int $code): string
    {
        return match ($code) {
            self::OK                          => '操作成功',
            self::FAIL                        => '操作失败',
            self::INVALID_PARAMS              => '请求参数缺失或格式不合法',
            self::RATE_LIMITED                => '请求频率超限，请稍后重试',
            self::INVALID_ORIGIN              => '请求来源校验失败',
            self::UNAUTHENTICATED             => '未登录，请先登录',
            self::TOKEN_INVALID               => 'Token 签名无效或已过期',
            self::TOKEN_REVOKED               => 'Token 已失效，请重新登录',
            self::VERIFY_CODE_INVALID         => '二次验证码错误或已过期',
            self::FORBIDDEN                   => '权限不足',
            self::IP_NOT_ALLOWED              => '当前 IP 不在白名单中',
            self::SIGN_INVALID                => '签名校验失败',
            self::MERCHANT_NOT_FOUND          => '商户不存在或已被停用',
            self::MERCHANT_BALANCE_INSUFFICIENT => '商户余额不足',
            self::MERCHANT_VIP_EXPIRED        => '商户 VIP 套餐已过期',
            self::MERCHANT_KEY_INVALID        => '商户 API 密钥错误',
            self::ORDER_NOT_FOUND             => '订单不存在',
            self::ORDER_DUPLICATE             => '商户订单号已存在且属性不一致',
            self::ORDER_STATUS_INVALID        => '当前订单状态不允许此操作',
            self::ORDER_AMOUNT_INVALID        => '支付金额不合法',
            self::ORDER_NOTIFY_URL_INVALID    => '回调地址格式不合法',
            self::ORDER_RESEND_RATE_LIMITED   => '订单重发通知频率超限',
            self::CHANNEL_UNAVAILABLE         => '暂无满足条件的可用支付通道',
            self::CHANNEL_AMOUNT_OUT_OF_RANGE => '支付金额超出通道限额',
            self::CHANNEL_DAY_LIMIT_REACHED   => '当前通道今日收款额度已满',
            self::CHANNEL_DRIVER_NOT_FOUND    => '支付驱动不存在或已停用',
            self::CHANNEL_PRICE_EXHAUSTED     => '通道可识别金额已占满',
            self::INTERNAL_ERROR              => '系统内部错误，请稍后重试',
            self::SERVICE_UNAVAILABLE         => '依赖服务暂时不可用，请稍后重试',
            self::NOT_IMPLEMENTED             => '功能尚未实现',
            default                           => '未知错误',
        };
    }

    /**
     * 构造标准 JSON 响应数组
     *
     * @param int    $code    错误码（ErrorCode 常量）
     * @param string $msg     覆盖默认描述（为空则使用 message($code)）
     * @param mixed  $data    附带数据（仅成功时下发）
     */
    public static function response(int $code, string $msg = '', mixed $data = null): array
    {
        $response = [
            'code' => $code,
            'msg'  => $msg !== '' ? $msg : self::message($code),
        ];
        if ($data !== null) {
            $response['data'] = $data;
        }
        return $response;
    }
}
