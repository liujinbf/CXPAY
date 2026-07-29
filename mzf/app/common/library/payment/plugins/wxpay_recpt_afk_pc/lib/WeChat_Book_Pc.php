<?php

namespace app\common\library\payment\plugins\wxpay_recpt_afk_pc\lib;

/**
 * 微信小账本协议（PC挂机 wxpay_recpt_afk_pc book 模式使用）
 *
 * 逐字节移植自旧 protected/payplug/wxpay_book_afk_pc/class/WeChat_Book_Pc.Class.php。
 * 合并自 wxpay_book_afk_pc/lib/WeChat_Book_Pc.php，namespace 已迁移。
 * 直连 payapp.weixin.qq.com 小账本接口，协议/加密/HTTP 逐字节保留。
 * Author:零度<2109877665@qq.com> 2023/05/10
 */
class WeChat_Book_Pc
{
    protected $bookver = 0;

    public function __construct($Zero = 'Zero')
    {
    }

    /*
    * 获取个人收款码
    * $book_sid = 小账本sid
    */
    public function getpayqrcode($book_sid = 0)
    {
        $url_getwxacode = 'https://payapp.weixin.qq.com/qrappsl/profile/getpayqrcode?sid=' . $book_sid . '&v=7.2.3';
        $data_getwxacode = $this->get_curl($url_getwxacode);
        $json_getwxacode = json_decode($data_getwxacode, true);
        if (($json_getwxacode['retcode'] ?? -1) == 0) {
            $return['code'] = 1;
            $return['msg']  = '生成收款二维码成功';
            $return['qr_url']  = 'data:image/jpg;base64,' . ($json_getwxacode['data']['qrcode_content'] ?? '');
        } elseif (($json_getwxacode['msg'] ?? '') == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = $json_getwxacode['msg'] ?? '获取收款码失败';
        }
        return $return;
    }

    /*
    * 查询订单列表
    * $book_sid = 小账本sid  只获取最新5分钟的
    */
    public function detailOrder($book_sid = 0)
    {
        $url_detail = 'https://payapp.weixin.qq.com/qrappzd/user/incomelistflexible?sid=' . $book_sid . '&v=7.2.3';
        $post       = '{"v":"7.2.3","sid":"' . $book_sid . '","start_time":' . strtotime(date("Y-m-d H:00:00")) . ',"end_time":' . time() . ',"page_size":20,"sort":"desc","is_first":true,"last_create_time":0,"last_id":""}';
        $data_detail = $this->get_curl($url_detail, $post);
        $json_detail = json_decode($data_detail, true);
        if (($json_detail['errcode'] ?? -1) == 0) {
            $return['code'] = 1;
            $return['msg']  = '获取订单列表成功';
            $data = [];
            foreach (($json_detail['data']['data_list'] ?? []) as $order) {
                $money = sprintf("%.2f", substr(sprintf("%.4f", (($order['fee'] ?? 0) / 100)), 0, -2));
                $order['payer_remark'] = ($order['payer_remark'] ?? '') ? $order['payer_remark'] : '未填支付备注';
                $data[] = array('money' => $money, 'timestamp' => $order['timestamp'] ?? 0, 'payer_nick_name' => base64_decode($order['payer_user_name'] ?? ''), 'id_v3' => $order['id_v3'] ?? '', 'trans_id' => $order['trans_id'] ?? '', 'payer_remark' => $order['payer_remark']);
            }
            $return['data'] = $data;
        } elseif (($json_detail['msg'] ?? '') == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = $json_detail['msg'] ?? '获取订单列表失败';
        }
        return $return;
    }

