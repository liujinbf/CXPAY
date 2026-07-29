<?php

namespace app\common\library\payment\plugins\wxpay_protocol_cloud\lib;

use app\common\library\payment\lib\ProtocolCloud;

/**
 * 微信协议云端 SDK（WeChatProtocolCloud）
 *
 * 通过 yydopen 协议服务器获取小程序code，换取SID，支持小账本和收款单两种模式
 */
class WeChatProtocolCloud
{
    /** 小账本 app_id */
    public const APP_ID_BOOK = 'wx28be8489b7a36aaa';

    /** 收款单 app_id */
    public const APP_ID_RECPT = 'wx264e9b6d4d484f51';

    protected string $app_type; // 'book' 或 'recpt'
    protected ProtocolCloud $cloudClient;
    protected string $api_version = '7.2.3'; // 小账本版本

    /**
     * @param ProtocolCloud $cloudClient 云端客户端
     * @param string $app_type 小程序类型：'book'(小账本) 或 'recpt'(收款单)
     */
    public function __construct(ProtocolCloud $cloudClient, string $app_type = 'book')
    {
        $this->cloudClient = $cloudClient;
        $this->app_type = $app_type;
    }

    /**
     * 登录：获取小程序code并换取SID
     *
     * @param string $uin 账号UIN
     * @param string|null $app_id 小程序app_id（为null时根据app_type自动选择）
     * @return array ['code'=>1,'sid'=>'...','openid'=>'...']
     */
    public function login(string $uin, ?string $app_id = null): array
    {
        $app_id = $app_id ?: $this->getAppId();

        if ($this->app_type === 'recpt') {
            // 收款单：由云端直接从 yydopen 获取新鲜 code 换取 SID，
            // 避免 code 在传输中过期
            $sid = $this->exchangeSid($uin, $app_id);
            if (($sid['code'] ?? 0) != 1) {
                return ['code' => -1, 'msg' => $sid['msg'] ?? 'SID换取失败'];
            }

            return [
                'code' => 1,
                'msg' => '登录成功',
                'data' => [
                    'sid' => $sid['data']['sid'] ?? '',
                ],
            ];
        }

        // 1. 获取小程序code（小账本）
        $codeResult = $this->cloudClient->getWxappCode($uin, $app_id);
        if (($codeResult['code'] ?? 0) != 1) {
            return ['code' => -1, 'msg' => $codeResult['msg'] ?? '获取小程序code失败'];
        }

        $wxapp_code = $codeResult['data']['wxapp_code'] ?? '';
        if (empty($wxapp_code)) {
            return ['code' => -1, 'msg' => '小程序code为空'];
        }

        // 2. 使用code换取SID
        $sid = $this->exchangeSid($wxapp_code, $app_id);
        if (($sid['code'] ?? 0) != 1) {
            return ['code' => -1, 'msg' => $sid['msg'] ?? 'SID换取失败'];
        }

        return [
            'code' => 1,
            'msg' => '登录成功',
            'data' => [
                'sid' => $sid['data']['sid'] ?? '',
            ],
        ];
    }

    /**
     * 换取SID：通过 cloud 端调用微信官方 API 换取 SID
     *
     * @param string $code 小程序code
     * @param string $app_id 小程序app_id（暂留参数，实际由 cloud 端处理）
     * @return array ['code'=>1,'sid'=>'...','openid'=>'...']
     */
    protected function exchangeSid(string $code, string $app_id): array
    {
        // 通过 cloud 端中转调用微信官方 API
        return $this->cloudClient->exchangeSid($code, $this->app_type);
    }

    /**
     * 获取收款二维码
     *
     * @param string $sid SID
     * @return array ['code'=>1,'qr_url'=>'...']
     */
    public function getpayqrcode(string $sid): array
    {
        if ($this->app_type === 'book') {
            return $this->getPayQrBook($sid);
        } elseif ($this->app_type === 'recpt') {
            return $this->getPayQrRecpt($sid);
        }

        return ['code' => -1, 'msg' => '未知的app_type: ' . $this->app_type];
    }

