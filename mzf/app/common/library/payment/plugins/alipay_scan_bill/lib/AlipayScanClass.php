<?php

namespace app\common\library\payment\plugins\alipay_scan_bill\lib;

/**
 * 支付宝网页版 Cookie 协议类（扫码免挂 alipay_scan_bill 使用）
 *
 * 逐字节移植自旧 protected/payplug/alipay_scan_bill/class/alipay_scan_class.php。
 * 直连支付宝网页接口（用商户 Cookie），不依赖任何云服务。
 *   - get_user_pic：校验 cookie + 取头像/昵称
 *   - check_user：校验 cookie 有效性
 *   - get_user_money：取支付宝余额（用于到账检测）
 *   - refresh_cookie：刷新延长 cookie 有效期
 */
class AlipayScanClass
{
    protected $ALI_UA = 'referer: https://authsa128.alipay.com/  user-agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.71 Safari/537.36 Core/1.94.238.400 QQBrowser/12.4.5622.400';

    public function get_user_pic($cookie = null)
    {
        $return     = [];
        $check_user = $this->check_user($cookie);
        if (($check_user['stat'] ?? '') == 'deny' || ($check_user['stat'] ?? '') == 'fail' || !$check_user) {
            $return = ['code' => -1, 'msg' => 'cookie失效,请重新获取'];
        } else {
            $user_pic     = '';
            $portraitPath = '';
            if (!$portraitPath || mb_strlen($portraitPath, 'UTF-8') > 30) {
                $url  = 'https://shanghu.alipay.com/home/switchPersonal.htm?_output_charset=utf-8';
                $data = $this->Get_curl($url, 0, 'https://authsa128.alipay.com/', $cookie, 0, $this->ALI_UA);
                $data = str_replace(' ', '', (string) $data);
                $data = str_replace('"', '', $data);
                $user_pic = $this->getSubstr($data, '<imgsrc=https:', 'id=J-portrait-user');
            }
            if (!$user_pic) {
                $url  = 'https://personalweb.alipay.com/account/security.htm?_output_charset=utf-8';
                $data = $this->Get_curl($url, 0, 'https://authsa128.alipay.com/', $cookie, 0, $this->ALI_UA);
                $portraitPath = $this->getSubstr((string) $data, "portraitPath: '/", "',");
                if ($portraitPath) $user_pic = '//tfs.alipayobjects.com/' . $portraitPath;
            }
            if (!$user_pic) {
                $url  = 'https://custweb.alipay.com/account/index.htm?_output_charset=utf-8';
                $data = $this->Get_curl($url, 0, 'https://authsa128.alipay.com/', $cookie, 0, $this->ALI_UA);
                $portraitPath = $this->getSubstr((string) $data, "portraitPath: '/", "',");
                if ($portraitPath) $user_pic = '//tfs.alipayobjects.com/' . $portraitPath;
            }
            if (!$user_pic) {
                $url  = 'https://shanghu.alipay.com/home/switchPersonal.htm?_output_charset=utf-8';
                $data = $this->Get_curl($url, 0, 'https://authsa128.alipay.com/', $cookie, 0, $this->ALI_UA);
                $portraitPath = $this->getSubstr((string) $data, "portraitPath: '/", "',");
                if ($portraitPath) $user_pic = '//tfs.alipayobjects.com/' . $portraitPath;
            }
            if (!$user_pic || mb_strlen($user_pic, 'UTF-8') > 100) {
                $user_pic = '/assets/alipay.jpeg';
            }
            $return['user_pic'] = $user_pic;
            $return['nickname'] = $check_user['data']['logonName'] ?? '';
        }
        return $return;
    }

    /**
     * 检测 cookie 是否正常并获取支付宝数据
     */
    public function check_user($cookie = 0)
    {
        $this->refresh_cookie($cookie);

        $url  = 'https://enterpriseportal.alipay.com/pamir/login/queryLoginAccount.json?_output_charset=utf-8&appScene=MRCH';
        $data = $this->Get_curl($url, 0, 'https://www.alipay.com', $cookie, 0, $this->ALI_UA);
        $json = json_decode((string) $data, true);

        if (isset($json['stat']) && ($json['stat'] == 'deny' || $json['stat'] == 'fail')) {
            $this->refresh_cookie($cookie);
            $this->Get_curl('https://my.alipay.com/portal/i.htm', 0, 'https://www.alipay.com', $cookie, 0, $this->ALI_UA);
            $data = $this->Get_curl($url, 0, 'https://www.alipay.com', $cookie, 0, $this->ALI_UA);
            $json = json_decode((string) $data, true);
        }
        return $json;
    }

