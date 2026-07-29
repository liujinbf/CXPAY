<?php

namespace app\common\library;

/**
 * 支付签名 / 商户回调 工具类
 *
 * 从旧系统 protected/lib/CoreClass/Payinc.php 逐字节移植：
 *   makeSign / verifySign / verifyNotify / creat_callback / callback_notify
 *
 * ⚠ 资金红线：以下算法必须与旧系统 100% 一致，任何改动都会导致
 *   与已对接商户 / 上游支付方的签名不匹配、对账失败。改动前须跑
 *   tests 里的新旧比对用例。
 */
class Sign
{
    /**
     * 生成签名（MD5）
     *
     * 规则（与旧 makeSign 完全一致）：
     *   1. 按 key 升序 ksort
     *   2. 跳过 sign / sign_type 及框架路由参数 a/c/m/s，且值不为空串
     *   3. 拼接 k=v&，去掉末尾 &，尾部追加商户 key，md5
     *
     * 注意：这里的 $v != '' 使用与旧代码相同的「宽松比较」，
     *   PHP8 下 0 != '' 为 true（0 会被计入），务必保持不变。
     *
     * @param array  $param 参与签名的参数
     * @param string $key   商户密钥
     * @return string 32 位小写 md5
     */
    public static function makeSign(array $param, string $key): string
    {
        ksort($param);
        reset($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != 'sign' && $k != 'sign_type' && $k != 'a' && $k != 'c' && $k != 'm' && $k != 's' && $v != '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        $signstr .= $key;

        return md5($signstr);
    }

    /**
     * 校验签名
     *
     * @param array  $data 含 sign 的参数集
     * @param string $key  商户密钥
     * @return bool
     */
    public static function verifySign(array $data, string $key): bool
    {
        if (!isset($data['sign'])) {
            return false;
        }

        return self::makeSign($data, $key) === $data['sign'];
    }

    /**
     * 异步回调验签（默认取 $_GET，可显式传入以便测试）
     *
     * @param string     $key    商户密钥
     * @param array|null $params 为 null 时读取 $_GET
     * @return bool
     */
    public static function verifyNotify(string $key, ?array $params = null): bool
    {
        $params ??= $_GET;

        if (empty($params) || !isset($params['sign'])) {
            return false;
        }

        return self::makeSign($params, $key) === $params['sign'];
    }

    /**
     * 生成商户异步/同步回调 URL 与 postdata
     *
     * 遵循易支付通用接口：固定字段集、money 保留 2 位小数字符串(如 "1.00")、
     * trade_status=TRADE_SUCCESS、sign_type=MD5。
     * （旧系统用 (float) 会得到 "1"，与标准易支付商户端按 2 位小数重建签名不符→验证失败，故改为 2 位小数。）
     *
     * @param array  $data 订单行（pid/trade_no/out_trade_no/type/name/money/param/notify_url/return_url/tid）
     * @param string $key  商户密钥
     * @return array{postdata:string,post:string,notify:string,return:string}
     */
    public static function creatCallback(array $data, string $key): array
    {
        $array = [
            'pid'          => $data['pid'],
            'trade_no'     => $data['trade_no'],
            'out_trade_no' => $data['out_trade_no'],
            'type'         => $data['type'],
            'name'         => $data['name'],
            'money'        => number_format((float) $data['money'], 2, '.', ''),
            'trade_status' => 'TRADE_SUCCESS',
        ];
        if (!empty($data['param'])) {
            $array['param'] = $data['param'];
        }
        $array['sign']      = self::makeSign($array, $key);
        $array['sign_type'] = 'MD5';

        $query_str = http_build_query($array);

        $url             = [];
        $url['postdata'] = $query_str;
        $url['post']     = $query_str;

        $url['notify'] = $data['notify_url'] . (strpos($data['notify_url'], '?') !== false ? '&' : '?') . $query_str;
        $url['return'] = $data['return_url'] . (strpos($data['return_url'], '?') !== false ? '&' : '?') . $query_str;

        if (!empty($data['tid']) && $data['tid'] > 0) {
            $url['return'] = $data['return_url'];
        }

        return $url;
    }

    /**
     * 判断商户回调响应是否为成功
     *
     * 与旧 callback_notify 一致：包含 success（不区分大小写）/ 成功 / 自定义值 即视为成功。
     *
     * @param string $data  商户返回内容
     * @param string $value 额外成功标识
     * @return bool
     */
    public static function callbackNotify(string $data, string $value = 'success'): bool
    {
        return stripos($data, 'success') !== false
            || strpos($data, '成功') !== false
            || strpos($data, $value) !== false;
    }
}