    /**
     * 小账本获取收款码
     */
    protected function getPayQrBook(string $sid): array
    {
        $url = 'https://payapp.weixin.qq.com/qrappsl/profile/getpayqrcode?sid=' . $sid . '&v=' . $this->api_version;
        $response = $this->httpRequest($url);
        $json = json_decode($response, true);

        if (isset($json['retcode']) && $json['retcode'] == 0) {
            return [
                'code' => 1,
                'msg' => '生成收款二维码成功',
                'qr_url' => 'data:image/jpg;base64,' . ($json['data']['qrcode_content'] ?? ''),
            ];
        } elseif (isset($json['msg']) && $json['msg'] == '效验登录态失败') {
            return ['code' => -1, 'msg' => 'SID失效了哦'];
        }

        return ['code' => -1, 'msg' => $json['msg'] ?? '生成二维码失败'];
    }

    /**
     * 收款单获取收款码（需要创建收款单）
     */
    protected function getPayQrRecpt(string $sid): array
    {
        // 收款单需要指定金额创建，由 Driver 层处理
        return [
            'code' => -1,
            'msg' => '收款单需通过 createOrder 创建指定金额的收款单',
        ];
    }

    /**
     * 查询账单列表（仅小账本）
     *
     * @param string $sid SID
     * @return array ['code'=>1,'data'=>[...]]
     */
    public function detailOrder(string $sid): array
    {
        if ($this->app_type !== 'book') {
            return ['code' => -1, 'msg' => '收款单使用单笔查询，不支持列表查询'];
        }

        $url = 'https://payapp.weixin.qq.com/qrappzd/user/incomelistflexible?sid=' . $sid . '&v=' . $this->api_version;
        $post = json_encode([
            'v' => $this->api_version,
            'sid' => $sid,
            'start_time' => strtotime(date("Y-m-d H:00:00")),
            'end_time' => time(),
            'page_size' => 20,
            'sort' => 'desc',
            'is_first' => true,
            'last_create_time' => 0,
            'last_id' => '',
        ]);

        $response = $this->httpRequest($url, $post);
        $json = json_decode($response, true);

        if (isset($json['errcode']) && $json['errcode'] == 0) {
            // SID过期时API可能返回errcode=0但是msg含expired/login_buffer等关键词
            $errMsg = $json['msg'] ?? '';
            if (strpos($errMsg, 'expired') !== false || strpos($errMsg, 'login_buffer') !== false || strpos($errMsg, '效验登录态失败') !== false) {
                return ['code' => -1, 'msg' => 'SID失效了哦'];
            }
            $data = [];
            if (isset($json['data']['data_list'])) {
                foreach ($json['data']['data_list'] as $order) {
                    $money = sprintf("%.2f", substr(sprintf("%.4f", ($order['fee'] / 100)), 0, -2));
                    $data[] = [
                        'money' => $money,
                        'timestamp' => $order['timestamp'],
                        'payer_nick_name' => base64_decode($order['payer_user_name']),
                        'id_v3' => $order['id_v3'],
                        'trans_id' => $order['trans_id'],
                        'payer_remark' => $order['payer_remark'] ?? '未填支付备注',
                    ];
                }
            }

            return [
                'code' => 1,
                'msg' => '获取订单列表成功',
                'data' => $data,
            ];
        } elseif (isset($json['msg']) && $json['msg'] == '效验登录态失败') {
            return ['code' => -1, 'msg' => 'SID失效了哦'];
        }

        return ['code' => -1, 'msg' => $json['msg'] ?? '获取订单列表失败'];
    }

