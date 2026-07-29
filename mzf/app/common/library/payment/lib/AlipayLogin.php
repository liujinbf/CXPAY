<?php

namespace app\common\library\payment\lib;

/**
 * 支付宝网页版 扫码取 Cookie 协议类
 *
 * 逐字节移植自旧 protected/lib/OpenClass/alipaylogin.Class.php。
 * 直连支付宝登录接口，不依赖任何云服务：
 *   - getqrlogin()：拉取登录二维码 token，返回 qrdata + id（多段用 Z-E-R-O 分隔）
 *   - verifyqrlogin($id)：轮询扫码状态，扫码确认后提取并返回 base64(Cookie)
 *     返回 code：0=等待扫码 1=已扫待确认 2=登录成功(带cookie) -1=失败
 */
class AlipayLogin
{
    protected $ALI_UA = 'Referer:https://b.alipay.com/ User-Agent:Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.5845.97 Safari/537.36 Core/1.116.485.400 QQBrowser/13.6.6321.400';

    /**
     * 获取支付宝登录二维码
     */
    public function getqrlogin(): array
    {
        $referer = 'https://authsa128.alipay.com?t=' . time();
        $url     = 'https://authsa128.alipay.com/login/index.htm';
        $data    = $this->Get_curl_header($url, 0, $referer, $this->ALI_UA);
        $data['body']   = $this->charset($data['body']);
        $data['header'] = $this->charset($data['header']);

        preg_match('/securityId: "(.*?)",/', $data['body'], $match);
        $authcenter_qrcode_login = $match[1] ?? '';
        preg_match('/s.sid = "(.*?)"/', $data['body'], $match);
        $authcenter_querypwd_login = $match[1] ?? '';
        $rds_form_token = $this->getSubstr($data['body'], '<input type="hidden" value="', '" name="rds_form_token"/>');
        $alieditUid     = $this->getSubstr($data['body'], '<input type="hidden" id="alieditUid" name="alieditUid" value="', '" />');

        $qrdata = 'https://qr.alipay.com/_d?_b=PAI_LOGIN_DY&amp;securityId=' . urlencode($authcenter_qrcode_login);

        if ($authcenter_qrcode_login) {
            return [
                'code'   => 1,
                'msg'    => '获取支付宝登录二维码成功',
                'qrdata' => $qrdata,
                'id'     => $authcenter_qrcode_login . 'Z-E-R-O' . $authcenter_querypwd_login . 'Z-E-R-O' . $rds_form_token . 'Z-E-R-O' . $alieditUid,
            ];
        }
        return ['code' => -1, 'msg' => '获取支付宝登录二维码失败'];
    }

