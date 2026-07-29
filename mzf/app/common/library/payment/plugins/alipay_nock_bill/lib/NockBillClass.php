<?php

namespace app\common\library\payment\plugins\alipay_nock_bill\lib;

/**
 * 支付宝免CK商家账单（手动配置 alipay_nock_bill）协议类
 *
 * 逐字节移植自旧 protected/payplug/alipay_nock_bill/class/nock_bill.php（类名 nock_bill_class）。
 * 直连支付宝官方账单接口 alipay.data.bill.accountlog.query（RSA2 签名 + openapi 网关），
 * 不依赖任何云服务，用商户 app_id + 应用私钥。
 *   - check_user：校验 app_id/私钥（心跳）
 *   - url_postdate：构造账单查询请求(url/post)
 *   - query_accountlog：url_postdate + Get_curl 取账单原始响应（monitor 复用，网络逻辑走原 Get_curl 重试）
 *
 * 网络/加密（getSignContent/Get_curl）逻辑逐字节保留，仅命名空间化。
 */
class NockBillClass
{
    public function __construct($zero = null)
    {
    }

    // 检测配置是否正常
    public function check_user($app_id = 0, $privateKey = 0)
    {
        $AliCurlDatas = array("app_id" => $app_id, "version" => '1.0', "format" => 'json', "sign_type" => 'RSA2', "method" => 'alipay.data.bill.accountlog.query', "timestamp" => date("Y-m-d H:i:s"), "alipay_sdk" => 'alipay-sdk-PHP-4.20.61.ALL', "charset" => 'UTF-8',);
        $startTime = 66; //获取多少秒内的账单
        $biz_content = array("biz_content" => '{"start_time":"' . date("Y-m-d H:i:s", time() - $startTime) . '","end_time":"' . date("Y-m-d ") . '23:59:59"}');
        $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" . $privateKey . "\n-----END RSA PRIVATE KEY-----";
        openssl_sign($this->getSignContent(array_merge($AliCurlDatas, $biz_content)), $signature, $privateKey, OPENSSL_ALGO_SHA256);// 生成签名
        $AliCurlDatas['sign'] = base64_encode($signature);// $signature 就是生成的签名，可以将其Base64编码后传输
        $url = 'https://openapi.alipay.com/gateway.do?' . http_build_query($AliCurlDatas);
        $post = http_build_query($biz_content);
        $data = $this->Get_curl($url, $post, 'https://www.alipay.com', 0, 0, 0);
        return $data;
    }

    // 请求账单数据参数
    public function url_postdate($app_id = 0, $privateKey = 0)
    {
        $AliCurlDatas = array("app_id" => $app_id, "version" => '1.0', "format" => 'json', "sign_type" => 'RSA2', "method" => 'alipay.data.bill.accountlog.query', "timestamp" => date("Y-m-d H:i:s"), "alipay_sdk" => 'alipay-sdk-PHP-4.20.61.ALL', "charset" => 'UTF-8',);

        // 智能时间范围策略：根据当前时间动态调整查询范围
        $currentMinute = (int)date('i');
        $currentSecond = (int)date('s');

        // 基础查询时间：1分钟，减少系统资源占用
        $baseTime = 60;

        // 每3分钟进行一次扩展查询，防止掉单
        if ($currentMinute % 3 == 0 && $currentSecond < 30) {
            // 每3分钟的前30秒，扩展查询范围到2分钟
            $startTime = 120;
        } else {
            // 其他时间使用基础查询范围
            $startTime = $baseTime;
        }

        $biz_content = array("biz_content" => '{"start_time":"' . date("Y-m-d H:i:s", time() - $startTime) . '","end_time":"' . date("Y-m-d H:i:s") . '"}');
        $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" . $privateKey . "\n-----END RSA PRIVATE KEY-----";
        openssl_sign($this->getSignContent(array_merge($AliCurlDatas, $biz_content)), $signature, $privateKey, OPENSSL_ALGO_SHA256);// 生成签名
        $AliCurlDatas['sign'] = base64_encode($signature);// $signature 就是生成的签名，可以将其Base64编码后传输
        $data['url'] = 'https://openapi.alipay.com/gateway.do?' . http_build_query($AliCurlDatas);
        $data['post'] = http_build_query($biz_content);
        return $data;
    }

    /**
     * 取账单原始响应（monitor 复用）：等价旧监控 worker 拿 url_postdate 后自行 CURL。
     * 网络请求仍走原 Get_curl（含 3 次重试 / 伪造 IP 头），逻辑不改。
     */
    public function query_accountlog($app_id = 0, $privateKey = 0)
    {
        $req = $this->url_postdate($app_id, $privateKey);
        return $this->Get_curl($req['url'], $req['post'], 'https://www.alipay.com', 0, 0, 0);
    }

    protected function getSignContent($params)
    {
        ksort($params);
        unset($params['sign']);
        $stringToBeSigned = "";
        $i = 0;
        foreach ($params as $k => $v) {
            if ("@" != substr($v, 0, 1)) {

                // 转换成目标字符集
                $v = iconv('gb2312', 'utf-8', $v);
                if ($i == 0) {
                    $stringToBeSigned .= "$k" . "=" . "$v";
                } else {
                    $stringToBeSigned .= "&" . "$k" . "=" . "$v";
                }
                $i++;
            }
        }
        unset($k, $v);
        return $stringToBeSigned;
    }

