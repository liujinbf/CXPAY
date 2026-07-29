<?php

namespace app\common\library\payment\plugins\wxpay_recpt_afk_pc;

use app\common\library\payment\AbstractPaymentChannel;
use app\common\library\payment\plugins\wxpay_recpt_afk_pc\lib\WeChat_Book_Pc;
use app\common\library\payment\plugins\wxpay_recpt_afk_pc\lib\WeChat_Recpt_Pc;
use app\common\model\PayOrder;

/**
 * PC挂机-收款单/小账本 驱动（wxpay_recpt_afk_pc）
 *   - recpt_sid → 收款单模式（免输金额，按 receipt_id 匹配）
 *   - book_sid  → 小账本模式（个人动态码，按金额+30s 时间窗口匹配）
 *
 * 移植自旧 protected/payplug/wxpay_recpt_afk_pc/config.php + wxpay_book_afk_pc/config.php。
 * monitorMode=server：服务端主动轮询。
 *
 * recpt 原理：PC 端挂微信保活收款单 sid；下单时按订单金额创建微信收款单(receipt_id)出免输码，
 *   服务端主动轮询该 receipt 详情，state=success 即到账 → 按 receipt_id 匹配结算并删单。
 * book 原理：PC 端挂微信保活 book_sid，服务端主动轮询小账本账单列表(qrappzd/home/detail)
 *   检测最近 30s 到账 → 按金额匹配结算。出码用小账本个人动态收款码。
 */
class Driver extends AbstractPaymentChannel
{
    protected string $cType = 'wxpay_recpt_afk_pc';

    /** 判断是否为小账本模式（book_sid 存在且 recpt_sid 不存在） */
    protected function isBookMode(array $config): bool
    {
        return isset($config['book_sid']) && !isset($config['recpt_sid']);
    }

    public function monitorMode(): string
    {
        return 'server';
    }

