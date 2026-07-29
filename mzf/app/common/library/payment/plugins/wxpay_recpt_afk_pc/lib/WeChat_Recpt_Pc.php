<?php

namespace app\common\library\payment\plugins\wxpay_recpt_afk_pc\lib;

/**
 * 微信收款单协议（PC挂机 wxpay_recpt_afk_pc 使用）
 *
 * 逐字节移植自旧 protected/payplug/wxpay_recpt_afk_pc/class/WeChat_Recpt_Pc.Class.php。
 * 直连 payapp.wechatpay.cn 收款单接口，协议/加密/HTTP 逐字节保留。
 * Author:零度<2109877665@qq.com> 2023/05/10
 */
class WeChat_Recpt_Pc
{
    protected $timestamp = null;

    public function __construct($Zero = 'Zero')
    {
    }

    /*
    * 生成订单并获取收款单收款小程序码
    */
    public function createOrder($account_id = 0, $recpt_sid = 0, $money = '1.00', $remark = 0)
    {
        $post = 0; // 兼容旧未初始化变量（原 error_reporting(0) 下静默）
        $explode = explode("|", $account_id);
        $account_id = $explode[0];
        $account_type = $explode[1];
        if ($explode[2]) {
            $shop_id = $explode[2] . '&receipt_item_list=%5B%5D';
        } else {
            $shop_id = '';
        }
        if (!$remark) $remark = $this->random_remark();
        if ($account_type == 1) {
            $receipt = 'receiptmdmgr';
            $url_create = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/create?miniprogram_version=3.15.9&remark_pic_urls=&option_list=%5B%5D&account_type=' . $account_type . '&shop_id=' . $shop_id . '&remark=' . urlencode($remark) . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&fee=' . ($money * 100);
        } elseif ($account_type == 2) {
            $receipt = 'receiptwxmgr';
            $url_create = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/create?miniprogram_version=3.15.9&remark_pic_urls=&option_list=%5B%5D&account_type=' . $account_type . '&shop_id=' . $shop_id . '&remark=' . urlencode($remark) . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&fee=' . ($money * 100);
        } elseif ($account_type == 3) {
            $receipt = 'receiptsjtmgr';
            $url_create = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/create?miniprogram_version=3.15.9&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid;
            $post = '{"miniprogram_version":"3.15.9","fee":' . ($money * 100) . ',"remark":"' . $remark . '","remark_pic_urls":"","option_list":[],"receipt_item_list":[],"shop_id":' . $explode[2] . ',"sid":"' . $recpt_sid . '"}';
        }

        $data_create = $this->get_curl($url_create, $post);
        $json_create = json_decode($data_create, true);
        if ($json_create['data']['errcode'] == 0 and $json_create['data']['receipt']['receipt_id']) {
            $url_getwxacode = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/getwxacode?miniprogram_version=3.15.9&wxacode_path_type=1&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $json_create['data']['receipt']['receipt_id'];
            $data_getwxacode = $this->get_curl($url_getwxacode);
            $json_getwxacode = json_decode($data_getwxacode, true);
            if ($json_getwxacode['data']['errcode'] == 0) {
                $return['code'] = 1;
                $return['msg']  = '生成收款二维码成功';
                $return['qr_url']  = 'data:image/jpg;base64,' . $json_getwxacode['data']['qrcode'];
                $return['receipt_id']  = $json_create['data']['receipt']['receipt_id'];
            } else {
                $return['code'] = -1;
                $return['msg']  = '1' . $json_getwxacode['msg'];
            }
        } elseif ($json_create['msg'] == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = '2' . $json_create['msg'];
            if (strstr($return['msg'], '还没有开通收款单账号')) $return['msg']  = '此微信没有开通收款单权限,请去微信搜索小程序 [微信收款单]
			进行开通';
        }
        return $return;
    }

