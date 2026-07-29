<?php

namespace app\common\library\payment\lib;

/**
 * 彩虹易支付 SDK 服务类（跨插件共享）
 *
 * 逐字节移植自旧 peakpay/protected/lib/OpenClass/EpayCore.class.php。
 * 包含发起支付、查询订单、回调验签等功能。
 *
 * 移植改动说明（网络/加密逻辑逐字节保留）：
 *   - 命名空间化：app\common\library\payment\lib。
 *   - 新增 verifyParams(array $params): bool —— 用与旧 getSign 完全一致的算法验签，
 *     供 Driver::verifyCallbackSign 使用（旧 verifyNotify/verifyReturn 依赖 $_GET，此处改为接收参数）。
 *   - 保留旧 verifyNotify()/verifyReturn()（仍读 $_GET）以维持兼容，Driver 不使用它们。
 *   - getSign / getHttpResponse / buildRequestParam 逻辑一字未改。
 *
 * @see /www/wwwroot/peakpay/protected/lib/OpenClass/EpayCore.class.php 原始实现
 */
class EpayCore
{
    private $pid;
    private $key;
    private $submit_url;
    private $mapi_url;
    private $api_url;
    private $sign_type = 'MD5';

    public function __construct($config)
    {
        $this->pid = $config['pid'] ?? '';
        $this->key = $config['key'] ?? '';
        $apiurl = $config['apiurl'] ?? '';
        $this->submit_url = $apiurl . 'submit.php';
        $this->mapi_url = $apiurl . 'mapi.php';
        $this->api_url = $apiurl . 'api.php';
    }

    // 发起支付（页面跳转）
    public function pagePay($param_tmp, $button = '正在跳转')
    {
        $param = $this->buildRequestParam($param_tmp);

        $html = '<form id="dopay" action="' . $this->submit_url . '" method="post">';
        foreach ($param as $k => $v) {
            $html .= '<input type="hidden" name="' . $k . '" value="' . $v . '"/>';
        }
        $html .= '<input type="submit" value="' . $button . '"></form><script>document.getElementById("dopay").submit();</script>';

        return $html;
    }

    // 发起支付（获取链接）
    public function getPayLink($param_tmp)
    {
        $param = $this->buildRequestParam($param_tmp);
        $url = $this->submit_url . '?' . http_build_query($param);
        return $url;
    }

    // 发起支付（API接口）
    public function apiPay($param_tmp)
    {
        $param = $this->buildRequestParam($param_tmp);
        $response = $this->getHttpResponse($this->mapi_url, http_build_query($param));
        $arr = json_decode($response, true);
        return $arr;
    }

    // 异步回调验证（旧：读 $_GET，保留兼容）
    public function verifyNotify()
    {
        if (empty($_GET)) return false;

        $sign = $this->getSign($_GET);

        if ($sign === $_GET['sign']) {
            $signResult = true;
        } else {
            $signResult = false;
        }

        return $signResult;
    }

    // 同步回调验证（旧：读 $_GET，保留兼容）
    public function verifyReturn()
    {
        if (empty($_GET)) return false;

        $sign = $this->getSign($_GET);

        if ($sign === $_GET['sign']) {
            $signResult = true;
        } else {
            $signResult = false;
        }

        return $signResult;
    }

    /**
     * 回调验签（新，接收参数版）：用与旧 getSign 完全一致的算法。
     * ksort → k=v&（排除 sign/sign_type 及空值）→ 尾拼 key → md5，比对 params['sign']。
     */
    public function verifyParams(array $params): bool
    {
        if (empty($params) || !isset($params['sign'])) {
            return false;
        }
        $sign = $this->getSign($params);
        return $sign === $params['sign'];
    }

    // 查询订单支付状态
    public function orderStatus($trade_no)
    {
        $result = $this->queryOrder($trade_no);
        if ($result['status'] == 1) {
            return true;
        } else {
            return false;
        }
    }

    // 查询订单
    public function queryOrder($trade_no)
    {
        $url = $this->api_url . '?act=order&pid=' . $this->pid . '&key=' . $this->key . '&trade_no=' . $trade_no;
        $response = $this->getHttpResponse($url);
        $arr = json_decode($response, true);
        return $arr;
    }

    // 订单退款
    public function refund($trade_no, $money)
    {
        $url = $this->api_url . '?act=refund';
        $post = 'pid=' . $this->pid . '&key=' . $this->key . '&trade_no=' . $trade_no . '&money=' . $money;
        $response = $this->getHttpResponse($url, $post);
        $arr = json_decode($response, true);
        return $arr;
    }

    private function buildRequestParam($param)
    {
        $mysign = $this->getSign($param);
        $param['sign'] = $mysign;
        $param['sign_type'] = $this->sign_type;
        return $param;
    }

    // 计算签名
    private function getSign($param)
    {
        ksort($param);
        reset($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $k != "sign_type" && $v != '') {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        $signstr .= $this->key;
        $sign = md5($signstr);
        return $sign;
    }

    // 请求外部资源
    private function getHttpResponse($url, $post = false, $timeout = 10)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