    /**
     * 创建收款单（仅收款单模式）
     *
     * @param string $account_id 账户ID（从sid中分离）
     * @param string $sid SID（从sid中分离）
     * @param string $amount 金额（单位：元）
     * @param string $remark 备注
     * @return array ['code'=>1,'receipt_id'=>'...','qr_url'=>'...']
     */
    public function createOrder(string $account_id, string $sid, string $amount, string $remark = ''): array
    {
        if ($this->app_type !== 'recpt') {
            return ['code' => -1, 'msg' => '仅收款单模式支持创建订单'];
        }

        // 解析复合account_id：account_id|account_type|shop_id
        $parts = explode('|', $account_id);
        $real_account_id = $parts[0] ?? '';
        $account_type = $parts[1] ?? '1';
        $shop_id = $parts[2] ?? '';
        $realSid = $sid;

        if (empty($remark)) {
            $remark = $this->randomRemark();
        }

        $receipt_mgr = $this->getReceiptMgr($account_type);
        $fee = (int) round((float) $amount * 100);
        $response = '';

        if ($account_type == 3) {
            // 收银台(receiptsjtmgr)：fee/shop_id 只在 POST body，URL 不放
            $url = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/create"
                . "?miniprogram_version=3.15.9"
                . "&account_type={$account_type}"
                . "&account_id={$real_account_id}"
                . "&sid={$realSid}";
            $post = json_encode([
                'miniprogram_version' => '3.15.9',
                'fee' => (int)$fee,
                'remark' => $remark,
                'remark_pic_urls' => '',
                'option_list' => [],
                'receipt_item_list' => [],
                'shop_id' => (int)$shop_id,
                'sid' => $realSid,
            ]);
            $response = $this->httpRequest($url, $post);
        } elseif ($account_type == 1) {
            // 门店码(receiptmdmgr)：fee 在 URL 中，不传 POST body
            $shopParam = $shop_id ? '&shop_id=' . $shop_id : '';
            $url = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/create"
                . "?miniprogram_version=3.15.9"
                . "&account_type={$account_type}{$shopParam}"
                . "&remark=" . urlencode($remark)
                . "&account_id={$real_account_id}"
                . "&sid={$realSid}"
                . "&fee={$fee}";
            $response = $this->httpRequest($url);
        } elseif ($account_type == 2) {
            // 经营码(receiptwxmgr)：fee 在 URL 中，不传 POST body
            $shopParam = $shop_id ? '&shop_id=' . $shop_id : '';
            $url = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/create"
                . "?miniprogram_version=3.15.9"
                . "&account_type={$account_type}{$shopParam}"
                . "&remark=" . urlencode($remark)
                . "&account_id={$real_account_id}"
                . "&sid={$realSid}"
                . "&fee={$fee}";
            $response = $this->httpRequest($url);
        }

        $json = json_decode($response, true);

        // 记录原始响应到PHP错误日志，方便查看
        error_log('createOrder_raw url=' . $url . ' post=' . ($post ?? '') . ' response=' . $response);

        if (isset($json['errcode']) && $json['errcode'] == 0 && isset($json['data']['receipt']['receipt_id'])) {
            // 创建成功，获取收款码
            $receiptId = $json['data']['receipt']['receipt_id'];
            $qrUrl = $this->getReceiptQrCode($receipt_mgr, $real_account_id, $account_type, $realSid, $receiptId);

            if ($qrUrl) {
                return [
                    'code' => 1,
                    'msg' => '创建收款单成功',
                    'receipt_id' => $receiptId,
                    'qr_url' => $qrUrl,
                ];
            }
            return ['code' => -1, 'msg' => '获取收款码失败'];
        } elseif ($json['msg'] ?? '' == '效验登录态失败') {
            return ['code' => -1, 'msg' => 'SID失效了哦'];
        }

        $errMsg = $json['msg'] ?? '创建收款单失败';
        if (strpos($errMsg, '还没有开通收款单账号') !== false) {
            $errMsg = '此微信没有开通收款单权限,请去微信搜索小程序【微信收款单】进行开通';
        }
        return ['code' => -1, 'msg' => $errMsg];
    }

    /**
     * 获取收款单二维码
     */
    protected function getReceiptQrCode(string $receipt_mgr, string $account_id, string $account_type, string $sid, string $receipt_id): string
    {
        $url = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/getwxacode"
            . "?miniprogram_version=3.15.9"
            . "&wxacode_path_type=1"
            . "&account_type={$account_type}"
            . "&account_id={$account_id}"
            . "&sid={$sid}"
            . "&receipt_id={$receipt_id}";

        $response = $this->httpRequest($url);
        $json = json_decode($response, true);

        if (isset($json['errcode']) && $json['errcode'] == 0 && !empty($json['data']['qrcode'])) {
            return 'data:image/jpg;base64,' . $json['data']['qrcode'];
        }
        return '';
    }

    /**
     * 查询收款单详情
     *
     * @param string $account_id 账户ID（复合格式：account_id|account_type）
     * @param string $sid SID
     * @param string $receipt_id 收款单ID
     * @return array ['code'=>1,'state'=>'success','data'=>...]
     */
    public function receiptDetail(string $account_id, string $sid, string $receipt_id): array
    {
        $parts = explode('|', $account_id);
        $real_account_id = $parts[0] ?? '';
        $account_type = $parts[1] ?? '1';

        $receipt_mgr = $this->getReceiptMgr($account_type);
        $url = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/detail"
            . "?miniprogram_version=3.15.9&page_index=1&page_size=10"
            . "&account_type={$account_type}&account_id={$real_account_id}"
            . "&sid={$sid}&receipt_id={$receipt_id}";

        $response = $this->httpRequest($url);
        $json = json_decode($response, true);

        if (isset($json['code']) && $json['code'] == 0) {
            $state = $json['data']['receipt']['state'] ?? '';
            return [
                'code' => 1,
                'msg' => '查询收款单成功',
                'state' => $state,
                'data' => $json['data'] ?? [],
            ];
        }

        return ['code' => -1, 'msg' => $json['msg'] ?? '查询收款单失败'];
    }