    /*
    * receipt_id 获取收款码
    */
    public function getpayqrcode($account_id = 0, $recpt_sid = 0, $receipt_id = 0)
    {
        $explode = explode("|", $account_id);
        $account_id = $explode[0];
        $account_type = $explode[1];
        if ($explode[2]) {
            $shop_id = $explode[2] . '&receipt_item_list=%5B%5D';
        } else {
            $shop_id = '';
        }
        if ($account_type == 1) {
            $receipt = 'receiptmdmgr';
        } elseif ($account_type == 2) {
            $receipt = 'receiptwxmgr';
        } elseif ($account_type == 3) {
            $receipt = 'receiptsjtmgr';
        }
        $url_getwxacode = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/getwxacode?miniprogram_version=3.15.9&wxacode_path_type=1&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $receipt_id;
        $data_getwxacode = $this->get_curl($url_getwxacode);
        $json_getwxacode = json_decode($data_getwxacode, true);
        if ($json_getwxacode['retcode'] == 0) {
            $return['code'] = 1;
            $return['msg']  = '生成收款二维码成功';
            $return['qr_url']  = 'data:image/jpg;base64,' . $json_getwxacode['data']['qrcode'];
            $return['receipt_id']  = $receipt_id;
        } elseif ($json_getwxacode['msg'] == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = '2' . $json_getwxacode['msg'];
            if (strstr($return['msg'], '还没有开通收款单账号')) $return['msg']  = '此微信没有开通收款单权限,请去微信搜索小程序 [微信收款单]
			进行开通';
        }
        return $return;
    }

    /*
    * 查询收款单支付状态 订单详细
    * receipt_state = 状态码  closed=则关闭  receiving=未收款  success=已支付
    */
    public function detailOrder($account_id = 0, $recpt_sid = 0, $receipt_id = 0)
    {
        $post = 0; // 兼容旧未初始化变量（原 error_reporting(0) 下静默）
        $explode = explode("|", $account_id);
        $account_id = $explode[0];
        $account_type = $explode[1];
        if ($account_type == 1) {
            $receipt = 'receiptmdmgr';
            $url_detail = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/detailv3?miniprogram_version=3.15.9&page_index=1&page_size=10&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $receipt_id;
        } elseif ($account_type == 2) {
            $receipt = 'receiptwxmgr';
            $url_detail = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/detail?miniprogram_version=3.15.9&page_index=1&page_size=10&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $receipt_id;
        } elseif ($account_type == 3) {
            $receipt = 'receiptsjtmgr';
            $url_detail = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/detail?miniprogram_version=3.15.9&page_index=1&page_size=10&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $receipt_id;
        }
        $data_detail = $this->get_curl($url_detail, $post);
        $json_detail = json_decode($data_detail, true);
        if ($json_detail['errcode'] == 0) {
            $return['code'] = 1;
            $return['msg']  = '查询成功';
            foreach ($json_detail['data']['receipt']['order'] as $order) {
                $money = sprintf("%.2f", substr(sprintf("%.4f", ($order['fee'] / 100)), 0, -2));
                $return['transaction_id']  = $order['transaction_id'];
                $return['money']  = $money;
                $return['order_state']  = $order['state'];
                $return['receipt_state']  = $json_detail['data']['receipt']['state'];
            }
        } elseif ($json_detail['msg'] == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = $json_detail['msg'];
        }
        return $return;
    }

    /*
    * 获取收款单订单列表
    * $typeDel = 1则 删除收款单列表
    */
    public function receiptList($account_ids = 0, $recpt_sid = 0, $typeDel = 0)
    {
        $post = 0; // 兼容旧未初始化变量（原 error_reporting(0) 下静默）
        $explode = explode("|", $account_ids);
        $account_id = $explode[0];
        $account_type = $explode[1];
        if ($account_type == 1) {
            $receipt = 'receiptmdmgr';
            $url_list = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/list?page_size=10&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid;
        } elseif ($account_type == 2) {
            $receipt = 'receiptwxmgr';
            $url_list = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/list?page_size=10&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid;
        } elseif ($account_type == 3) {
            $receipt = 'receiptsjtmgr';
            $url_list = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/list?account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid;
            $post = '{"miniprogram_version":"3.15.9","start_time":0,"end_time":0,"page_size":10,"state":[],"shop_id_list":[],"sid":"' . $recpt_sid . '"}';
        }
        $data_list = $this->get_curl($url_list, $post);
        $json_list = json_decode($data_list, true);
        if ($json_list['errcode'] == 0) {
            $return['code'] = 1;
            $return['msg']  = '获取收款单列表成功';
            foreach ($json_list['data']['receipt'] as $receipt) {
                if ($typeDel) $this->closeDel($account_ids, $recpt_sid, $receipt['receipt_id']);
                $money = sprintf("%.2f", substr(sprintf("%.4f", ($receipt['fee'] / 100)), 0, -2));
                $data[] = array('receipt_id' => $order['receipt_id'], 'remark' => $order['remark'], 'money' => $money);
            }
            $return['data'] = $data;
        } elseif ($json_list['msg'] == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = $json_list['msg'];
        }
        return $return;
    }