    /**
     * 获取支付宝余额（到账检测）
     */
    public function get_user_money($cookie = null)
    {
        $urls = [
            'https://b.alipay.com/page/home',
            'https://accounts.alipay.com/console/querypwd/logonIdInputReset.htm?site=1&scene_code=resetQueryPwd&page_type=fullpage',
            'https://cshall.alipay.com/lab/cateQuestion.htm?cateId=237621&pcateId=',
            'https://cshall.alipay.com/lab/cateQuestion.htm?cateId=237627&pcateId=237621',
            'https://cshall.alipay.com/lab/cateQuestion.htm',
            'https://b.alipay.com/page/self-operation-center/index',
            'https://shenghuo.alipay.com/send/payment/fill.htm',
            'https://www.alipay.com/x/personal',
            'https://open.alipay.com/',
            'https://my.alipay.com/portal/i.htm',
            'https://consumerplatform.alipay.com/mas/v1/home.htm',
            'https://auth.alipay.com/login/index.htm',
        ];
        $cookie_url = $urls[array_rand($urls)];
        $this->Get_curl($cookie_url, 0, 'https://www.alipay.com', $cookie, 0, $this->ALI_UA);
        $this->refresh_cookie($cookie);

        $url  = 'https://uemprod.alipay.com/service.json?_output_charset=utf-8&ctoken=' . time() . '-' . time() . '&operation=mrchcenter.artisan.v2.ext.query';
        $post = 'data=%7B%22pageSource%22%3A%22fund_home_pc_merchant%22%2C%22parameters%22%3A%7B%22switchToNew%22%3A%22true%22%7D%7D';
        $data = $this->Get_curl($url, $post, 'https://www.alipay.com', $cookie, 0, $this->ALI_UA);
        $json = json_decode((string) $data, true);
        $money = preg_replace('/[^\d.]/', '', $json['data']['data']['fund_home_detail_info']['data']['alipayBalanceList'][0]['availableBalance'] ?? '');
        if (($json['stat'] ?? '') == 'deny' || ($json['stat'] ?? '') == 'fail') {
            return ['code' => -1, 'msg' => 'cookie失效了哦'];
        }
        return ['code' => 1, 'money' => $money];
    }

    /**
     * 刷新 cookie 有效期
     */
    public function refresh_cookie($cookie = null)
    {
        $refresh_urls = [
            'https://my.alipay.com/portal/i.htm',
            'https://personalweb.alipay.com/portal/i.htm',
            'https://shenghuo.alipay.com/transfer/index.htm',
            'https://lab.alipay.com/user/index.htm',
        ];
        $random_url = $refresh_urls[array_rand($refresh_urls)];
        $this->Get_curl($random_url, 0, 'https://www.alipay.com', $cookie, 0, $this->ALI_UA);
        $this->Get_curl('https://personalweb.alipay.com/account/security.htm?_output_charset=utf-8', 0, 'https://www.alipay.com', $cookie, 0, $this->ALI_UA);
        return true;
    }

    /**
     * 强化保活：多点触活支付宝各子域，延长 Cookie 有效期、保持在线
     *
     * 在 refresh_cookie 基础上优化——跨多个子域连续访问，模拟真实用户活跃，
     * 显著延长登录会话 TTL，降低掉线概率。心跳时调用。
     */
    public function keepAlive($cookie = null): bool
    {
        // 跨子域的保活触点（覆盖账户/商家/生活/开放平台等，尽量分散触活）
        $keepUrls = [
            'https://my.alipay.com/portal/i.htm',
            'https://personalweb.alipay.com/portal/i.htm',
            'https://personalweb.alipay.com/account/security.htm?_output_charset=utf-8',
            'https://shenghuo.alipay.com/transfer/index.htm',
            'https://b.alipay.com/page/home',
            'https://custweb.alipay.com/account/index.htm?_output_charset=utf-8',
            'https://consumerplatform.alipay.com/mas/v1/home.htm',
        ];
        // 打乱后连续访问其中若干个，分散触活、避免固定特征
        shuffle($keepUrls);
        $hit = 0;
        foreach ($keepUrls as $url) {
            $this->Get_curl($url, 0, 'https://www.alipay.com', $cookie, 0, $this->ALI_UA);
            if (++$hit >= 4) {
                break;
            }
        }
        // 关键校验接口再触活一次，确保会话续期
        $this->Get_curl(
            'https://enterpriseportal.alipay.com/pamir/login/queryLoginAccount.json?_output_charset=utf-8&appScene=MRCH',
            0,
            'https://www.alipay.com',
            $cookie,
            0,
            $this->ALI_UA
        );
        return true;
    }

    protected function Get_curl($url, $post = 0, $referer = 0, $cookie = 0, $header = 0, $ua = 0, $nobaody = 0, $get_login_cookie = 0, $login_cookie_data = 0, $proxy = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        $xforip = rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['CLIENT-IP:' . $xforip, 'X-FORWARDED-FOR:' . $xforip]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_PROXY, $proxy[0]);
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxy[1]);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
        $httpheader = [
            'Accept:application/json, text/plain, */*',
            'Accept-Language:zh-CN,zh;q=0.8',
            'Connection:keep-alive',
            'content-type: application/x-www-form-urlencoded',
            'Cache-Control:max-age=3600',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        if ($header) curl_setopt($ch, CURLOPT_HEADER, true);
        if ($cookie) curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer == 1 ? 'http://m.qzone.com/infocenter?g_f=' : $referer);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua ?: 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.25 Safari/537.36 Core/1.70.3741.400 QQBrowser/10.5.3863.400');
        if ($nobaody) curl_setopt($ch, CURLOPT_NOBODY, $nobaody);
        if ($get_login_cookie) curl_setopt($ch, CURLOPT_COOKIEJAR, $get_login_cookie);
        if ($login_cookie_data) curl_setopt($ch, CURLOPT_COOKIEFILE, $login_cookie_data);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }

    protected function getSubstr($str, $leftStr, $rightStr)
    {
        $left = strpos($str, $leftStr);
        if ($left === false) return '';
        $right = strpos($str, $rightStr, $left);
        if ($right === false || $right < $left) return '';
        return substr($str, $left + strlen($leftStr), $right - $left - strlen($leftStr));
    }
}