    public function config(): array
    {
        return [
            'version'       => '1.2.0',
            'name'          => 'PC挂机-[小账本/收款单]',
            'author'        => '小乐',
            'link'          => 'http://xiaole.ink',
            'type'          => 'wxpay',
            'c_type'        => 'wxpay_recpt_afk_pc',
            'switch_qr_url' => false,
            'getqrlogin'    => 'wxpay_ipad',
            'inputs'        => [
                'wxid' => [
                    'name' => '微信ID',
                    'type' => 'input',
                    'note' => '微信账号ID',
                ],
            ],
            'software' => [
                'name' => 'Win PC挂机监控软件',
                'note' => '本通道无需手动新增：点击下载专属安装包（已按您的账号内置对接信息），运行后自动创建/更新本通道。',
            ],
            'note' => '<span style="color:red" name="check_status" id="check_status">收款单模式需微信开通经营码或者商业码；小账本模式无需开通任何资质，有微信就能用</span>',
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        // 两种模式任选其一即可，确保必要字段存在
        if ($this->isBookMode($config)) {
            $config['qr_url'] = $config['qr_url'] ?? '';
        }
        return $config;
    }

    // ────────── 出码 ──────────

    /**
     * 出码：根据模式走收款单创建或小账本动态码。
     */
    public function getPayQr(array $order, array $config): array
    {
        $config['config'] = false;

        if ($this->isBookMode($config)) {
            return $this->getPayQrBook($order, $config);
        }
        return $this->getPayQrRecpt($order, $config);
    }

    protected function getPayQrBook(array $order, array $config): array
    {
        $qr = $config['qr_url'] ?? '';
        $WeChat_Book_Pc = new WeChat_Book_Pc();
        $getpayqrcode = $WeChat_Book_Pc->getpayqrcode($config['book_sid'] ?? '');
        if (($getpayqrcode['code'] ?? 0) == 1) {
            $qr = $getpayqrcode['qr_url'];
        }
        if (!$qr) {
            return $this->fail('通道未配置收款码');
        }
        return $this->ok(['qr' => $qr, 'type' => 'qr', 'config' => false]);
    }

    protected function getPayQrRecpt(array $order, array $config): array
    {
        $config['config'] = false;

        [$account_id, $sid] = $this->splitRecpt($config['recpt_sid'] ?? '');
        $remark = $this->generateShopName() . substr((string) ($order['trade_no'] ?? ''), -5);

        $money = $order['money'] ?? ($order['price'] ?? '0.00'); // 免输金额：用 money，不递增
        $WeChat_Recpt_Pc = new WeChat_Recpt_Pc();
        $Create = $WeChat_Recpt_Pc->createOrder($account_id, $sid, $money, $remark);
        if (($Create['code'] ?? 0) == 1) {
            $receiptId = $Create['receipt_id'];
            return $this->ok(['qr' => $Create['qr_url'], 'type' => 'qr', 'config' => $receiptId]);
        }
        // 失败回退通道内已存 qr_url（自定义上传码）
        $qr = $config['qr_url'] ?? '';
        if (!$qr) {
            return $this->fail($Create['msg'] ?? '创建收款单失败');
        }
        return $this->ok(['qr' => $qr, 'type' => 'qr', 'config' => false]);
    }

    // ────────── 心跳 ──────────

    /**
     * 协议心跳：校验 sid 是否在线。
     */
    public function heartbeatCallback(array $channelRow, array $config): array
    {
        if ($this->isBookMode($config)) {
            return $this->heartbeatBook($channelRow, $config);
        }
        return $this->heartbeatRecpt($channelRow, $config);
    }

    protected function heartbeatBook(array $channelRow, array $config): array
    {
        $WeChat_Book_Pc = new WeChat_Book_Pc();
        $detailOrder = $WeChat_Book_Pc->detailOrder($config['book_sid'] ?? '');
        if (($detailOrder['code'] ?? 0) == -1) {
            if (($config['getcacheinfo_status'] ?? 0) == 1 and ($config['getcacheinfo_time'] ?? 0) < time()) {
                $config['getcacheinfo_status'] = 0;
                $config['status_msg'] = $detailOrder['msg'] ?? '';
            } elseif (($config['getcacheinfo_status'] ?? 0) == 0) {
                $config['getcacheinfo_time'] = time() + (60 * 3);
                $config['getcacheinfo_status'] = 1;
            }
        } else {
            $config['getcacheinfo_status'] = 0;
        }
        return $config;
    }

    protected function heartbeatRecpt(array $channelRow, array $config): array
    {
        [$account_id, $sid] = $this->splitRecpt($config['recpt_sid'] ?? '');
        $WeChat_Recpt_Pc = new WeChat_Recpt_Pc();
        $receiptList = $WeChat_Recpt_Pc->receiptList($account_id, $sid);
        if (($receiptList['code'] ?? 0) == -1) {
            if (($config['getcacheinfo_status'] ?? 0) == 1 and ($config['getcacheinfo_time'] ?? 0) < time()) {
                $config['getcacheinfo_status'] = 0;
                $config['status'] = false;
                $config['status_msg'] = $receiptList['msg'] ?? '';
            } elseif (($config['getcacheinfo_status'] ?? 0) == 0) {
                $config['getcacheinfo_time'] = time() + (60 * 3);
                $config['getcacheinfo_status'] = 1;
            }
        } else {
            $config['getcacheinfo_status'] = 0;
        }
        return $config;
    }

    // ────────── 监控 URL ──────────

    /**
     * 获取监控地址：根据模式组装查询 URL。
     */
    public function getPayCurl(array $channelRow, array $config, array $order = []): array
    {
        if ($this->isBookMode($config)) {
            return $this->getPayCurlBook($channelRow, $config, $order);
        }
        return $this->getPayCurlRecpt($channelRow, $config, $order);
    }

    protected function getPayCurlBook(array $channelRow, array $config, array $order = []): array
    {
        $url_detail = 'https://payapp.weixin.qq.com/qrappzd/home/detail?v=7.2.3&sid=' . ($config['book_sid'] ?? '');
        return [
            'url'    => $url_detail,
            'post'   => '',
            'cookie' => '',
            'ua'     => '',
        ];
    }

    protected function getPayCurlRecpt(array $channelRow, array $config, array $order = []): array
    {
        $Recpt = explode("|", $config['recpt_sid'] ?? '');
        if (count($Recpt) > 3) {
            $sid = $Recpt[3];
        } else {
            $sid = $Recpt[2] ?? '';
        }
        $account_id = $Recpt[0] ?? '';
        $account_type = $Recpt[1] ?? '';
        $receiptId = $order['config'] ?? '';
        $url_detail = '';
        if ($account_type == 1) {
            $receipt = 'receiptmdmgr';
            $url_detail = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/detailv3?miniprogram_version=3.15.9&page_index=1&page_size=10&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $sid . '&receipt_id=' . $receiptId;
        } elseif ($account_type == 2) {
            $receipt = 'receiptwxmgr';
            $url_detail = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/detail?miniprogram_version=3.15.9&page_index=1&page_size=10&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $sid . '&receipt_id=' . $receiptId;
        } elseif ($account_type == 3) {
            $receipt = 'receiptsjtmgr';
            $url_detail = 'https://payapp.wechatpay.cn/' . $receipt . '/receipt/detail?miniprogram_version=3.15.9&page_index=1&page_size=10&account_type=' . $account_type . '&account_id=' . $account_id . '&sid=' . $sid . '&receipt_id=' . $receiptId;
        }
        if ($receiptId) {
            return [
                'url'    => $url_detail,
                'post'   => '',
                'cookie' => '',
                'ua'     => '',
            ];
        }
        return [];
    }

    // ────────── 监控回调解析 ──────────

    /**
     * 解析详情：根据模式解析 receipt 详情或账单列表。
     */
    public function getPayCurlCallback(array $channelRow, array $config, $curlData): array
    {
        if ($this->isBookMode($config)) {
            return $this->getPayCurlCallbackBook($channelRow, $config, $curlData);
        }
        return $this->getPayCurlCallbackRecpt($channelRow, $config, $curlData);
    }

    protected function getPayCurlCallbackBook(array $channelRow, array $config, $curlData): array
    {
        $config['bill'] = false;
        $json = json_decode((string) $curlData, true);
        if (isset($json['data']['bill_list']) and is_array($json['data']['bill_list'])) {
            foreach ($json['data']['bill_list'] as $order) {
                if ($order['timestamp'] > (time() - 30)) {
                    $money = sprintf("%.2f", substr(sprintf("%.4f", ($order['fee'] / 100)), 0, -2));
                    if (!is_array($config['bill'])) $config['bill'] = [];
                    $config['bill'][] = array(
                        "price"  => $money,
                        "config" => $order['trans_id'],
                    );
                }
            }
        }
        return $config;
    }

    protected function getPayCurlCallbackRecpt(array $channelRow, array $config, $curlData): array
    {
        $config['bill'] = false;
        $json = json_decode((string) $curlData, true);
        if (isset($json['data']['receipt']['order']) and is_array($json['data']['receipt']['order'])) {
            foreach ($json['data']['receipt']['order'] as $order) {
                $money = sprintf("%.2f", substr(sprintf("%.4f", ($order['fee'] / 100)), 0, -2));
                if ($json['data']['receipt']['state'] == 'success' || (isset($order['state']) && $order['state'] == 'success')) {
                    if (!is_array($config['bill'])) $config['bill'] = [];
                    $config['bill'][] = array(
                        "price"  => $money,
                        "config" => $json['data']['receipt']['receipt_id'],
                    );
                    [$account_id, $sid] = $this->splitRecpt($config['recpt_sid'] ?? '');
                    try {
                        $WeChat_Recpt_Pc = new WeChat_Recpt_Pc();
                        $WeChat_Recpt_Pc->closeDel($account_id, $sid, $json['data']['receipt']['receipt_id']);
                    } catch (\Throwable $e) {
                        // 关单失败不影响到账入库
                    }
                }
            }
        }
        return $config;
    }

    // ────────── 服务端监控 ──────────

    /**
     * 服务端监控一次：根据模式巡查到账。
     */
    public function monitor(array $channelRow, array $config): array
    {
        if ($this->isBookMode($config)) {
            return $this->monitorBook($channelRow, $config);
        }
        return $this->monitorRecpt($channelRow, $config);
    }

    protected function monitorBook(array $channelRow, array $config): array
    {
        $req = $this->getPayCurl($channelRow, $config, []);
        $data = '';
        if (is_array($req) && !empty($req['url'])) {
            $data = $this->httpRequestBook($req);
        }
        $config = $this->getPayCurlCallback($channelRow, $config, $data);

        // sid 有效性 → 在线状态
        $WeChat_Book_Pc = new WeChat_Book_Pc();
        $detail = $WeChat_Book_Pc->detailOrder($config['book_sid'] ?? '');
        $config['status'] = (($detail['code'] ?? 0) == 1) ? '1' : false;

        return $config;
    }

    protected function monitorRecpt(array $channelRow, array $config): array
    {
        $bills = [];
        $channelId = $channelRow['id'] ?? 0;
        $orders = [];
        try {
            $orders = PayOrder::where('channel_id', $channelId)
                ->where('status', 0)
                ->whereNotNull('config')
                ->limit(50)
                ->select()->toArray();
        } catch (\Throwable $e) {
            $orders = [];
        }

        foreach ($orders as $o) {
            $receiptId = $o['config'] ?? '';
            if (!$receiptId) continue;
            $req = $this->getPayCurl($channelRow, $config, ['config' => $receiptId]);
            if (!is_array($req) || empty($req['url'])) continue;
            $data = $this->httpRequestRecpt($req);
            $r = $this->getPayCurlCallback($channelRow, $config, $data);
            if (is_array($r['bill'] ?? false)) {
                foreach ($r['bill'] as $b) $bills[] = $b;
            }
        }
        $config['bill'] = $bills ?: false;

        // sid 有效性 → 在线状态
        [$account_id, $sid] = $this->splitRecpt($config['recpt_sid'] ?? '');
        $WeChat_Recpt_Pc = new WeChat_Recpt_Pc();
        $receiptList = $WeChat_Recpt_Pc->receiptList($account_id, $sid);
        $config['status'] = (($receiptList['code'] ?? 0) == 1) ? '1' : false;

        return $config;
    }

    // ────────── 关单 ──────────

    public function tradeClose(array $order, array $config): array
    {
        if ($this->isBookMode($config)) {
            // 旧 WeChat_Book_Pc 无 closeDel → no-op
            return ['code' => 1, 'msg' => ''];
        }

        [$account_id, $sid] = $this->splitRecpt($config['recpt_sid'] ?? '');
        if ($sid) {
            $WeChat_Recpt_Pc = new WeChat_Recpt_Pc();
            $WeChat_Recpt_Pc->closeDel($account_id, $sid, $order['config'] ?? '');
        }
        return ['code' => 1, 'msg' => ''];
    }

    // ────────── 工具方法 ──────────

    /**
     * 拆分 recpt_sid（1:1 移植旧 explode 逻辑）→ [account_id, sid]
     */
    protected function splitRecpt(string $recptSid): array
    {
        $Recpt = explode("|", $recptSid);
        if (count($Recpt) > 3) {
            $account_id = $Recpt[0] . '|' . $Recpt[1] . '|' . $Recpt[2];
            $sid = $Recpt[3];
        } else {
            $account_id = ($Recpt[0] ?? '') . '|' . ($Recpt[1] ?? '');
            $sid = $Recpt[2] ?? '';
        }
        return [$account_id, $sid];
    }

    /**
     * 随机店名，仅用于收款单 remark，无协议意义。
     */
    protected function generateShopName(): string
    {
        $names = ['优选便利店', '百货优选', '生活优选', '好邻居', '优品汇', '惠民超市'];
        return $names[array_rand($names)];
    }

    protected function httpRequestRecpt(array $req): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $req['url']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept:*/*",
            "Accept-Encoding:gzip,deflate,sdch",
            "Accept-Language:zh-CN,zh;q=0.8",
            "Connection:close",
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if (!empty($req['post'])) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['post']);
        }
        if (!empty($req['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $req['cookie']);
        }
        curl_setopt($ch, CURLOPT_USERAGENT, 'Host: payapp.weixin.qq.com\r\User-Agent: Mozilla/5.0 (Linux; Android 12; NEM-AL10 Build/HONORNEM-AL10; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/57.0.2987.132 MQQBrowser/6.2 TBS/043906 Mobile Safari/537.36 MicroMessenger/6.6.1.1220(0x26060133) NetType/WIFI Language/zh_CN/6762\r\nContent-Type: application/json\r\n');
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return (string) $ret;
    }

    protected function httpRequestBook(array $req): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $req['url']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept:*/*",
            "Accept-Encoding:gzip,deflate,sdch",
            "Accept-Language:zh-CN,zh;q=0.8",
            "Connection:close",
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if (!empty($req['post'])) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['post']);
        }
        if (!empty($req['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $req['cookie']);
        }
        if (!empty($req['ua'])) {
            curl_setopt($ch, CURLOPT_USERAGENT, $req['ua']);
        } else {
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; U; Android 4.4.1; zh-cn; R815T Build/JOP40D) AppleWebKit/533.1 (KHTML, like Gecko)Version/4.0 MQQBrowser/4.5 Mobile Safari/533.1');
        }
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return (string) $ret;
    }
}