    /*
    * 关闭并删除收款单
    */
    public function closeDel($account_id = 0, $recpt_sid = 0, $receipt_id = '18323308')
    {
        $post = 0; // 兼容旧未初始化变量（原 error_reporting(0) 下静默）
        $explode = explode("|", $account_id);
        $account_id = $explode[0];
        $account_type = $explode[1];
        if ($account_type == 1) {
            $receipt = 'receiptmdmgr';
            $url_close = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/closev2?miniprogram_version=3.15.9&wxacode_path_type=1&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $receipt_id;
        } elseif ($account_type == 2) {
            $receipt = 'receiptwxmgr';
            $url_close = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/close?miniprogram_version=3.15.9&wxacode_path_type=1&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $receipt_id;
        } elseif ($account_type == 3) {
            $receipt = 'receiptsjtmgr';
            $url_close = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/close?miniprogram_version=3.15.9&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid;
            $post = '{"miniprogram_version":"3.15.9","receipt_id":' . $receipt_id . ',"sid":"' . $recpt_sid . '"}';
        }
        $data_close = $this->get_curl($url_close, $post);
        $json_close = json_decode($data_close, true);
        if ($json_close['errcode'] == 0) {
            if ($account_type == 1) {
                $receipt = 'receiptmdmgr';
                $url_del = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/del?miniprogram_version=3.15.9&wxacode_path_type=1&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $receipt_id;
            } elseif ($account_type == 2) {
                $receipt = 'receiptwxmgr';
                $url_del = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/del?miniprogram_version=3.15.9&wxacode_path_type=1&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid . '&receipt_id=' . $receipt_id;
            } elseif ($account_type == 3) {
                $receipt = 'receiptsjtmgr';
                $url_del = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/del?miniprogram_version=3.15.9&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $recpt_sid;
                $post = '{"miniprogram_version":"3.15.9","receipt_id":' . $receipt_id . ',"sid":"' . $recpt_sid . '"}';
            }
            $data_del = $this->get_curl($url_del, $post);
            $json_del = json_decode($data_del, true);
            if ($json_del['errcode'] == 0) {
                $return['code'] = 1;
                $return['msg']  = '删除收款单ID: ' . $receipt_id . ' 成功';
            } else {
                $return['code'] = -1;
                $return['msg']  = $json_del['msg'];
            }
        } elseif ($json_close['msg'] == '效验登录态失败') {
            $return['code'] = -1;
            $return['msg']  = 'SID失效了哦';
        } else {
            $return['code'] = -1;
            $return['msg']  = $json_close['msg'];
            if (strstr($return['msg'], '关闭收款单失败')) $return['msg']  = '关闭收款单失败,此订单ID可能不存在或已被关闭';
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
            curl_setopt($ch, CURLOPT_USERAGENT, 'Host: payapp.weixin.qq.com\r\User-Agent: Mozilla/5.0 (Linux; Android 12; NEM-AL10 Build/HONORNEM-AL10; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/57.0.2987.132 MQQBrowser/6.2 TBS/043906 Mobile Safari/537.36 MicroMessenger/6.6.1.1220(0x26060133) NetType/WIFI Language/zh_CN/6762\r\nContent-Type: application/json\r\n');
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

    protected function random_remark()
    {
        $poems = "AA购买、AA制购买、AA制购、合伙购买、合伙购";
        $poems = explode("、", $poems);
        $beiz = $poems[rand(0, count($poems) - 1)];
        $poems = "香蕉、苹果、枇杷、草莓、柠檬、桃子、榴莲、梨、红枣、石榴、葡萄、橘子、芒果、西瓜、柿子、山竹、百香果、杏子、火龙果、桂圆、荸荠、柚子、桑葚、李子、菠萝、菠萝蜜、槟榔、橙子、杨梅、银杏、无花果、乌梅、甘蔗、蕃茄、龙眼、荔枝、黄皮、桔子";
        $poems = explode("、", $poems);
        $name = $poems[rand(0, count($poems) - 1)];
        return $beiz . $name;
    }

    protected function getSubstr($str, $leftStr, $rightStr)
    {
        $left = strpos($str, $leftStr);
        $right = strpos($str, $rightStr, $left);
        if ($left < 0 or $right < $left) return '';
        return substr($str, $left + strlen($leftStr), $right - $left - strlen($leftStr));
    }
}