    /*
    * 查询订单列表 2
    * $book_sid = 小账本sid   备用 可以暂时不用
    */
    public function detailOrder1($book_sid = 0)
    {
        $url_detail = 'https://payapp.weixin.qq.com/qrappzd/home/detail?v=7.2.3&sid=' . $book_sid;
        $data_detail = $this->get_curl($url_detail);
        $json_detail = json_decode($data_detail, true);
        if (($json_detail['errcode'] ?? -1) == 0) {
            $return['code'] = 1;
            $return['msg']  = '获取订单列表成功';
            $data = [];
            foreach (($json_detail['data']['bill_list'] ?? []) as $order) {
                $money = sprintf("%.2f", substr(sprintf("%.4f", (($order['fee'] ?? 0) / 100)), 0, -2));
                $data[] = array('money' => $money, 'timestamp' => $order['timestamp'] ?? 0, 'payer_nick_name' => $order['payer_nick_name'] ?? '', 'payer_head_img' => $order['payer_head_img'] ?? '', 'trans_id' => $order['trans_id'] ?? '');
            }
            $return['data'] = $data;
        } elseif (($json_detail['msg'] ?? '') == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = $json_detail['msg'] ?? '获取订单列表失败';
        }
        return $return;
    }

    /*
    * 获取店员邀请小程序码
    * $book_sid = 小账本sid
    */
    public function getbindnotifierqrcode($book_sid = 0)
    {
        $url_getwxacode = 'https://payapp.weixin.qq.com/qrapp/user/getbindnotifierqrcode?sid=' . $book_sid . '&v=7.2.3';
        $post = '{"v":"7.2.3","width":400,"sid":"' . $book_sid . '"}';
        $data_getwxacode = $this->get_curl($url_getwxacode, $post);
        $json_getwxacode = json_decode($data_getwxacode, true);
        if (($json_getwxacode['retcode'] ?? -1) == 0) {
            echo ('<img src="data:image/jpg;base64,' . ($json_getwxacode['data']['qrcode'] ?? '') . '" width="200" height="200"/><br>' . ($json_getwxacode['data']['token'] ?? ''));
        } elseif (($json_getwxacode['msg'] ?? '') == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = $json_getwxacode['msg'] ?? '获取失败';
        }
        return $return;
    }

    /*
    * 获取店员列表
    * $book_sid = 小账本sid
    */
    public function notifierlist($book_sid = 0)
    {
        $url_GetPayeeList = 'https://payapp.weixin.qq.com/qrapp/user/notifierlist?sid=' . $book_sid . '&v=7.2.3';
        $post = '{"v":"7.2.3","sid":"' . $book_sid . '"}';
        $data_GetPayeeList = $this->get_curl($url_GetPayeeList, $post);
        $json_GetPayeeList = json_decode($data_GetPayeeList, true);
        if (($json_GetPayeeList['retcode'] ?? -1) == 0) {
            //输出收款码
        } elseif (($json_GetPayeeList['msg'] ?? '') == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = $json_GetPayeeList['msg'] ?? '获取失败';
        }
        return $return;
    }

    protected function get_curl($url, $post = 0, $referer = 0, $cookie = 0, $header = 0, $ua = 0, $nobaody = 0)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept:*/*";
        $httpheader[] = "Accept-Encoding:gzip,deflate,sdch";
        $httpheader[] = "Accept-Language:zh-CN,zh;q=0.8";
        $httpheader[] = "Connection:close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; U; Android 4.4.1; zh-cn; R815T Build/JOP40D) AppleWebKit/533.1 (KHTML, like Gecko)Version/4.0 MQQBrowser/4.5 Mobile Safari/533.1');
        }
        if ($nobaody) {
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }

    //获取时间戳(毫秒)
    protected function getCurrentMilis()
    {
        list($t1, $t2) = explode(' ', microtime());
        return (float) sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
    }

    protected function getSubstr($str, $leftStr, $rightStr)
    {
        $left = strpos($str, $leftStr);
        $right = strpos($str, $rightStr, $left);
        if ($left < 0 or $right < $left) return '';
        return substr($str, $left + strlen($leftStr), $right - $left - strlen($leftStr));
    }
}