    /**
     * 检测支付宝登录并获取 Cookie
     */
    public function verifyqrlogin(string $id = ''): array
    {
        $referer = 'https://b.alipay.com/?t=' . time();
        $intlpay = explode('Z-E-R-O', $id);

        $url               = 'https://securitycore.alipay.com/barcode/barcodeProcessStatus.json?';
        $post_intl         = [];
        $post_intl['securityId'] = $intlpay[0] ?? '';
        $post_intl['_callback']  = 'light.request._callbacks.callback2';
        $post_intl = http_build_query($post_intl);
        $data_intl = $this->Get_curl($url . $post_intl, 0, $referer, 0, 0, 0, $this->ALI_UA);

        $url  = 'https://authsa128.alipay.com/login/homeB.htm';
        $post = [
            'support'                  => '000001',
            'CtrlVersion'              => '1,1,0,1',
            'loginScene'               => 'ant_sso_index',
            'goto'                     => 'https://b.alipay.com/page/bizfund/assetManage',
            'rds_form_token'           => $intlpay[2] ?? '',
            'method'                   => 'qrCodeLogin',
            'superSwitch'              => 'true',
            'noActiveX'                => 'false',
            'passwordSecurityId'       => $intlpay[1] ?? '',
            'qrCodeSecurityId'         => $intlpay[0] ?? '',
            'J_aliedit_using'          => 'true',
            'J_aliedit_key_hidn'       => 'password',
            'J_aliedit_uid_hidn'       => 'alieditUid',
            'alieditUid'               => $intlpay[3] ?? '',
            'REMOTE_PCID_NAME'         => '_seaside_gogo_pcid',
            'security_activeX_enabled' => 'false',
        ];
        $post = http_build_query($post);

        $data = '';
        if (!strpos((string) $data_intl, 'waiting') && !strpos((string) $data_intl, 'scanned')) {
            $resp = $this->Get_curl_header($url, $post, $referer, $this->ALI_UA);
            $resp['header'] = $this->charset($resp['header']);
            $data = @iconv('GB2312', 'UTF-8', $resp['header']) ?: $resp['header'];
        }

        $ALIPAYJSESSIONID = $this->between($data, 'ALIPAYJSESSIONID=', 'Domain=', 2);
        $ctoken           = $this->between($data, 'ctoken=', 'Domain=', 2);
        $CLUB_ALIPAY_COM  = $this->between($data, 'CLUB_ALIPAY_COM=', 'Domain=', 1);
        $JSESSIONID       = $this->between($data, 'JSESSIONID=', ';', 1);

        $cookies = '';
        if ($ALIPAYJSESSIONID && $CLUB_ALIPAY_COM) {
            $cookies = rtrim('JSESSIONID=' . $JSESSIONID . '; zone=GZ00C; ALIPAYJSESSIONID=' . $ALIPAYJSESSIONID . ' ctoken=' . $ctoken . ' CLUB_ALIPAY_COM=' . $CLUB_ALIPAY_COM);

            $url  = 'https://uemprod.alipay.com/baseinfo/indexVersion/queryVersion.json?pamir_app_scene=MRCH&operateBizType=b_merchant';
            $resp = $this->Get_curl_header($url, 0, $referer, $cookies, 0, $this->ALI_UA);
            $resp['header'] = $this->charset($resp['header']);
            $data2 = @iconv('GB2312', 'UTF-8', $resp['header']) ?: $resp['header'];

            $ALIPAYJSESSIONID2 = $this->getSubstr($data2, 'ALIPAYJSESSIONID=', ';') ?: $ALIPAYJSESSIONID;
            $spanner = $this->getSubstr($data2, 'spanner=', ';');
            $cookies = 'mobileSendTime=-1; credibleMobileSendTime=-1; ctuMobileSendTime=-1; receive-cookie-deprecation=1; auth_goto_http_type=https; JSESSIONID=' . $JSESSIONID . '; spanner=' . $spanner . '; zone=GZ00G; session.cookieNameId=ALIPAYJSESSIONID; ALIPAYJSESSIONID=' . $ALIPAYJSESSIONID2 . '; CLUB_ALIPAY_COM=' . $CLUB_ALIPAY_COM . '__TRACERT_COOKIE_bucUserId=' . $CLUB_ALIPAY_COM . ' ctoken=ZERO2109877665;';
        }

        if (strpos((string) $data_intl, 'waiting')) {
            $code = 0;
            $msg  = '等待扫码中';
        } elseif (strpos((string) $data_intl, 'scanned')) {
            $code = 1;
            $msg  = '等待确认中';
        } elseif ($ALIPAYJSESSIONID && $CLUB_ALIPAY_COM) {
            $code = 2;
            $msg  = '登录成功';
        } else {
            $code = -1;
            $msg  = '登录失败';
        }
        return ['code' => $code, 'msg' => $msg, 'cookie' => base64_encode($cookies)];
    }

    /* ---------------- helpers ---------------- */

    // 取第 N 段 leftStr..rightStr 之间（移植旧 explode 逻辑）
    protected function between(string $data, string $left, string $right, int $index): string
    {
        $parts = explode($left, $data);
        if (!isset($parts[$index])) return '';
        return explode($right, $parts[$index])[0] ?? '';
    }

    protected function charset($data)
    {
        if (!empty($data)) {
            $fileType = mb_detect_encoding($data, ['UTF-8', 'GBK', 'LATIN1', 'BIG5']);
            if ($fileType && $fileType != 'UTF-8') {
                $data = mb_convert_encoding($data, 'utf-8', $fileType);
            }
        }
        return $data;
    }

    private function Get_curl_header($url, $post = 0, $referer = 0, $cookie = 0, $header = 0, $ua = 0): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        $xforip = rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept:application/json, text/plain, */*',
            'Accept-Language:zh-CN,zh;q=0.8',
            'Connection:close',
            'content-type: application/x-www-form-urlencoded',
            'CLIENT-IP: ' . $xforip,
            'X-FORWARDED-FOR: ' . $xforip,
        ]);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        if ($cookie) curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua ?: 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 Chrome/70.0.3538.25 QQBrowser/10.5.3863.400');
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
        $ret = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['header' => substr((string) $ret, 0, $headerSize), 'body' => substr((string) $ret, $headerSize)];
    }

    protected function Get_curl($url, $post = 0, $referer = 0, $cookie = 0, $header = 0, $ua = 0, $ua2 = 0)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        $xforip = rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'CLIENT-IP:' . $xforip,
            'X-FORWARDED-FOR:' . $xforip,
            'Accept:application/json, text/plain, */*',
            'Accept-Language:zh-CN,zh;q=0.8',
            'Connection:close',
            'content-type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        if ($cookie) curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua ?: 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 Chrome/70.0.3538.25 QQBrowser/10.5.3863.400');
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }

    protected function getSubstr($str, $leftStr, $rightStr): string
    {
        $left = strpos($str, $leftStr);
        if ($left === false) return '';
        $right = strpos($str, $rightStr, $left);
        if ($right === false || $right < $left) return '';
        return substr($str, $left + strlen($leftStr), $right - $left - strlen($leftStr));
    }
}