    /**
     * 用支付宝网页版 Cookie 取头像/昵称（自动配置扫码登录后展示用）。移植自 nock_bill_nolic。
     */
    public function get_user_pic($cookie = null)
    {
        $check_user = $this->get_user($cookie);
        $user_pic   = '';
        if (($check_user['stat'] ?? '') == 'deny' || ($check_user['stat'] ?? '') == 'fail' || !$check_user) {
            return array("code" => -1, "msg" => "cookie失效,请重新获取");
        }
        foreach ([
            'https://personalweb.alipay.com/account/security.htm?_output_charset=utf-8',
            'https://custweb.alipay.com/account/index.htm?_output_charset=utf-8',
            'https://shanghu.alipay.com/home/switchPersonal.htm?_output_charset=utf-8',
        ] as $url) {
            if ($user_pic) break;
            $data        = $this->Get_curl($url, 0, 'https://authsa128.alipay.com/', $cookie, 0);
            $portraitPath = $this->getSubstr((string) $data, "portraitPath: '/", "',");
            if ($portraitPath) $user_pic = '//tfs.alipayobjects.com/' . $portraitPath;
        }
        if (!$user_pic || mb_strlen($user_pic, "UTF-8") > 100) {
            $user_pic = '/Assets/Icon/alipay.jpeg';
        }
        return array(
            'code'     => 1,
            'user_pic' => $user_pic,
            'nickname' => $check_user['data']['logonName'] ?? '',
        );
    }

    // 检测 cookie 是否正常 以及获取支付宝一些数据
    public function get_user($cookie = 0)
    {
        $url  = 'https://enterpriseportal.alipay.com/pamir/login/queryLoginAccount.json?_output_charset=utf-8&appScene=MRCH';
        $data = $this->Get_curl($url, 0, 'https://www.alipay.com', $cookie, 0);
        return json_decode((string) $data, true);
    }

    protected function Get_curl($url, $post = 0, $referer = 0, $cookie = 0, $header = 0, $ua = 0, $nobaody = 0, $get_login_cookie = 0, $login_cookie_data = 0, $proxy = array())
    {
        $maxRetries = 3; // 最大重试次数
        $retryCount = 0;
        $result = false;

        while ($retryCount < $maxRetries && $result === false) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            // 伪造客户端来源IP
            $xforip = rand(1, 254) . "." . rand(1, 254) . "." . rand(1, 254) . "." . rand(1, 254);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'CLIENT-IP:' . $xforip,
                'X-FORWARDED-FOR:' . $xforip,
            ));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            if ($proxy) {
                // 有代理IP就使用代理
                curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_PROXY, $proxy[0]);
                //代理服务器地址
                curl_setopt($ch, CURLOPT_PROXYPORT, $proxy[1]);
                //代理服务器端口
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
            $httpheader[] = "Accept:application/json, text/plain, */*";
            $httpheader[] = "Accept-Language:zh-CN,zh;q=0.8";
            $httpheader[] = "Connection:keep-alive"; // 改为keep-alive提高性能
            $httpheader[] = "content-type: application/x-www-form-urlencoded";
            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 增加超时时间到10秒
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 连接超时5秒

            if ($post) {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            }
            if ($header) {
                curl_setopt($ch, CURLOPT_HEADER, TRUE);
            }
            if ($cookie) {
                curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            }
            if ($referer) {
                if ($referer == 1) {
                    curl_setopt($ch, CURLOPT_REFERER, 'http://m.qzone.com/infocenter?g_f=');
                } else {
                    curl_setopt($ch, CURLOPT_REFERER, $referer);
                }
            }
            if ($ua) {
                curl_setopt($ch, CURLOPT_USERAGENT, $ua);
            } else {
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.25 Safari/537.36 Core/1.70.3741.400 QQBrowser/10.5.3863.400');
            }
            if ($nobaody) {
                curl_setopt($ch, CURLOPT_NOBODY, $nobaody);
            }
            if ($get_login_cookie) {
                curl_setopt($ch, CURLOPT_COOKIEJAR, $get_login_cookie);
            }
            if ($login_cookie_data) {
                curl_setopt($ch, CURLOPT_COOKIEFILE, $login_cookie_data);
            }
            curl_setopt($ch, CURLOPT_ENCODING, "gzip,deflate"); // 添加deflate压缩
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            $ret = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            // 检查HTTP状态码和错误
            if ($httpCode == 200 && empty($error) && !empty($ret)) {
                $result = $ret;
            } else {
                $retryCount++;
                // 记录错误日志
                error_log("Alipay API请求失败 (尝试 $retryCount/$maxRetries): HTTP状态码=$httpCode, 错误=$error");
                if ($retryCount < $maxRetries) {
                    // 重试前等待一段时间
                    usleep(500000 * $retryCount); // 递增等待时间：0.5秒, 1秒, 1.5秒
                }
            }
        }

        return $result;
    }

    //取中间文本
    protected function getSubstr($str, $leftStr, $rightStr)
    {
        $left = strpos($str, $leftStr);
        $right = strpos($str, $rightStr, $left);
        if ($left < 0 or $right < $left) {
            return '';
        }
        return substr($str, $left + strlen($leftStr), $right - $left - strlen($leftStr));
    }
}