    /**
     * 关闭并删除收款单
     *
     * @param string $account_id 账户ID（复合格式）
     * @param string $sid SID
     * @param string $receipt_id 收款单ID
     * @return array ['code'=>1]
     */
    public function closeDel(string $account_id, string $sid, string $receipt_id): array
    {
        if ($this->app_type !== 'recpt' || empty($receipt_id)) {
            return ['code' => 1, 'msg' => '无需关闭'];
        }

        $parts = explode('|', $account_id);
        $real_account_id = $parts[0] ?? '';
        $account_type = $parts[1] ?? '1';

        $receipt_mgr = $this->getReceiptMgr($account_type);

        $receipt_id_int = (int) $receipt_id;

        if ($account_type == 3) {
            $url_close = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/close"
                . "?miniprogram_version=3.15.9"
                . "&account_type={$account_type}"
                . "&account_id={$real_account_id}"
                . "&sid={$sid}";
            $post_close = json_encode([
                'miniprogram_version' => '3.15.9',
                'receipt_id' => $receipt_id_int,
                'sid' => $sid,
            ]);
        } else {
            $url_close = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/close?miniprogram_version=3.15.9";
            $post_close = json_encode([
                'miniprogram_version' => '3.15.9',
                'account_id' => $real_account_id,
                'account_type' => (int)$account_type,
                'sid' => $sid,
                'receipt_id' => $receipt_id_int,
            ]);
        }
        $this->httpRequest($url_close, $post_close);

        if ($account_type == 3) {
            $url_del = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/del"
                . "?miniprogram_version=3.15.9"
                . "&account_type={$account_type}"
                . "&account_id={$real_account_id}"
                . "&sid={$sid}";
            $post_del = json_encode([
                'miniprogram_version' => '3.15.9',
                'receipt_id' => $receipt_id_int,
                'sid' => $sid,
            ]);
        } else {
            $url_del = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/delete?miniprogram_version=3.15.9";
            $post_del = json_encode([
                'miniprogram_version' => '3.15.9',
                'account_id' => $real_account_id,
                'account_type' => (int)$account_type,
                'sid' => $sid,
                'receipt_ids' => [$receipt_id_int],
            ]);
        }
        $this->httpRequest($url_del, $post_del);

        return ['code' => 1, 'msg' => '收款单已关闭删除'];
    }

    /**
     * 获取账号对应的小程序app_id
     */
    protected function getAppId(): string
    {
        return $this->app_type === 'book' ? self::APP_ID_BOOK : self::APP_ID_RECPT;
    }

    /**
     * 根据account_type获取收款单管理路径
     */
    protected function getReceiptMgr(string $account_type): string
    {
        $mgr_map = [
            '1' => 'receiptmdmgr',  // 经营码
            '2' => 'receiptwxmgr',   // 商业码
            '3' => 'receiptsjtmgr',  // 社交账本
        ];

        return $mgr_map[$account_type] ?? 'receiptsjtmgr';
    }

    /**
     * HTTP请求封装
     */
    protected function httpRequest(string $url, string $post = '', array $headers = []): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');

        $httpheader = [
            'Accept: */*',
            'Accept-Encoding: gzip,deflate,sdch',
            'Accept-Language: zh-CN,zh;q=0.8',
            'Connection: close',
        ];

        if (!empty($post)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            $httpheader[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($httpheader, $headers));
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; U; Android 4.4.1; zh-cn; R815T Build/JOP40D) AppleWebKit/533.1 (KHTML, like Gecko)Version/4.0 MQQBrowser/4.5 Mobile Safari/533.1');

        $ret = curl_exec($ch);
        curl_close($ch);

        return $ret ?: '';
    }

    /**
     * 生成随机备注
     */
    protected function randomRemark(): string
    {
        $remarks = ['AA购买', 'AA制购买', '合伙购买'];
        $goods = ['商品', '水果', '办公用品', '生活用品', '食品', '饮料'];
        return $remarks[array_rand($remarks)] . $goods[array_rand($goods)];
    }
}
